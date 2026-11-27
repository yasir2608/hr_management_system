<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'country_code', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'timezone', 'latitude', 'longitude', 'clock_radius_m', 'is_active'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToCompany, HasFactory, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'latitude' => 'float', 'longitude' => 'float', 'clock_radius_m' => 'integer'];
    }
}
