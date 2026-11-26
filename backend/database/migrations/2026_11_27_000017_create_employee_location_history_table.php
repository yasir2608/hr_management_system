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
        Schema::create('employee_location_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->foreignUlid('location_id')->constrained();
            $table->string('work_arrangement', 20)->default('onsite'); // onsite | remote | hybrid
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('reason')->nullable();
            $table->foreignUlid('changed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->boolean('is_current')->nullable()->storedAs($this->portable('IF(`valid_to` IS NULL, 1, NULL)'));

            // Explicit names: generated ones exceed MySQL's 64-character identifier limit.
            $table->unique(['company_id', 'employee_id', 'valid_from'], 'emp_location_hist_valid_from_unique');
            $table->unique(['company_id', 'employee_id', 'is_current'], 'emp_location_hist_is_current_unique');
            $table->index(['company_id', 'employee_id', 'valid_to'], 'emp_location_hist_valid_to_index');
            $table->index(['company_id', 'location_id', 'valid_to'], 'emp_location_hist_location_valid_to_index');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `employee_location_history` ADD CONSTRAINT `employee_location_history_valid_range_check` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`)');
        }
    }

    // Generated-column SQL is written for MySQL; SQLite (the test database) spells IF() as IIF().
    private function portable(string $expression): string
    {
        return DB::getDriverName() === 'sqlite' ? str_replace('IF(', 'IIF(', $expression) : $expression;
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_location_history');
    }
};
