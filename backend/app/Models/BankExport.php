<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank file created from an approved payroll run, for the company to upload to its bank. The file holds full
 * account numbers, so it is encrypted at rest and only returned by the download endpoint.
 */
#[Fillable(['payroll_run_id', 'bank_format_id', 'format_name', 'template', 'file_name', 'mime', 'currency', 'payment_count', 'total_cents', 'excluded', 'content', 'created_by'])]
#[Hidden(['content'])]
class BankExport extends Model
{
    use BelongsToCompany, HasUlids;

    public const NOT_RECORDED = 'not_recorded';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const PAYMENT_STATUSES = [self::NOT_RECORDED, self::PAID, self::FAILED];

    /** @var array<string, mixed> */
    protected $attributes = ['payment_status' => self::NOT_RECORDED];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['excluded' => 'array', 'content' => 'encrypted', 'paid_on' => 'date:Y-m-d', 'payment_recorded_at' => 'datetime', 'payment_count' => 'integer', 'total_cents' => 'integer', 'download_count' => 'integer', 'last_downloaded_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<BankFormat, $this>
     */
    public function format(): BelongsTo
    {
        return $this->belongsTo(BankFormat::class, 'bank_format_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function paymentRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_recorded_by');
    }
}
