<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['template_id', 'title', 'description', 'owner_type', 'due_offset_days', 'is_required', 'employee_field_group', 'position'])]
class OnboardingTemplateTask extends Model
{
    use BelongsToCompany, HasUlids;

    public const OWNERS = ['hr', 'manager', 'employee', 'payroll', 'it'];

    public const FIELD_GROUPS = ['personal', 'emergency', 'bank', 'tax'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'due_offset_days' => 'integer', 'position' => 'integer'];
    }
}
