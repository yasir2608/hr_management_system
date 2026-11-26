<?php

namespace App\Company;

use App\Models\CompanyPayrollCountry;
use App\Models\CompanyPreference;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\Department;
use App\Models\Location;
use App\Models\Team;
use App\Tenancy\CurrentCompany;

/**
 * Which setup steps the current company has completed. Steps marked
 * required_for_payroll must all be complete before production payroll can run.
 */
class SetupChecklist
{
    public const COMPLETE = 'complete';

    public const OUTSTANDING = 'outstanding';

    public const SKIPPED = 'skipped';

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * @return list<array{key: string, label: string, description: string, status: string, optional: bool, required_for_payroll: bool, link: string}>
     */
    public function steps(): array
    {
        $company = $this->currentCompany->require();
        $preferences = CompanyPreference::query()->first();

        $steps = [
            $this->step('profile', 'Company profile', 'Legal name and contact email.',
                filled($company->legal_name) && filled($company->contact_email), false, true, '/settings/company?tab=profile'),
            $this->step('payroll_countries', 'Payroll countries', 'Where you employ direct employees.',
                CompanyPayrollCountry::query()->exists(), false, true, '/settings/company?tab=countries'),
            $this->step('regional', 'Regional settings', 'Time zone, currency and working days.',
                CompanySetting::query()->exists(), false, true, '/settings/company?tab=regional'),
            $this->step('structure', 'Locations, departments and teams', 'Only if your company uses them.',
                Location::query()->exists() || Department::query()->exists() || Team::query()->exists(), true, false, '/settings/company?tab=structure'),
            [
                ...$this->step('preferences', 'Leave and attendance preferences', 'Can be left for later.',
                    $preferences?->confirmed_at !== null, true, false, '/settings/company?tab=preferences'),
                'status' => $preferences?->confirmed_at !== null ? self::COMPLETE : ($preferences?->skipped_at !== null ? self::SKIPPED : self::OUTSTANDING),
            ],
            $this->step('team_members', 'Invite HR, payroll and managers', 'Give colleagues access with the right role.',
                CompanyUser::query()->where('company_id', $company->id)->where('status', '!=', CompanyUser::STATUS_REMOVED)->count() > 1, true, false, '/settings/users'),
        ];

        return array_merge($steps, $this->additionalSteps());
    }

    /**
     * Hook for later modules (payroll settings) to add their own steps.
     *
     * @return list<array{key: string, label: string, description: string, status: string, optional: bool, required_for_payroll: bool, link: string}>
     */
    protected function additionalSteps(): array
    {
        return [];
    }

    public function readyForPayroll(): bool
    {
        return collect($this->steps())
            ->where('required_for_payroll', true)
            ->every(fn (array $step): bool => $step['status'] === self::COMPLETE);
    }

    /**
     * @return array{key: string, label: string, description: string, status: string, optional: bool, required_for_payroll: bool, link: string}
     */
    private function step(string $key, string $label, string $description, bool $done, bool $optional, bool $requiredForPayroll, string $link): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'status' => $done ? self::COMPLETE : self::OUTSTANDING,
            'optional' => $optional,
            'required_for_payroll' => $requiredForPayroll,
            'link' => $link,
        ];
    }
}
