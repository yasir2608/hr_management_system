<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * The company's own M.Y HRMS subscription, as its administrators see it: the agreement in force, any upcoming one,
 * and the invoices raised to the company. Read only, always limited to the current company, and without the
 * internal notes operations keep (agreement notes, invoice notes, void reasons, payment notes, who recorded what).
 * Void invoices are left out.
 */
class CompanyBillingController extends Controller
{
    public function show(CurrentCompany $currentCompany): JsonResponse
    {
        $company = $currentCompany->require();
        $today = CarbonImmutable::today();

        $subscriptions = CompanySubscription::query()->where('company_id', $company->id)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today->toDateString()))
            ->orderBy('starts_on')->get();
        $current = $subscriptions->first(fn (CompanySubscription $subscription): bool => ! $subscription->starts_on->gt($today));
        $upcoming = $subscriptions->first(fn (CompanySubscription $subscription): bool => $subscription->starts_on->gt($today));

        $invoices = BillingInvoice::query()->where('company_id', $company->id)->where('status', '!=', BillingInvoice::VOID)
            ->orderByDesc('issued_on')->orderByDesc('reference')->get();
        $open = $invoices->where('status', BillingInvoice::ISSUED);

        return response()->json([
            'company' => $company->name,
            'current' => $current ? $this->agreement($current, $today) : null,
            'upcoming' => $upcoming ? $this->agreement($upcoming, $today) : null,
            'employees' => Employee::query()->where('current_status', '!=', Employee::STATUS_ENDED)->count(),
            'invoices' => $invoices->map(fn (BillingInvoice $invoice): array => [
                'id' => $invoice->id,
                'reference' => $invoice->reference,
                'period_start' => $invoice->period_start?->toDateString(),
                'period_end' => $invoice->period_end?->toDateString(),
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'issued_on' => $invoice->issued_on->toDateString(),
                'due_on' => $invoice->due_on->toDateString(),
                'status' => $invoice->displayStatus($today),
                'paid_on' => $invoice->paid_on?->toDateString(),
                'payment_method' => BillingInvoice::PAYMENT_METHODS[$invoice->payment_method] ?? null,
                'payment_reference' => $invoice->payment_reference,
            ])->values(),
            'totals' => [
                'outstanding' => $open->groupBy('currency')->map(fn ($group): string => number_format((float) $group->sum('amount'), 2, '.', '')),
                'overdue_count' => $open->filter(fn (BillingInvoice $invoice): bool => $invoice->due_on->lt($today))->count(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function agreement(CompanySubscription $subscription, CarbonImmutable $today): array
    {
        return [
            ...$subscription->only(['plan_name', 'price_basis', 'price', 'currency', 'billing_period', 'employee_limit']),
            'state' => $subscription->stateOn($today),
            'starts_on' => $subscription->starts_on->toDateString(),
            'ends_on' => $subscription->ends_on?->toDateString(),
            'next_period_starts' => $subscription->nextPeriodStart($today)?->toDateString(),
        ];
    }
}
