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
        Schema::create('employee_job_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->string('job_title');
            $table->foreignUlid('department_id')->nullable()->constrained();
            $table->foreignUlid('team_id')->nullable()->constrained();
            $table->string('employment_type', 20)->nullable(); // full_time | part_time | fixed_term | intern
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('reason')->nullable();
            $table->foreignUlid('changed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->boolean('is_current')->nullable()->storedAs('IF(`valid_to` IS NULL, 1, NULL)');

            $table->unique(['company_id', 'employee_id', 'valid_from']);
            $table->unique(['company_id', 'employee_id', 'is_current']);
            $table->index(['company_id', 'employee_id', 'valid_to']);
        });

        DB::statement('ALTER TABLE `employee_job_history` ADD CONSTRAINT `employee_job_history_valid_range_check` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_job_history');
    }
};
