<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company payroll setup, one row per payroll country: currency, pay schedule, period rules and employer identifiers.
     */
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->char('country_code', 2);
            $table->char('currency', 3);
            $table->string('pay_frequency', 20);                         // monthly | semi_monthly | biweekly | four_weekly | weekly
            $table->unsignedTinyInteger('period_start_day')->default(1); // monthly: day the period starts (1-28)
            $table->date('anchor_date')->nullable();                     // weekly-style schedules: first day of a known period
            $table->string('pay_date_rule', 30);                         // last_working_day | fixed_day | period_end | days_after_period_end
            $table->unsignedTinyInteger('pay_day')->nullable();          // fixed_day: day of month
            $table->unsignedTinyInteger('pay_date_offset_days')->nullable(); // days_after_period_end
            $table->unsignedTinyInteger('input_cutoff_days')->default(3);   // inputs close this many days before pay day
            $table->text('employer_details')->nullable();                // encrypted JSON (config/countries.php employer_fields)
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'country_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settings');
    }
};
