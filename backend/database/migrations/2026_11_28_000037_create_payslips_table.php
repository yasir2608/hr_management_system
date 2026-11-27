<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payslips, issued for every included employee when a payroll run is approved. The content (company and employee
     * names, items and amounts) is a snapshot taken at approval and encrypted at rest. A payslip is never edited: an
     * approved replacement run supersedes it with a new one, and an adjustment run issues an extra adjustment payslip.
     */
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('payroll_run_id')->constrained('payroll_runs');
            $table->foreignUlid('payroll_run_employee_id')->constrained('payroll_run_employees');
            $table->foreignUlid('employee_id')->constrained();
            $table->string('number', 30);                       // e.g. PS-2026-000123, unique per company
            $table->string('kind', 20)->default('regular');      // regular | adjustment | replacement (from the run)
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');
            $table->char('currency', 3);
            $table->text('content');                            // encrypted JSON snapshot
            $table->string('status', 20)->default('issued');    // issued | superseded
            $table->foreignUlid('superseded_by')->nullable()->constrained('payslips')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index(['company_id', 'employee_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
