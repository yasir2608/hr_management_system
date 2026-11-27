<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['contractor_id', 'title', 'start_date', 'end_date', 'renewal_date', 'notice_days', 'rate_type', 'amount', 'currency', 'status', 'signed_document_id', 'notes'])]
class ContractorContract extends Model
{
    use BelongsToCompany, HasUlids;

    public const RATE_TYPES = ['hourly', 'daily', 'monthly', 'fixed_fee'];

    public const STATUSES = ['draft', 'active', 'ended', 'terminated'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'renewal_date' => 'date:Y-m-d', 'amount' => 'decimal:2', 'notice_days' => 'integer'];
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function signedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'signed_document_id');
    }
}
