<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Regular allowances and recurring deductions on an employee's pay, effective-dated.
     * Sensitive like salary: amounts are shown only with payroll.salary.view.
     */
    public function up(): void
    {
        Schema::create('employee_pay_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->string('kind', 20);                  // allowance | deduction
            $table->string('name', 100);
            $table->string('calculation', 20);           // fixed (amount per pay period) | percent (of base pay)
            $table->decimal('amount', 14, 2)->nullable(); // fixed
            $table->decimal('percent', 6, 3)->nullable(); // percent
            $table->boolean('taxable')->default(true);    // allowance: taxed as pay; deduction: true = taken after tax
            $table->date('valid_from');
            $table->date('valid_to')->nullable();         // last day it applies; NULL = until changed
            $table->string('notes', 500)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_pay_items');
    }
};
