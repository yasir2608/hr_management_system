<?php

namespace App\Payroll;

use App\Models\PayrollRunEmployee;
use App\Payroll\Calculation\Money;
use Illuminate\Support\Collection;

/**
 * Totals for each pay item across a run (all income tax, all employer pension ...), for reviewing a run as a whole.
 */
class RunSummary
{
    /** Display order of the groups. */
    public const GROUPS = [
        'earning' => 'Pay',
        'pre_tax_deduction' => 'Deductions before tax',
        'tax' => 'Taxes',
        'employee_contribution' => 'Employee contributions',
        'post_tax_deduction' => 'Deductions after tax',
        'employer_contribution' => 'Employer costs',
    ];

    /**
     * @param  Collection<int, PayrollRunEmployee>  $lines
     * @return list<array{category: string, label: string, items: list<array{code: string, label: string, amount: string, employees: int}>, total: string}>
     */
    public function byItem(Collection $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            if (! $line->included || $line->results === null) {
                continue;
            }
            foreach ($line->results['lines'] ?? [] as $row) {
                $key = $row['category'].'|'.$row['code'].'|'.$row['label'];
                $items[$key] ??= ['category' => $row['category'], 'code' => $row['code'], 'label' => $row['label'], 'cents' => 0, 'employees' => []];
                // Deductions are stored as negative amounts on the employee's line; totals show them as positive sums.
                $items[$key]['cents'] += $row['category'] === 'earning' ? Money::cents($row['amount']) : abs(Money::cents($row['amount']));
                $items[$key]['employees'][$line->employee_id] = true;
            }
        }

        $groups = [];
        foreach (self::GROUPS as $category => $label) {
            $inGroup = array_values(array_filter($items, fn (array $item): bool => $item['category'] === $category));
            if ($inGroup === []) {
                continue;
            }
            $groups[] = [
                'category' => $category,
                'label' => $label,
                'items' => array_map(fn (array $item): array => [
                    'code' => $item['code'], 'label' => $item['label'], 'amount' => Money::format($item['cents']), 'employees' => count($item['employees']),
                ], $inGroup),
                'total' => Money::format(array_sum(array_column($inGroup, 'cents'))),
            ];
        }

        return $groups;
    }
}
