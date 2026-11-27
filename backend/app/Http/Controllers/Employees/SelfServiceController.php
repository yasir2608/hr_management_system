<?php

namespace App\Http\Controllers\Employees;

use App\Access\Access;
use App\Employees\EmployeeProfile;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeUpdateRequest;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The employee's own profile and their requests to change personal details.
 */
class SelfServiceController extends Controller
{
    public function __construct(private readonly Access $access) {}

    public function show(EmployeeProfile $profile): JsonResponse
    {
        $employee = $this->access->employee();

        if ($employee === null) {
            return response()->json([
                'message' => 'Your account is not linked to an employee record in this company yet. Ask HR to link it.',
                'code' => 'NO_EMPLOYEE_RECORD',
            ], 404);
        }

        return response()->json([
            ...$profile->build($employee),
            'requests' => $this->requestsFor($employee),
            'requestable_fields' => EmployeeUpdateRequest::ALLOWED_FIELDS,
        ]);
    }

    public function requestUpdate(Request $request): JsonResponse
    {
        $employee = $this->access->employee() ?? abort(404);

        $rules = collect(EmployeeUpdateRequest::ALLOWED_FIELDS)
            ->mapWithKeys(fn (string $field): array => ["changes.{$field}" => ['nullable', 'string', 'max:255']])
            ->all();
        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            ...$rules,
            'changes.personal_email' => ['nullable', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $changes = collect($data['changes'] ?? [])
            ->only(EmployeeUpdateRequest::ALLOWED_FIELDS)
            ->filter(fn ($value, string $field): bool => (string) $value !== (string) $employee->{$field})
            ->map(fn ($value, string $field): array => ['old' => $employee->{$field}, 'new' => $value]);

        if ($changes->isEmpty()) {
            return response()->json(['message' => 'Nothing has changed.', 'errors' => ['changes' => ['Change at least one detail.']]], 422);
        }

        if (EmployeeUpdateRequest::query()->where('employee_id', $employee->id)->where('status', EmployeeUpdateRequest::STATUS_PENDING)->exists()) {
            return response()->json(['message' => 'You already have a request waiting for HR. Withdraw it first to send a new one.', 'code' => 'REQUEST_PENDING'], 422);
        }

        $updateRequest = EmployeeUpdateRequest::query()->create([
            'employee_id' => $employee->id,
            'requested_by' => $request->user()->id,
            'changes' => $changes->all(),
            'note' => $data['note'] ?? null,
            'status' => EmployeeUpdateRequest::STATUS_PENDING,
        ]);

        Activity::record('employee.update_requested', "{$employee->fullName()} asked to update their details", $employee, ['request_id' => $updateRequest->id, 'fields' => $changes->keys()]);

        return response()->json(['message' => 'Your request was sent to HR. You will see the outcome here.'], 201);
    }

    public function withdraw(string $requestId): JsonResponse
    {
        $employee = $this->access->employee() ?? abort(404);
        $updateRequest = EmployeeUpdateRequest::query()->where('employee_id', $employee->id)->where('status', EmployeeUpdateRequest::STATUS_PENDING)->findOrFail($requestId);
        $updateRequest->forceFill(['status' => EmployeeUpdateRequest::STATUS_WITHDRAWN])->save();

        return response()->json(['message' => 'Request withdrawn.']);
    }

    /**
     * HR: pending and past requests for one employee.
     */
    public function index(Employee $employee): JsonResponse
    {
        abort_unless($this->access->scopeFor('employees.manage')->covers($employee), 404);

        return response()->json(['data' => $this->requestsFor($employee)]);
    }

    public function review(Request $request, string $requestId): JsonResponse
    {
        $updateRequest = EmployeeUpdateRequest::query()->with('employee')->where('status', EmployeeUpdateRequest::STATUS_PENDING)->findOrFail($requestId);
        $employee = $updateRequest->employee;
        abort_unless($this->access->scopeFor('employees.manage')->covers($employee), 404);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,decline'],
            'review_note' => ['nullable', 'required_if:decision,decline', 'string', 'max:500'],
        ], ['review_note.required_if' => 'Tell the employee why the request was declined.']);

        if ($data['decision'] === 'approve') {
            $employee->fill(collect($updateRequest->changes)->map(fn (array $change) => $change['new'])->only(EmployeeUpdateRequest::ALLOWED_FIELDS)->all())->save();
        }

        $updateRequest->forceFill([
            'status' => $data['decision'] === 'approve' ? EmployeeUpdateRequest::STATUS_APPROVED : EmployeeUpdateRequest::STATUS_DECLINED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ])->save();

        Activity::record(
            $data['decision'] === 'approve' ? 'employee.update_approved' : 'employee.update_declined',
            ($data['decision'] === 'approve' ? 'Approved' : 'Declined')." {$employee->fullName()}'s request to update their details",
            $employee,
            ['request_id' => $updateRequest->id, 'changes' => $updateRequest->changes],
        );

        return response()->json(['message' => $data['decision'] === 'approve' ? 'Request approved and the details updated.' : 'Request declined.']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestsFor(Employee $employee): array
    {
        return EmployeeUpdateRequest::query()->with('reviewer:id,name')->where('employee_id', $employee->id)->latest()->limit(20)->get()
            ->map(fn (EmployeeUpdateRequest $updateRequest): array => [
                ...$updateRequest->only(['id', 'changes', 'note', 'status', 'review_note', 'created_at', 'reviewed_at']),
                'reviewed_by' => $updateRequest->reviewer?->name,
            ])->all();
    }
}
