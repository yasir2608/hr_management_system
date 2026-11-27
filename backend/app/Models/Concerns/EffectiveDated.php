<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rows valid from `valid_from` (inclusive) to `valid_to` (exclusive); NULL valid_to = current.
 *
 * @mixin Model
 */
trait EffectiveDated
{
    public function initializeEffectiveDated(): void
    {
        $this->mergeCasts(['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d']);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('valid_to');
    }

    /**
     * The row in force on a date.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOn(Builder $query, string $date): void
    {
        $query->where('valid_from', '<=', $date)->where(fn (Builder $inner) => $inner->whereNull('valid_to')->orWhere('valid_to', '>', $date));
    }
}
