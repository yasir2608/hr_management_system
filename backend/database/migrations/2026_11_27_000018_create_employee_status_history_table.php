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
        Schema::create('employee_status_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->string('status', 20); // invited | onboarding | active | on_leave | ended
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('reason')->nullable();
            $table->foreignUlid('changed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->boolean('is_current')->nullable()->storedAs($this->portable('IF(`valid_to` IS NULL, 1, NULL)'));

            $table->unique(['company_id', 'employee_id', 'valid_from']);
            $table->unique(['company_id', 'employee_id', 'is_current']);
            $table->index(['company_id', 'employee_id', 'valid_to']);
            $table->index(['company_id', 'status', 'valid_to']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `employee_status_history` ADD CONSTRAINT `employee_status_history_valid_range_check` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`)');
        }
    }

    // Generated-column SQL is written for MySQL; SQLite (the test database) spells IF() as IIF().
    private function portable(string $expression): string
    {
        return DB::getDriverName() === 'sqlite' ? str_replace('IF(', 'IIF(', $expression) : $expression;
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_status_history');
    }
};
