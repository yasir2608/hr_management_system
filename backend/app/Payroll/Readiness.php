<?php

namespace App\Payroll;

use App\Employees\PayrollReadiness;
use App\Models\CompanyPayrollCountry;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\PayrollGroup;
use App\Models\PayrollSetting;
use Carbon\CarbonImmutable;

/**
 * Whether employees and their payroll group are ready to be paid for a pay period.
 *
 * Each issue says whether it blocks calculation or is only a warning to review:
 * - missing_information: at least one blocking issue, the employee cannot be calculated
 * - needs_review: warnings only, the employee can be calculated but someone should look
 * - ready: nothing to do
 */
class Readiness
{
    public const READY = 'ready';

    public const NEEDS_REVIEW = 'needs_review';

    public const MISSING = 'missing_information';

    /**
     * Problems with the group or its country setup that stop the whole group from running.
     *
     * @return list<array{key: string, message: string, severity: string}>
     */
    public function groupIssues(PayrollGroup $group, ?PayrollSetting $countrySetting): array
    {
        $issues = [];
        $country = config("countries.{$group->country_code}.name", $group->country_code);

        if (! in_array($group->country_code, CompanyPayrollCountry::codes(), true)) {
            $issues[] = self::blocking('country', "{$country} is no longer one of the company’s payroll countries.");
        }
        if ($countrySetting === null) {
            $issues[] = self::blocking('settings', "Payroll settings for {$country} are not set up.");
        } else {
            foreach ($countrySetting->missingEmployerFields() as $field) {
                $issues[] = self::blocking("employer.{$field}", 'Employer detail missing: '.config("countries.{$group->country_code}.employer_fields.{$field}.label").'.');
            }
        }
        if ($group->schedule($countrySetting) === null) {
            $issues[] = self::blocking('schedule', 'This group has no pay schedule.');
        }

        return $issues;
    }

    /**
     * @param  array<string, float>  $unpaidDays  employee id => unpaid leave days in the period
     * @return array{state: string, issues: list<array{key: string, message: string, severity: string}>}
     */
    public function employee(Employee $employee, ?PayrollGroup $group, ?PayrollSetting $schedule, ?CarbonImmutable $periodStart, ?CarbonImmutable $periodEnd, array $unpaidDays = []): array
    {
        $issues = [];

        foreach (PayrollReadiness::missing($employee) as $item) {
            // A missing bank account does not stop the calculation; it stops paying them by bank file.
            $issues[] = $item['key'] === 'bank'
                ? self::warning('bank', 'No bank account: pay can be calculated but will be left out of the bank file.')
                : self::blocking($item['key'], "Missing: {$item['label']}.");
        }

        if ($employee->work_country !== null && ! in_array($employee->work_country, CompanyPayrollCountry::codes(), true)) {
            $issues[] = self::blocking('country', config("countries.{$employee->work_country}.name", $employee->work_country).' is not one of the company’s payroll countries.');
        }
        if ($group === null) {
            $issues[] = self::blocking('payroll_group', 'Not in a payroll group.');
        }

        /** @var EmployeeCompensation|null $pay */
        $pay = $employee->currentCompensation;
        if ($pay !== null && $schedule !== null && $pay->currency !== $schedule->currency) {
            $issues[] = self::blocking('currency', "Pay is recorded in {$pay->currency} but this group pays in {$schedule->currency}.");
        }
        if ($pay !== null && $pay->frequency === 'hourly' && $pay->hours_per_week === null) {
            $issues[] = self::warning('hours', 'Hourly pay with no hours per week: 40 hours a week will be used.');
        }

        if ($periodStart !== null && $periodEnd !== null) {
            if ($employee->start_date !== null && $employee->start_date->betweenIncluded($periodStart, $periodEnd) && $employee->start_date->gt($periodStart)) {
                $issues[] = self::warning('starter', 'Starts during this period on '.$employee->start_date->toDateString().': pay will be prorated.');
            }
            if ($employee->end_date !== null && $employee->end_date->betweenIncluded($periodStart, $periodEnd)) {
                $issues[] = self::warning('leaver', 'Leaves during this period on '.$employee->end_date->toDateString().': check final pay.');
            }
            if ($pay !== null && $pay->valid_from->betweenIncluded($periodStart->addDay(), $periodEnd)) {
                $issues[] = self::warning('pay_change', 'Pay changes part-way through this period on '.$pay->valid_from->toDateString().'.');
            }
            if (($unpaidDays[$employee->id] ?? 0) > 0) {
                $issues[] = self::warning('unpaid_leave', "{$unpaidDays[$employee->id]} day(s) of approved unpaid leave in this period.");
            }
        }

        if ($employee->current_status === Employee::STATUS_ONBOARDING) {
            $issues[] = self::warning('onboarding', 'Still onboarding: check they should be paid this period.');
        }
        if ($employee->current_status === Employee::STATUS_ON_LEAVE) {
            $issues[] = self::warning('on_leave', 'On leave: check whether pay continues.');
        }

        $blocking = collect($issues)->contains('severity', 'blocking');

        return [
            'state' => $blocking ? self::MISSING : ($issues === [] ? self::READY : self::NEEDS_REVIEW),
            'issues' => $issues,
        ];
    }

    /**
     * @return array{key: string, message: string, severity: string}
     */
    private static function blocking(string $key, string $message): array
    {
        return ['key' => $key, 'message' => $message, 'severity' => 'blocking'];
    }

    /**
     * @return array{key: string, message: string, severity: string}
     */
    private static function warning(string $key, string $message): array
    {
        return ['key' => $key, 'message' => $message, 'severity' => 'warning'];
    }
}
