<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaveTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => LeaveType::query()->orderByDesc('is_active')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $type = LeaveType::query()->create($this->validated($request));
        Activity::record('leave.type_created', "Leave type {$type->name} added", $type);

        return response()->json($type, 201);
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        $leaveType->fill($this->validated($request, $leaveType))->save();
        Activity::record('leave.type_updated', "Leave type {$leaveType->name} updated", $leaveType, Activity::changes($leaveType));

        return response()->json($leaveType);
    }

    /**
     * Adds the starter leave types (annual, sick, unpaid, parental) that do not exist yet.
     */
    public function addStarters(): JsonResponse
    {
        $added = 0;

        foreach (LeaveType::STARTERS as [$name, $code, $color, $paid, $balance, $approval, $reason, $attachment]) {
            $type = LeaveType::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'color' => $color, 'is_paid' => $paid, 'uses_balance' => $balance,
                'approval' => $approval, 'reason_required' => $reason, 'attachment_after_days' => $attachment,
            ]);
            $added += $type->wasRecentlyCreated ? 1 : 0;
        }

        return response()->json(['message' => $added > 0 ? "{$added} leave type(s) added." : 'The starter leave types already exist.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?LeaveType $type = null): array
    {
        $companyId = app(CurrentCompany::class)->id();

        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('leave_types')->where('company_id', $companyId)->ignore($type?->id)],
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('leave_types')->where('company_id', $companyId)->ignore($type?->id)],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_paid' => ['required', 'boolean'],
            'uses_balance' => ['required', 'boolean'],
            'requestable_by' => ['required', Rule::in(LeaveType::REQUESTABLE_BY)],
            'approval' => ['required', Rule::in(LeaveType::APPROVALS)],
            'reason_required' => ['boolean'],
            'attachment_after_days' => ['nullable', 'integer', 'between:1,60'],
            'is_active' => ['boolean'],
        ], ['code.unique' => 'Another leave type uses this code.', 'name.unique' => 'Another leave type has this name.']);

        $data['code'] = strtoupper($data['code']);

        return $data;
    }
}
