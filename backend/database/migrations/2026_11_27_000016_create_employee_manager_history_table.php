<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // valid_from inclusive, valid_to exclusive, NULL = current. Overlaps are prevented by the application.
        Schema::create('employee_manager_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->foreignUlid('manager_employee_id')->constrained('employees');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('reason')->nullable();
            $table->foreignUlid('changed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->boolean('is_current')->nullable()->storedAs('IF(`valid_to` IS NULL, 1, NULL)');

            // Explicit names: generated ones exceed MySQL's 64-character identifier limit.
            $table->unique(['company_id', 'employee_id', 'valid_from'], 'emp_manager_hist_valid_from_unique');
            $table->unique(['company_id', 'employee_id', 'is_current'], 'emp_manager_hist_is_current_unique');
            $table->index(['company_id', 'employee_id', 'valid_to'], 'emp_manager_hist_valid_to_index');
            $table->index(['company_id', 'manager_employee_id', 'valid_to'], 'emp_manager_hist_manager_valid_to_index'); // reporting-line lookups
        });

        DB::statement('ALTER TABLE `employee_manager_history` ADD CONSTRAINT `employee_manager_history_valid_range_check` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`)');
        DB::statement('ALTER TABLE `employee_manager_history` ADD CONSTRAINT `employee_manager_history_not_self_check` CHECK (`manager_employee_id` <> `employee_id`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_manager_history');
    }
};
