<?php

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A minimal company-owned model used to test company scoping.
 */
#[Fillable(['company_id', 'name'])]
class ScopedRecord extends Model
{
    use BelongsToCompany, HasUlids;

    protected $table = 'scoped_records';
}
