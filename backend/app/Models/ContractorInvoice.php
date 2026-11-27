<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['contractor_id', 'contract_id', 'reference', 'issue_date', 'due_date', 'currency', 'amount', 'description', 'attachment_document_id', 'status', 'created_by'])]
class ContractorInvoice extends Model
{
    use BelongsToCompany, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<Contractor, $this>
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * @return BelongsTo<ContractorContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(ContractorContract::class, 'contract_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'attachment_document_id');
    }
}
