<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payroll groups: employees paid together on one schedule. A group uses its country's payroll settings unless it
     * sets its own schedule. Each employee belongs to at most one group, in their own work country.
     */
    public function up(): void
    {
        Schema::create('payroll_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->char('country_code', 2);
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            // Own schedule; NULL = use the country's payroll settings.
            $table->string('pay_frequency', 20)->nullable();
            $table->unsignedTinyInteger('period_start_day')->nullable();
            $table->date('anchor_date')->nullable();
            $table->string('pay_date_rule', 30)->nullable();
            $table->unsignedTinyInteger('pay_day')->nullable();
            $table->unsignedTinyInteger('pay_date_offset_days')->nullable();
            $table->unsignedTinyInteger('input_cutoff_days')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'country_code']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignUlid('payroll_group_id')->nullable()->after('work_country')->constrained('payroll_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_group_id');
        });
        Schema::dropIfExists('payroll_groups');
    }
};
