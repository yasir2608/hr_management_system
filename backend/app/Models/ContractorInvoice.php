<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['contractor_id', 'contract_id', 'reference', 'issue_date', 'due_date', 'currency', 'amount', 'description', 'attachment_document_id', 'status', 'created_by'])]
class ContractorInvoice extends Model
{
    use BelongsToCompany, HasUlids;

    /** Stored statuses. "Overdue" is not stored: it is worked out from the due date. */
    public const STATUSES = ['draft', 'received', 'pending_review', 'approved', 'rejected', 'paid'];

    /** Statuses still open for editing by people who manage contractors. */
    public const EDITABLE = ['draft', 'received', 'pending_review'];

    /** Which statuses someone can move an invoice to by hand; approval and payment have their own steps. */
    public const MANUAL_MOVES = [
        'draft' => ['received', 'pending_review'],
        'received' => ['pending_review', 'draft'],
        'pending_review' => ['received'],
        'rejected' => ['received'],
    ];

    /** Unpaid statuses that can fall overdue. */
    public const OUTSTANDING = ['received', 'pending_review', 'approved'];

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OUTSTANDING, true) && $this->due_date !== null && $this->due_date->lt(today());
    }

    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->whereIn('status', self::OUTSTANDING)->whereNotNull('due_date')->where('due_date', '<', today()->toDateString());
    }

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
