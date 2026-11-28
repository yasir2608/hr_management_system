<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\BillingPlan;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\PlatformActivity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Manual subscription billing in the staff console: the plans M.Y HRMS offers and each company's agreed plan and
 * billing period over time. Any staff member can view; operations staff record changes. Every change is logged in
 * the platform activity. This is M.Y HRMS's own billing, separate from company payroll and contractor invoices.
 */
class BillingController extends Controller
{
    public function overview(): JsonResponse
    {
        $today = CarbonImmutable::today();
        $current = CompanySubscription::inForce(CompanySubscription::query(), $today)->get()->keyBy('company_id');
        $upcoming = CompanySubscription::query()->where('starts_on', '>', $today->toDateString())->orderBy('starts_on')->get()->groupBy('company_id');
        $employees = Employee::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('current_status', '!=', Employee::STATUS_ENDED)
            ->groupBy('company_id')->selectRaw('company_id, count(*) as total')->pluck('total', 'company_id');

        $companies = Company::query()->orderBy('name')->get(['id', 'name', 'status'])->map(function (Company $company) use ($current, $upcoming, $employees, $today): array {
            $subscription = $current->get($company->id);

            return [
                'id' => $company->id,
                'name' => $company->name,
                'status' => $company->status,
                'employees' => (int) ($employees[$company->id] ?? 0),
                'subscription' => $subscription ? $this->present($subscription, $today) : null,
                'upcoming' => ($next = $upcoming->get($company->id)?->first()) ? $this->present($next, $today) : null,
            ];
        });

        return response()->json([
            'companies' => $companies,
            'totals' => [
                'companies' => $companies->count(),
                'with_plan' => $companies->whereNotNull('subscription')->count(),
                'without_plan' => $companies->whereNull('subscription')->count(),
                'on_trial' => $companies->filter(fn (array $row): bool => ($row['subscription']['state'] ?? null) === 'trial')->count(),
            ],
            'can_manage' => auth()->user()->canManageBilling(),
        ]);
    }

    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => BillingPlan::query()->withCount(['subscriptions as companies' => fn ($query) => CompanySubscription::inForce($query, CarbonImmutable::today())])
                ->orderByDesc('is_active')->orderBy('name')->get(),
            'bases' => BillingPlan::BASES,
            'periods' => BillingPlan::PERIODS,
        ]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $plan = BillingPlan::query()->create($this->validatedPlan($request));
        PlatformActivity::record('billing.plan_created', "Billing plan “{$plan->name}” created", properties: $plan->only(['price_basis', 'price', 'currency', 'billing_period']));

        return response()->json($plan, 201);
    }

    public function updatePlan(Request $request, BillingPlan $plan): JsonResponse
    {
        $plan->fill($this->validatedPlan($request, $plan))->save();
        PlatformActivity::record('billing.plan_updated', "Billing plan “{$plan->name}” updated", properties: ['changes' => $plan->getChanges()]);

        return response()->json($plan);
    }

    public function subscriptions(Company $company): JsonResponse
    {
        $today = CarbonImmutable::today();
        $history = CompanySubscription::query()->with('recorder:id,name')->where('company_id', $company->id)->orderByDesc('starts_on')->get();

        return response()->json([
            'company' => $company->only(['id', 'name', 'status']),
            'current' => ($current = CompanySubscription::inForce(CompanySubscription::query()->where('company_id', $company->id), $today)->first()) ? $this->present($current, $today) : null,
            'history' => $history->map(fn (CompanySubscription $subscription): array => $this->present($subscription, $today))->values(),
            'plans' => BillingPlan::query()->where('is_active', true)->orderBy('name')->get(),
            'can_manage' => auth()->user()->canManageBilling(),
        ]);
    }

    /**
     * Records a new agreement. Any agreement still running on the start date ends the day before, so there is
     * always one agreement at a time and the history is kept.
     */
    public function storeSubscription(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'billing_plan_id' => ['required', 'ulid', Rule::exists(BillingPlan::class, 'id')->where('is_active', true)],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'billing_period' => ['nullable', Rule::in(BillingPlan::PERIODS)],
            'employee_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'starts_on' => ['required', 'date'],
            'status' => ['required', Rule::in(CompanySubscription::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['billing_plan_id.exists' => 'Choose an active plan.']);

        $plan = BillingPlan::query()->findOrFail($data['billing_plan_id']);
        $start = CarbonImmutable::parse($data['starts_on']);
        abort_if(CompanySubscription::query()->where('company_id', $company->id)->where('starts_on', '>=', $start->toDateString())->exists(), 422,
            'An agreement already starts on or after this date. End or correct it first.');

        $subscription = DB::transaction(function () use ($company, $plan, $data, $start, $request): CompanySubscription {
            $previous = CompanySubscription::query()->where('company_id', $company->id)->where('starts_on', '<', $start->toDateString())
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $start->toDateString()))->get();
            foreach ($previous as $running) {
                $running->forceFill(['ends_on' => $start->subDay()->toDateString(), 'end_reason' => $running->end_reason ?? 'Replaced by a new agreement'])->save();
            }

            return CompanySubscription::query()->create([
                'company_id' => $company->id, 'billing_plan_id' => $plan->id, 'plan_name' => $plan->name, 'price_basis' => $plan->price_basis,
                'price' => $data['price'] ?? $plan->price, 'currency' => $plan->currency, 'billing_period' => $data['billing_period'] ?? $plan->billing_period,
                'employee_limit' => $data['employee_limit'] ?? null, 'starts_on' => $start->toDateString(), 'status' => $data['status'],
                'notes' => $data['notes'] ?? null, 'recorded_by' => $request->user()->id,
            ]);
        });

        PlatformActivity::record('billing.subscription_recorded', "{$company->name}: {$subscription->plan_name} ({$subscription->billing_period}) from {$start->toDateString()}", $company->id, properties: [
            'subscription_id' => $subscription->id, 'plan' => $subscription->plan_name, 'price' => $subscription->price, 'currency' => $subscription->currency,
            'billing_period' => $subscription->billing_period, 'status' => $subscription->status,
        ]);

        return response()->json($this->present($subscription->load('recorder:id,name'), CarbonImmutable::today()), 201);
    }

    public function endSubscription(Request $request, Company $company, CompanySubscription $subscription): JsonResponse
    {
        abort_unless($subscription->company_id === $company->id, 404);
        $data = $request->validate([
            'ends_on' => ['required', 'date', 'after_or_equal:'.$subscription->starts_on->toDateString()],
            'reason' => ['required', 'string', 'max:500'],
        ], ['ends_on.after_or_equal' => 'An agreement cannot end before it starts.']);
        abort_if($subscription->ends_on !== null && $subscription->ends_on->lt(CarbonImmutable::parse($data['ends_on'])), 422, 'This agreement has already ended.');

        $subscription->forceFill(['ends_on' => $data['ends_on'], 'end_reason' => $data['reason']])->save();
        PlatformActivity::record('billing.subscription_ended', "{$company->name}: {$subscription->plan_name} ends on {$data['ends_on']}", $company->id, properties: [
            'subscription_id' => $subscription->id, 'reason' => $data['reason'],
        ]);

        return response()->json($this->present($subscription->load('recorder:id,name'), CarbonImmutable::today()));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CompanySubscription $subscription, CarbonImmutable $today): array
    {
        return [
            ...$subscription->only(['id', 'billing_plan_id', 'plan_name', 'price_basis', 'price', 'currency', 'billing_period', 'employee_limit', 'status', 'notes', 'end_reason']),
            'starts_on' => $subscription->starts_on->toDateString(),
            'ends_on' => $subscription->ends_on?->toDateString(),
            'state' => $subscription->stateOn($today),
            'next_period_starts' => $subscription->nextPeriodStart($today)?->toDateString(),
            'recorded_by' => $subscription->recorder?->name,
            'created_at' => $subscription->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPlan(Request $request, ?BillingPlan $plan = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique(BillingPlan::class)->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'price_basis' => ['required', Rule::in(BillingPlan::BASES)],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_period' => ['required', Rule::in(BillingPlan::PERIODS)],
            'is_active' => ['boolean'],
        ], ['name.unique' => 'Another plan already has this name.']);

        return [...$data, 'currency' => strtoupper($data['currency'])];
    }
}
