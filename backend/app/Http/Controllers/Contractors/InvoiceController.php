<?php

namespace App\Http\Controllers\Contractors;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\ContractorContract;
use App\Models\ContractorInvoice;
use App\Support\Activity;
use App\Support\ContractorFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function index(Contractor $contractor): JsonResponse
    {
        return response()->json(['data' => ContractorInvoice::query()->with('contract:id,title')->where('contractor_id', $contractor->id)
            ->latest('issue_date')->get()->map(fn (ContractorInvoice $invoice): array => self::present($invoice))]);
    }

    /**
     * Every contractor invoice in the company, filterable by status (including the worked-out "overdue").
     */
    public function all(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in([...ContractorInvoice::STATUSES, 'overdue', 'outstanding'])]]);
        $status = $request->string('status')->toString();

        $invoices = ContractorInvoice::query()->with(['contract:id,title', 'contractor:id,name'])
            ->when($status === 'overdue', fn ($query) => $query->overdue())
            ->when($status === 'outstanding', fn ($query) => $query->whereIn('status', ContractorInvoice::OUTSTANDING))
            ->when(in_array($status, ContractorInvoice::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->orderByRaw('due_date is null')->orderBy('due_date')->latest('issue_date')
            ->limit(500)->get();

        $counts = ContractorInvoice::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'data' => $invoices->map(fn (ContractorInvoice $invoice): array => [...self::present($invoice), 'contractor' => $invoice->contractor->only(['id', 'name'])]),
            'counts' => [...array_fill_keys(ContractorInvoice::STATUSES, 0), ...$counts->map(fn ($total): int => (int) $total)->all(), 'overdue' => ContractorInvoice::query()->overdue()->count()],
        ]);
    }

    public function move(Request $request, Contractor $contractor, ContractorInvoice $invoice): JsonResponse
    {
        abort_unless($invoice->contractor_id === $contractor->id, 404);
        $allowed = ContractorInvoice::MANUAL_MOVES[$invoice->status] ?? [];
        $data = $request->validate(['status' => ['required', Rule::in($allowed)]], ['status.in' => $allowed === []
            ? 'This invoice’s status cannot be changed by hand.'
            : 'From '.str_replace('_', ' ', $invoice->status).' an invoice can move to: '.implode(', ', array_map(fn (string $s): string => str_replace('_', ' ', $s), $allowed)).'.']);

        $from = $invoice->status;
        $invoice->forceFill(['status' => $data['status']])->save();

        Activity::record('contractor.invoice_status_changed', "Invoice {$invoice->reference} moved from ".str_replace('_', ' ', $from).' to '.str_replace('_', ' ', $invoice->status), $invoice, ['from' => $from, 'to' => $invoice->status]);

        return response()->json(self::present($invoice->fresh('contract')));
    }

    public function store(Request $request, Contractor $contractor): JsonResponse
    {
        $data = $this->validated($request, $contractor);
        $invoice = ContractorInvoice::query()->create([
            ...collect($data)->except('attachment')->all(), 'contractor_id' => $contractor->id, 'created_by' => $request->user()->id,
        ]);
        $this->attach($request, $invoice, $contractor);

        Activity::record('contractor.invoice_entered', "Invoice {$invoice->reference} from {$contractor->name} entered", $invoice);

        return response()->json(self::present($invoice->fresh('contract')), 201);
    }

    public function update(Request $request, Contractor $contractor, ContractorInvoice $invoice): JsonResponse
    {
        abort_unless($invoice->contractor_id === $contractor->id, 404);
        abort_unless(in_array($invoice->status, ContractorInvoice::EDITABLE, true), 422, 'An invoice cannot be edited once it is '.str_replace('_', ' ', $invoice->status).'.');
        $invoice->fill(collect($this->validated($request, $contractor, $invoice))->except(['attachment', 'status'])->all())->save();
        $this->attach($request, $invoice, $contractor);

        Activity::record('contractor.invoice_updated', "Invoice {$invoice->reference} updated", $invoice, Activity::changes($invoice));

        return response()->json(self::present($invoice->fresh('contract')));
    }

    public function download(Contractor $contractor, ContractorInvoice $invoice): StreamedResponse
    {
        abort_unless($invoice->contractor_id === $contractor->id && $invoice->attachment !== null, 404);

        Activity::record('contractor.invoice_downloaded', "Invoice {$invoice->reference} attachment downloaded", $invoice);

        return ContractorFiles::download($invoice->attachment);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ContractorInvoice $invoice): array
    {
        return [
            ...$invoice->only(['id', 'contractor_id', 'contract_id', 'reference', 'currency', 'amount', 'description', 'status']),
            'contract' => $invoice->contract?->only(['id', 'title']),
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'has_attachment' => $invoice->attachment_document_id !== null,
            'display_status' => $invoice->displayStatus(),
            'is_overdue' => $invoice->isOverdue(),
            'can_edit' => in_array($invoice->status, ContractorInvoice::EDITABLE, true),
            'moves' => ContractorInvoice::MANUAL_MOVES[$invoice->status] ?? [],
        ];
    }

    private function attach(Request $request, ContractorInvoice $invoice, Contractor $contractor): void
    {
        if ($file = $request->file('attachment')) {
            $document = ContractorFiles::store($file, $contractor, 'invoice', "Invoice {$invoice->reference}", $request->user());
            $invoice->forceFill(['attachment_document_id' => $document->id])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Contractor $contractor, ?ContractorInvoice $invoice = null): array
    {
        if ($request->filled('currency')) {
            $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);
        }

        return $request->validate([
            'contract_id' => ['nullable', Rule::exists(ContractorContract::class, 'id')->where('contractor_id', $contractor->id)],
            'reference' => ['required', 'string', 'max:100', Rule::unique(ContractorInvoice::class)->where('contractor_id', $contractor->id)->ignore($invoice?->id)],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency' => ['required', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'description' => ['nullable', 'string', 'max:5000'],
            'attachment' => ContractorFiles::RULES,
            'status' => ['nullable', Rule::in(['draft', 'received', 'pending_review'])],
        ], [
            'reference.unique' => 'This contractor already has an invoice with this reference.',
            'due_date.after_or_equal' => 'The due date cannot be before the invoice date.',
            'contract_id.exists' => 'Choose one of this contractor’s contracts.',
        ]);
    }
}
