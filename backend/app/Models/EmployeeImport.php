<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<int, array{row: int, values: array<string, string|null>, errors: array<string, string>}> $rows
 */
#[Fillable(['uploaded_by', 'original_name', 'status', 'columns', 'rows', 'row_count', 'ready_count', 'error_count', 'created_count', 'completed_at'])]
#[Hidden(['rows'])]
class EmployeeImport extends Model
{
    use BelongsToCompany, HasUlids;

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['columns' => 'array', 'rows' => 'encrypted:array', 'completed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_UPLOADED, self::STATUS_VALIDATED], true);
    }
}
