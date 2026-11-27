<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'code', 'color', 'is_paid', 'uses_balance', 'requestable_by', 'approval', 'reason_required', 'attachment_after_days', 'is_active'])]
class LeaveType extends Model
{
    use BelongsToCompany, HasUlids;

    public const APPROVALS = ['none', 'manager', 'hr', 'manager_then_hr'];

    public const REQUESTABLE_BY = ['all', 'hr_only'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean', 'uses_balance' => 'boolean', 'reason_required' => 'boolean',
            'is_active' => 'boolean', 'attachment_after_days' => 'integer',
        ];
    }

    /**
     * The approval chain for the shared approval engine.
     *
     * @return list<string>
     */
    public function approvalChain(): array
    {
        return match ($this->approval) {
            'none' => [],
            'hr' => ['role:hr_admin'],
            'manager_then_hr' => ['manager', 'role:hr_admin'],
            default => ['manager'],
        };
    }

    /** Starter leave types every company can begin with. */
    public const STARTERS = [
        ['Annual leave', 'AL', '#315ee8', true, true, 'manager', false, null],
        ['Sick leave', 'SL', '#c13d4b', true, true, 'manager', false, 7],
        ['Unpaid leave', 'UL', '#748198', false, false, 'manager_then_hr', true, null],
        ['Parental leave', 'PL', '#16845b', true, true, 'hr', true, null],
    ];
}
