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
        $invoice->fill(collect($this->validated($request, $contractor, $invoice))->except('attachment')->all())->save();
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
        ], [
            'reference.unique' => 'This contractor already has an invoice with this reference.',
            'due_date.after_or_equal' => 'The due date cannot be before the invoice date.',
            'contract_id.exists' => 'Choose one of this contractor’s contracts.',
        ]);
    }
}
