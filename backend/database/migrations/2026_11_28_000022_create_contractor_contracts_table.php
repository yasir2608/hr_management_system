<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_contracts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('contractor_id')->constrained();
            $table->string('title');
            $table->date('start_date');
            $table->date('end_date')->nullable();          // NULL = open-ended
            $table->date('renewal_date')->nullable();      // when it should be reviewed or renewed
            $table->unsignedSmallInteger('notice_days')->nullable();
            $table->string('rate_type', 20);               // hourly | daily | monthly | fixed_fee
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('status', 20)->default('draft'); // draft | active | ended | terminated
            $table->foreignUlid('signed_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'contractor_id']);
            $table->index(['company_id', 'status', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_contracts');
    }
};
