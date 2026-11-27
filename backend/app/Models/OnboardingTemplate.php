<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'is_default', 'is_active'])]
class OnboardingTemplate extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<OnboardingTemplateTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(OnboardingTemplateTask::class, 'template_id')->orderBy('position');
    }
}
