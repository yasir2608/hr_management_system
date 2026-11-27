<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('contractor_id')->constrained();
            $table->foreignUlid('contract_id')->nullable()->constrained('contractor_contracts')->nullOnDelete();
            $table->string('reference', 100);              // the contractor's own invoice number
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3);
            $table->decimal('amount', 14, 2);
            $table->text('description')->nullable();
            $table->foreignUlid('attachment_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status', 20)->default('received');
            $table->foreignUlid('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'contractor_id', 'reference']);
            $table->index(['company_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_invoices');
    }
};
