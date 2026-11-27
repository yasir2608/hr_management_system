<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->foreignUlid('leave_type_id')->constrained();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('start_half')->default(false); // starts at midday
            $table->boolean('end_half')->default(false);   // ends at midday
            $table->decimal('days', 6, 2);                  // working days requested
            $table->decimal('unpaid_days', 6, 2)->default(0); // part taken as unpaid (insufficient balance rule)
            $table->string('reason', 1000)->nullable();
            $table->foreignUlid('attachment_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending | info_requested | approved | declined | cancelled
            $table->foreignUlid('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('requested_by')->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'start_date']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
