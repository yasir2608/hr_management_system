<?php

namespace App\Leave;

use App\Approvals\ApprovalEngine;
use App\Models\CompanyPreference;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checks a leave request against the leave type's rules and the employee's balance, saves
 * it with any attachment, and sends it for approval (or approves it straight away when the
 * leave type needs no approval).
 */
class SubmitLeaveRequest
{
    public function __construct(
        private readonly WorkingDays $workingDays,
        private readonly BalanceCalculator $balances,
        private readonly ApprovalEngine $approvals,
    ) {}

    /**
     * @param  array{leave_type_id: string, start_date: string, end_date: string, start_half?: bool, end_half?: bool, reason?: ?string}  $data
     */
    public function handle(Employee $employee, array $data, User $requester, ?UploadedFile $attachment = null): LeaveRequest
    {
        $type = LeaveType::query()->where('is_active', true)->findOrFail($data['leave_type_id']);
        $start = Carbon::parse($data['start_date'])->toDateString();
        $end = Carbon::parse($data['end_date'])->toDateString();
        $startHalf = (bool) ($data['start_half'] ?? false);
        $endHalf = (bool) ($data['end_half'] ?? false);
        $preferences = CompanyPreference::current();

        $errors = [];

        if ($end < $start) {
            $errors['end_date'] = 'The last day cannot be before the first day.';
        }

        if (($startHalf || $endHalf) && ! $preferences->half_day_leave_allowed) {
            $errors['start_half'] = 'Your company does not allow half-day leave.';
        }

        if ($type->reason_required && blank($data['reason'] ?? null)) {
            $errors['reason'] = "A reason is required for {$type->name}.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $days = $this->workingDays->count($start, $end, $startHalf, $endHalf);

        if ($days <= 0) {
            throw ValidationException::withMessages(['start_date' => 'These dates contain no working days.']);
        }

        if ($type->attachment_after_days !== null && $days > $type->attachment_after_days && $attachment === null) {
            throw ValidationException::withMessages(['attachment' => "Attach supporting evidence for {$type->name} longer than {$type->attachment_after_days} days."]);
        }

        $overlap = LeaveRequest::query()->active()->where('employee_id', $employee->id)
            ->where('start_date', '<=', $end)->where('end_date', '>=', $start)->first();

        if ($overlap !== null) {
            throw ValidationException::withMessages(['start_date' => 'You already have leave from '.$overlap->start_date->toDateString().' to '.$overlap->end_date->toDateString().' that overlaps these dates.']);
        }

        ['unpaid' => $unpaidDays, 'exception' => $exceptionDays] = $this->checkBalance($employee, $type, $days, $start);

        return DB::transaction(function () use ($employee, $type, $start, $end, $startHalf, $endHalf, $days, $unpaidDays, $exceptionDays, $data, $requester, $attachment): LeaveRequest {
            $request = LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $start,
                'end_date' => $end,
                'start_half' => $startHalf,
                'end_half' => $endHalf,
                'days' => $days,
                'unpaid_days' => $unpaidDays,
                'reason' => $data['reason'] ?? null,
                'status' => LeaveRequest::STATUS_PENDING,
                'requested_by' => $requester->id,
            ]);

            if ($attachment !== null) {
                $request->forceFill(['attachment_document_id' => $this->storeAttachment($employee, $attachment, $requester, $type)->id])->save();
            }

            $chain = $type->approvalChain();

            // Going over the balance as an exception always needs HR, even for types without approval.
            if ($exceptionDays > 0 && ! in_array('role:hr_admin', $chain, true)) {
                $chain[] = 'role:hr_admin';
            }

            if ($chain === []) {
                $request->forceFill(['status' => LeaveRequest::STATUS_APPROVED, 'decided_at' => now()])->save();
            } else {
                $approval = $this->approvals->submit($request, 'leave_request', $requester, $employee, "{$type->name} for {$employee->fullName()}", [
                    'leave_type' => $type->name,
                    'dates' => $start === $end ? $start : "{$start} to {$end}",
                    'working_days' => $days,
                    'unpaid_days' => $unpaidDays > 0 ? $unpaidDays : null,
                    'over_balance_exception' => $exceptionDays > 0 ? "{$exceptionDays} day(s) beyond the balance" : null,
                    'reason' => $data['reason'] ?? null,
                ], $chain);
                $request->forceFill(['approval_request_id' => $approval->id])->save();
            }

            Activity::record('leave.requested', "{$employee->fullName()} requested {$days} day(s) of {$type->name}", $request);

            return $request;
        });
    }

    /**
     * Applies the company's rule for requests beyond the balance:
     *
     * - block: refuse the request
     * - allow_unpaid: the extra days are taken as unpaid leave
     * - exception: send it, with the shortfall flagged, to HR as an exception
     *
     * @return array{unpaid: float, exception: float}
     */
    protected function checkBalance(Employee $employee, LeaveType $type, float $days, string $start): array
    {
        if (! $type->uses_balance) {
            return ['unpaid' => 0.0, 'exception' => 0.0];
        }

        $available = max(0.0, $this->balances->balance($employee, $type, $start)['available']);

        if ($days <= $available) {
            return ['unpaid' => 0.0, 'exception' => 0.0];
        }

        $shortfall = round($days - $available, 2);

        return match (CompanyPreference::current()->insufficient_balance_rule) {
            CompanyPreference::BALANCE_ALLOW_UNPAID => ['unpaid' => $shortfall, 'exception' => 0.0],
            CompanyPreference::BALANCE_EXCEPTION => ['unpaid' => 0.0, 'exception' => $shortfall],
            default => throw ValidationException::withMessages([
                'end_date' => sprintf('You have %s day(s) of %s left, but asked for %s.', rtrim(rtrim(number_format($available, 1), '0'), '.'), $type->name, $days),
            ]),
        };
    }

    private function storeAttachment(Employee $employee, UploadedFile $file, User $requester, LeaveType $type): Document
    {
        $path = $file->storeAs("companies/{$employee->company_id}/employees/{$employee->id}/leave", Str::ulid().'.'.$file->extension(), 'local');

        return $employee->documents()->create([
            'category' => 'leave_evidence',
            'title' => "{$type->name} evidence",
            'disk' => 'local',
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'visibility' => Document::VISIBILITY_EMPLOYEE,
            'uploaded_by' => $requester->id,
        ]);
    }
}
