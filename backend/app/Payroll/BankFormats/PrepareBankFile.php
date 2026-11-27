<?php

namespace App\Payroll\BankFormats;

use App\Models\BankExport;
use App\Models\BankFormat;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\PayrollRun;
use App\Models\PayrollRunEmployee;
use App\Payroll\Calculation\Money;
use Illuminate\Support\Collection;

/**
 * Works out the payments of a bank file for an approved run in one format: who is paid, how much, and who is left
 * out and why. Amounts come from the approved figures; bank details are each employee's current primary account.
 * - regular and replacement runs pay each included employee's net pay
 * - adjustment runs pay only the extra net pay; a recovery (negative difference) cannot be paid by bank file
 */
class PrepareBankFile
{
    /**
     * @return array{
     *     payments: list<array{employee_id: string, name: string, employee_number: ?string, amount_cents: int, account: EmployeeBankAccount}>,
     *     excluded: list<array{employee_id: string, name: string, reason: string}>,
     *     warnings: list<string>,
     *     payment_count: int,
     *     total_cents: int
     * }
     */
    public function prepare(PayrollRun $run, BankFormat $format): array
    {
        $lines = $run->lines()->orderBy('employee_name')->get();
        $accounts = EmployeeBankAccount::query()->whereIn('employee_id', $lines->pluck('employee_id'))
            ->orderByDesc('is_primary')->orderBy('created_at')->get()->groupBy('employee_id')->map(fn (Collection $group) => $group->first());
        $employees = Employee::query()->whereIn('id', $lines->pluck('employee_id'))->get(['id', 'employee_number'])->keyBy('id');

        $payments = [];
        $excluded = [];
        foreach ($lines as $line) {
            $person = ['employee_id' => $line->employee_id, 'name' => $line->employee_name];
            if (! $line->included || $line->results === null) {
                $excluded[] = [...$person, 'reason' => 'Left out of this payroll run.'];

                continue;
            }

            $amount = $this->amount($run, $line);
            if ($amount <= 0) {
                $excluded[] = [...$person, 'reason' => $amount < 0
                    ? 'This adjustment recovers '.Money::display(-$amount, $run->currency).'. Recoveries cannot be paid by bank file.'
                    : 'Nothing to pay.'];

                continue;
            }

            $account = $accounts->get($line->employee_id);
            $problems = BankFormatCatalog::problems($format->template, $account);
            if ($problems !== []) {
                $excluded[] = [...$person, 'reason' => implode(' ', $problems)];

                continue;
            }

            $payments[] = [...$person, 'employee_number' => $employees->get($line->employee_id)?->employee_number ?? $line->employee_number, 'amount_cents' => $amount, 'account' => $account];
        }

        return [
            'payments' => $payments,
            'excluded' => $excluded,
            'warnings' => $this->warnings($run),
            'payment_count' => count($payments),
            'total_cents' => array_sum(array_column($payments, 'amount_cents')),
        ];
    }

    private function amount(PayrollRun $run, PayrollRunEmployee $line): int
    {
        $results = $line->results ?? [];

        return $run->kind === PayrollRun::KIND_ADJUSTMENT
            ? Money::cents($results['adjustment']['net_pay']['difference'] ?? 0)
            : Money::cents($results['net_pay'] ?? 0);
    }

    /**
     * Situations where a file could pay someone twice.
     *
     * @return list<string>
     */
    private function warnings(PayrollRun $run): array
    {
        $warnings = [];
        $previous = BankExport::query()->where('payroll_run_id', $run->id)->count();
        if ($previous > 0) {
            $warnings[] = "A bank file was already created for this run ({$previous} so far). Upload only one of them to your bank, or employees will be paid twice.";
        }
        if ($run->kind === PayrollRun::KIND_REPLACEMENT && $run->corrects_run_id !== null && BankExport::query()->where('payroll_run_id', $run->corrects_run_id)->exists()) {
            $warnings[] = 'The run this replaces already has a bank file. This file pays full net pay again; if the original file was already paid, pay only the differences instead.';
        }

        return $warnings;
    }
}
