<?php

namespace App\Http\Controllers\Payroll;

use App\Access\Access;
use App\Access\SensitiveData;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\EmployeePayItem;
use App\Payroll\BasePay;
use App\Payroll\ImpactPreview;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shows which upcoming pay periods a pay change or pay item change touches, before it is saved.
 */
class ImpactPreviewController extends Controller
{
    public function __invoke(Request $request, Employee $employee, Access $access, SensitiveData $sensitive, ImpactPreview $preview): JsonResponse
    {
        abort_unless($access->can('payroll.settings.manage') && $sensitive->canSee(SensitiveData::SALARY), 403);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['pay', 'pay_item'])],
            'effective_from' => ['required', 'date'],
            'pay_basis' => ['required_if:kind,pay', Rule::in(['salary', 'hourly'])],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:999999999'],
            'frequency' => ['required_if:kind,pay', Rule::in(['annual', 'monthly', 'hourly'])],
            'hours_per_week' => ['nullable', 'numeric', 'between:1,80'],
            'item_id' => ['required_if:kind,pay_item', 'nullable', 'ulid'],
            'percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
        ]);

        $employee->loadMissing(['payrollGroup', 'compensationHistory']);
        $frequency = $employee->payrollGroup?->schedule()?->pay_frequency ?? 'monthly';
        $basePayOn = function (CarbonImmutable $date) use ($employee, $frequency): string {
            $row = $employee->compensationHistory->first(fn (EmployeeCompensation $pay) => $pay->valid_from->lte($date) && ($pay->valid_to === null || $pay->valid_to->gt($date)))
                ?? $employee->compensationHistory->sortBy('valid_from')->first();

            return $row ? BasePay::perPeriod((string) $row->amount, $row->frequency, $frequency, $row->hours_per_week === null ? null : (string) $row->hours_per_week) : '0.00';
        };
        $from = CarbonImmutable::parse($data['effective_from']);

        if ($data['kind'] === 'pay') {
            abort_if(empty($data['amount']), 422, 'Enter the new pay amount to see its effect.');
            $result = $preview->forEmployee(
                $employee, $from,
                fn (CarbonImmutable $start) => $basePayOn($start),
                fn () => BasePay::perPeriod((string) $data['amount'], $data['frequency'], $frequency, isset($data['hours_per_week']) ? (string) $data['hours_per_week'] : null),
            );
            $label = 'Base pay per period';
        } else {
            $item = EmployeePayItem::query()->where('employee_id', $employee->id)->findOrFail($data['item_id']);
            $value = fn (?string $amount, ?string $percent, CarbonImmutable $start): string => $item->calculation === 'fixed'
                ? BasePay::money((float) $amount)
                : BasePay::money((float) $basePayOn($start) * (float) $percent / 100);
            abort_if($item->calculation === 'fixed' ? empty($data['amount']) : empty($data['percent']), 422, 'Enter the new amount to see its effect.');

            $result = $preview->forEmployee(
                $employee, $from,
                fn (CarbonImmutable $start) => $value((string) $item->amount, (string) $item->percent, $start),
                fn (CarbonImmutable $start) => $value(isset($data['amount']) ? (string) $data['amount'] : null, isset($data['percent']) ? (string) $data['percent'] : null, $start),
            );
            $label = "{$item->name} per period";
        }

        return response()->json([
            ...$result,
            'label' => $label,
            'note' => 'Pay periods that end before the change keep the old value. Approved payroll runs always keep the values they were calculated with.',
        ]);
    }
}
