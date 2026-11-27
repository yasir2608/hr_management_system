<?php

namespace App\Employees\Import;

use App\Employees\CreateEmployee;
use App\Employees\EmployeeRules;
use App\Models\EmployeeImport;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Saves the ready rows of an import. Rows are checked once more, then each employee is
 * created in its own transaction, so a row either becomes a complete employee or nothing
 * at all. Rows with problems and rows left out are never saved.
 */
class ConfirmImport
{
    public function __construct(private readonly ImportValidator $validator, private readonly CreateEmployee $createEmployee) {}

    /**
     * @return array{created: int, skipped: int, excluded: int, failed: int}
     */
    public function handle(EmployeeImport $import, User $actor): array
    {
        $this->validator->validate($import);

        $results = [];
        $counts = ['created' => 0, 'skipped' => 0, 'excluded' => 0, 'failed' => 0];

        foreach ($import->rows as $row) {
            $name = trim(($row['values']['first_name'] ?? '').' '.($row['values']['last_name'] ?? ''));

            if ($row['excluded'] ?? false) {
                $counts['excluded']++;
                $results[] = ['row' => $row['row'], 'name' => $name, 'outcome' => 'excluded'];

                continue;
            }

            if ($row['errors'] !== []) {
                $counts['skipped']++;
                $results[] = ['row' => $row['row'], 'name' => $name, 'outcome' => 'skipped', 'problems' => count($row['errors'])];

                continue;
            }

            try {
                $payload = $this->validator->toPayload($row['values']);
                $country = $payload['work_country'] ?? null;
                $data = Validator::make($payload, [...EmployeeRules::core(), ...EmployeeRules::payroll($country)])->validate();
                $employee = $this->createEmployee->handle($data, $actor)['employee'];

                if ($employee->work_email) {
                    $this->validator->rememberEmployee($employee->work_email, $employee->id);
                }

                $counts['created']++;
                $results[] = ['row' => $row['row'], 'name' => $employee->fullName(), 'outcome' => 'created', 'employee_id' => $employee->id, 'employee_number' => $employee->employee_number];
            } catch (Throwable $exception) {
                report($exception);
                $counts['failed']++;
                $results[] = ['row' => $row['row'], 'name' => $name, 'outcome' => 'failed'];
            }
        }

        // Keep only the outcome per row: no personal or sensitive values stay in the import.
        $import->forceFill([
            'status' => EmployeeImport::STATUS_COMPLETED,
            'rows' => $results,
            'created_count' => $counts['created'],
            'completed_at' => now(),
        ])->save();

        Activity::record('employee_import.completed', "Employee import “{$import->original_name}” finished: {$counts['created']} added", $import, $counts);

        return $counts;
    }
}
