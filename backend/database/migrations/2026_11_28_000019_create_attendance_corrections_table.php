<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An employee asking to fix a shift (or add a missing one). The original values and the
        // requested values are kept separately, so the record's history is never lost.
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->foreignUlid('attendance_record_id')->nullable()->constrained()->nullOnDelete(); // NULL = add a missing shift
            $table->date('work_date');
            $table->timestamp('original_clock_in_at')->nullable();
            $table->timestamp('original_clock_out_at')->nullable();
            $table->timestamp('requested_clock_in_at');
            $table->timestamp('requested_clock_out_at')->nullable();
            $table->string('timezone', 64);             // zone the requested times were entered in
            $table->string('reason', 1000);
            $table->string('status', 20)->default('pending'); // pending | info_requested | approved | declined | cancelled
            $table->foreignUlid('approval_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('requested_by')->constrained('users');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
