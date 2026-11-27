<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A stored file attached to an employee, contractor or other record. Files are kept on a
 * private disk and only served through permission-checked endpoints.
 */
#[Fillable([
    'documentable_type', 'documentable_id', 'category', 'title', 'disk', 'path', 'original_name',
    'mime_type', 'size_bytes', 'checksum', 'visibility', 'expires_on', 'uploaded_by',
])]
#[Hidden(['disk', 'path', 'checksum'])]
class Document extends Model
{
    use BelongsToCompany, HasUlids;

    /** Who may see a document besides HR: the employee themself, or payroll only. */
    public const VISIBILITY_EMPLOYEE = 'employee';

    public const VISIBILITY_HR = 'hr';

    public const VISIBILITY_PAYROLL = 'payroll';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_on' => 'date:Y-m-d', 'size_bytes' => 'integer'];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
