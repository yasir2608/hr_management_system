<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A payroll run: one payroll group, one pay period. Each employee line keeps a snapshot of the inputs it was built
     * from, so later changes to pay or tax details never silently rewrite a run.
     */
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('payroll_group_id')->constrained('payroll_groups');
            $table->char('country_code', 2);
            $table->char('currency', 3);
            $table->string('pay_frequency', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');
            $table->string('status', 20)->default('draft'); // draft | calculated | in_review | approved | cancelled
            $table->json('totals')->nullable();              // filled by the calculation
            $table->timestamp('inputs_gathered_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'payroll_group_id', 'period_start']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payroll_run_employees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained();
            $table->string('employee_name');                  // as it was when the run was built
            $table->string('employee_number', 50)->nullable();
            $table->boolean('included')->default(true);
            $table->string('readiness', 30);                  // ready | needs_review | missing_information
            $table->json('issues')->nullable();
            $table->text('inputs');                           // encrypted JSON snapshot (pay, items, unpaid leave, tax details)
            $table->text('results')->nullable();              // encrypted JSON, filled by the calculation
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_employees');
        Schema::dropIfExists('payroll_runs');
    }
};
