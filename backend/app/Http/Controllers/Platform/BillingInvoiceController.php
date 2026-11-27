<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\PlatformActivity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manual invoices M.Y HRMS raises to customers, recorded in the staff console. Any staff member can view;
 * operations staff create, correct and void them. Voiding keeps the record. Every change is logged.
 */
class BillingInvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['issued', 'overdue', 'void'])], 'company_id' => ['nullable', 'ulid']]);
        $today = CarbonImmutable::today();

        $invoices = BillingInvoice::query()->with(['company:id,name', 'creator:id,name'])
            ->when($data['company_id'] ?? null, fn ($query, string $id) => $query->where('company_id', $id))
            ->when(($data['status'] ?? null) === 'overdue', fn ($query) => $query->where('status', BillingInvoice::ISSUED)->where('due_on', '<', $today->toDateString()))
            ->when(in_array($data['status'] ?? null, ['issued', 'void'], true), fn ($query) => $query->where('status', $data['status']))
            ->orderByDesc('issued_on')->orderByDesc('reference')->limit(500)->get();

        $open = BillingInvoice::query()->where('status', BillingInvoice::ISSUED)->get(['amount', 'currency', 'due_on']);

        return response()->json([
            'data' => $invoices->map(fn (BillingInvoice $invoice): array => $this->present($invoice, $today))->values(),
            'totals' => [
                'outstanding' => $open->groupBy('currency')->map(fn ($group): string => number_format((float) $group->sum('amount'), 2, '.', '')),
                'overdue_count' => $open->filter(fn (BillingInvoice $invoice): bool => $invoice->due_on->lt($today))->count(),
            ],
            'can_manage' => auth()->user()->platform_role === 'ops',
        ]);
    }

    public function forCompany(Company $company): JsonResponse
    {
        $today = CarbonImmutable::today();

        return response()->json([
            'data' => BillingInvoice::query()->with('creator:id,name')->where('company_id', $company->id)->orderByDesc('issued_on')->orderByDesc('reference')->get()
                ->map(fn (BillingInvoice $invoice): array => $this->present($invoice, $today))->values(),
            'suggestion' => $this->suggestion($company, $today),
            'can_manage' => auth()->user()->platform_role === 'ops',
        ]);
    }

    public function store(Request $request, Company $company): JsonResponse
    {
        $data = $this->validated($request, $company);
        $invoice = BillingInvoice::query()->create([...$data, 'company_id' => $company->id, 'status' => BillingInvoice::ISSUED, 'created_by' => $request->user()->id]);

        PlatformActivity::record('billing.invoice_created', "Invoice {$invoice->reference} for {$company->name}: {$invoice->currency} {$invoice->amount}, due {$invoice->due_on->toDateString()}", $company->id, properties: [
            'invoice_id' => $invoice->id, 'reference' => $invoice->reference, 'amount' => $invoice->amount, 'currency' => $invoice->currency,
        ]);

        return response()->json($this->present($invoice->load('creator:id,name'), CarbonImmutable::today()), 201);
    }

    public function update(Request $request, Company $company, BillingInvoice $invoice): JsonResponse
    {
        $this->guard($company, $invoice);
        $invoice->fill($this->validated($request, $company, $invoice))->save();

        PlatformActivity::record('billing.invoice_updated', "Invoice {$invoice->reference} for {$company->name} corrected", $company->id, properties: [
            'invoice_id' => $invoice->id, 'changes' => collect($invoice->getChanges())->except('updated_at')->all(),
        ]);

        return response()->json($this->present($invoice->load('creator:id,name'), CarbonImmutable::today()));
    }

    public function void(Request $request, Company $company, BillingInvoice $invoice): JsonResponse
    {
        $this->guard($company, $invoice);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say why the invoice is void.']);

        $invoice->forceFill(['status' => BillingInvoice::VOID, 'void_reason' => $data['reason'], 'voided_at' => now()])->save();
        PlatformActivity::record('billing.invoice_voided', "Invoice {$invoice->reference} for {$company->name} voided", $company->id, properties: [
            'invoice_id' => $invoice->id, 'reason' => $data['reason'],
        ]);

        return response()->json($this->present($invoice->load('creator:id,name'), CarbonImmutable::today()));
    }

    /**
     * Only issued invoices of this company can be corrected or voided.
     */
    private function guard(Company $company, BillingInvoice $invoice): void
    {
        abort_unless($invoice->company_id === $company->id, 404);
        abort_unless($invoice->status === BillingInvoice::ISSUED, 422, 'Only issued invoices can be changed.');
    }

    /**
     * The next invoice to raise for the agreement in force: the first billing period not yet invoiced, priced from
     * the agreement (per-employee plans use the current headcount). NULL when there is no agreement.
     *
     * @return array<string, mixed>|null
     */
    private function suggestion(Company $company, CarbonImmutable $today): ?array
    {
        $subscription = CompanySubscription::inForce(CompanySubscription::query()->where('company_id', $company->id), $today)->first();
        if ($subscription === null) {
            return null;
        }

        $months = CompanySubscription::PERIOD_MONTHS[$subscription->billing_period] ?? 1;
        $start = CarbonImmutable::parse($subscription->starts_on);
        $invoiced = BillingInvoice::query()->where('company_subscription_id', $subscription->id)->where('status', '!=', BillingInvoice::VOID)
            ->pluck('period_start')->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())->all();

        $period = 0;
        do {
            $periodStart = $start->addMonthsNoOverflow($months * $period);
            $period++;
        } while (in_array($periodStart->toDateString(), $invoiced, true) && $period < 120);

        if ($subscription->ends_on !== null && $periodStart->gt($subscription->ends_on)) {
            return null;
        }
        $periodEnd = $start->addMonthsNoOverflow($months * $period)->subDay();
        if ($subscription->ends_on !== null && $periodEnd->gt($subscription->ends_on)) {
            $periodEnd = CarbonImmutable::parse($subscription->ends_on);
        }

        $employees = Employee::query()->withoutGlobalScopes()->where('company_id', $company->id)->whereNull('deleted_at')->where('current_status', '!=', Employee::STATUS_ENDED)->count();
        $quantity = $subscription->price_basis === 'per_employee' ? $employees : 1;

        return [
            'company_subscription_id' => $subscription->id,
            'plan_name' => $subscription->plan_name,
            'reference' => BillingInvoice::nextReference($today->year),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'amount' => number_format((float) $subscription->price * $quantity, 2, '.', ''),
            'currency' => $subscription->currency,
            'issued_on' => $today->toDateString(),
            'due_on' => $today->addDays(14)->toDateString(),
            'basis' => $subscription->price_basis === 'per_employee'
                ? "{$employees} employee(s) × {$subscription->currency} {$subscription->price}"
                : "Flat fee {$subscription->currency} {$subscription->price}",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Company $company, ?BillingInvoice $invoice = null): array
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:50', Rule::unique(BillingInvoice::class)->ignore($invoice?->id)],
            'company_subscription_id' => ['nullable', 'ulid', Rule::exists(CompanySubscription::class, 'id')->where('company_id', $company->id)],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3'],
            'issued_on' => ['required', 'date'],
            'due_on' => ['required', 'date', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'reference.unique' => 'Another invoice already uses this reference.',
            'due_on.after_or_equal' => 'The due date cannot be before the invoice date.',
            'company_subscription_id.exists' => 'Choose one of this company’s agreements.',
        ]);

        return [...$data, 'currency' => strtoupper($data['currency'])];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BillingInvoice $invoice, CarbonImmutable $today): array
    {
        return [
            ...$invoice->only(['id', 'company_id', 'company_subscription_id', 'reference', 'amount', 'currency', 'notes', 'status', 'void_reason']),
            'company' => $invoice->relationLoaded('company') ? $invoice->company?->name : null,
            'period_start' => $invoice->period_start?->toDateString(),
            'period_end' => $invoice->period_end?->toDateString(),
            'issued_on' => $invoice->issued_on->toDateString(),
            'due_on' => $invoice->due_on->toDateString(),
            'display_status' => $invoice->displayStatus($today),
            'created_by' => $invoice->creator?->name,
            'voided_at' => $invoice->voided_at?->toIso8601String(),
        ];
    }
}
