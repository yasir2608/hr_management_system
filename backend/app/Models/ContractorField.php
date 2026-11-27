<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * A field a company adds to its contractor records (e.g. "Supplier number", "Insurance expiry").
 */
#[Fillable(['key', 'label', 'type', 'options', 'is_required', 'position'])]
class ContractorField extends Model
{
    use BelongsToCompany, HasUlids;

    public const TYPES = ['text', 'number', 'date', 'select'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['options' => 'array', 'is_required' => 'boolean', 'position' => 'integer'];
    }

    /**
     * Validation rules for this field's value.
     *
     * @return list<mixed>
     */
    public function rules(): array
    {
        return [
            $this->is_required ? 'required' : 'nullable',
            ...match ($this->type) {
                'number' => ['numeric'],
                'date' => ['date'],
                'select' => [Rule::in($this->options ?? [])],
                default => ['string', 'max:500'],
            },
        ];
    }
}
