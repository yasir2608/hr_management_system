<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Manual changes to a balance (corrections, carried-over days). Balances themselves are
        // worked out from the policy, these adjustments and leave taken, so nothing drifts.
        Schema::create('leave_balance_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->foreignUlid('leave_type_id')->constrained();
            $table->date('leave_year_start');            // the leave year it belongs to
            $table->decimal('days', 6, 2);               // positive adds days, negative removes them
            $table->string('kind', 20)->default('adjustment'); // adjustment | carry_over
            $table->string('reason', 500);
            $table->foreignUlid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'leave_type_id', 'leave_year_start'], 'leave_adjust_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balance_adjustments');
    }
};
