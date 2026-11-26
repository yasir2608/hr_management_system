<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sensitive data (tax identifiers, bank accounts, pay) is NOT stored here.
        // It attaches later via separate encrypted, payroll-permission tables
        // referencing employees.id (e.g. employee_tax_identifiers, employee_bank_accounts).
        Schema::create('employees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('user_id')->nullable()->constrained();
            $table->string('employee_number', 50);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('preferred_name', 100)->nullable();
            $table->string('work_email')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // Current values, denormalised from the *_history tables.
            // Written in the same transaction as the history row; history is the source of truth.
            $table->string('current_job_title')->nullable();
            $table->foreignUlid('current_department_id')->nullable()->constrained('departments');
            $table->foreignUlid('current_team_id')->nullable()->constrained('teams');
            $table->foreignUlid('current_manager_id')->nullable()->constrained('employees');
            $table->foreignUlid('current_location_id')->nullable()->constrained('locations');
            $table->string('current_status', 20)->default('onboarding'); // invited | onboarding | active | on_leave | ended

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'employee_number']);
            $table->unique(['company_id', 'user_id']);
            $table->index(['company_id', 'current_status']);
            $table->index(['company_id', 'last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
