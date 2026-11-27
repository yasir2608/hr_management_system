<?php

namespace App\Employees;

use App\Access\Access;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeTaxDetail;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates an employee with their first history rows in one transaction. Pay, tax and bank
 * details are only saved when the user may manage payroll; otherwise they are left for later.
 */
class CreateEmployee
{
    public const PERSONAL = [
        'first_name', 'last_name', 'preferred_name', 'work_email', 'personal_email', 'phone', 'date_of_birth', 'gender', 'nationality',
        'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'address_country',
        'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone', 'work_country', 'start_date',
    ];

    public function __construct(private readonly EmployeeRecord $record, private readonly Access $access) {}

    /**
     * @param  array<string, mixed>  $data  validated with EmployeeRules
     * @return array{employee: Employee, payroll_saved: bool}
     */
    public function handle(array $data, ?User $actor): array
    {
        $canSavePayroll = $this->access->can('payroll.settings.manage');

        $employee = DB::transaction(function () use ($data, $actor, $canSavePayroll): Employee {
            $start = $data['start_date'];
            $status = $data['status'] ?? Employee::STATUS_ONBOARDING;

            $employee = Employee::query()->create([
                ...Arr::only($data, self::PERSONAL),
                'employee_number' => $data['employee_number'] ?? self::nextNumber(),
                'work_email' => isset($data['work_email']) ? strtolower($data['work_email']) : null,
                'current_status' => $status,
            ]);

            $this->record->changeJob($employee, [
                'job_title' => $data['job_title'],
                'department_id' => $data['department_id'] ?? null,
                'team_id' => $data['team_id'] ?? null,
                'employment_type' => $data['employment_type'] ?? null,
            ], $start, 'Joined', $actor);
            $this->record->changeStatus($employee, $status, $start, 'Joined', $actor);

            if (! empty($data['location_id'])) {
                $this->record->changeLocation($employee, $data['location_id'], $data['work_arrangement'] ?? 'onsite', $start, 'Joined', $actor);
            } elseif (! empty($data['work_arrangement'])) {
                $employee->forceFill(['current_work_arrangement' => $data['work_arrangement']])->save();
            }

            if (! empty($data['manager_id'])) {
                $this->record->changeManager($employee, $data['manager_id'], $start, 'Joined', $actor);
            }

            if ($canSavePayroll) {
                $this->savePayroll($employee, $data, $actor);
            }

            Activity::record('employee.created', "Employee {$employee->fullName()} added", $employee);

            return $employee;
        });

        return [
            'employee' => $employee->fresh(['department', 'team', 'location', 'manager']),
            'payroll_saved' => $canSavePayroll && (! empty($data['pay']) || ! empty($data['tax_details']) || ! empty($data['bank'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function savePayroll(Employee $employee, array $data, ?User $actor): void
    {
        if (! empty($data['pay'])) {
            $this->record->changeCompensation($employee, $data['pay'], $data['start_date'] ?? now()->toDateString(), 'Starting pay', $actor);
        }

        $taxDetails = array_filter($data['tax_details'] ?? [], fn ($value) => filled($value));

        if ($taxDetails !== [] && $employee->work_country) {
            $existing = EmployeeTaxDetail::query()->firstOrNew(['employee_id' => $employee->id, 'country_code' => $employee->work_country]);
            // Empty fields keep their current value.
            $existing->details = [...($existing->details ?? []), ...$taxDetails];
            $existing->save();
        }

        if (! empty($data['bank'])) {
            EmployeeBankAccount::query()->where('employee_id', $employee->id)->update(['is_primary' => false]);
            EmployeeBankAccount::query()->create([...$data['bank'], 'employee_id' => $employee->id, 'is_primary' => true]);
        }
    }

    /**
     * Next free number in the company's E-0001 sequence.
     */
    public static function nextNumber(): string
    {
        $highest = Employee::withTrashed()->where('employee_number', 'like', 'E-%')->pluck('employee_number')
            ->map(fn (string $number): int => (int) substr($number, 2))
            ->max() ?? 0;

        return sprintf('E-%04d', $highest + 1);
    }
}
