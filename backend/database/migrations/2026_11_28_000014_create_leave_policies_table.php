<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How much of a leave type people get. Versions are effective-dated, and a policy can
        // apply to a group (by country, employment type, location or department).
        Schema::create('leave_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('leave_type_id')->constrained();
            $table->ulid('series_id');                    // all versions of one policy share this
            $table->string('name');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();     // exclusive; NULL = no end
            $table->decimal('annual_days', 6, 2);
            $table->string('accrual', 20)->default('upfront'); // upfront | monthly (method still to confirm in the PRD)
            $table->decimal('carry_over_days', 6, 2)->default(0);
            $table->json('applies_to')->nullable();       // {work_countries, employment_types, location_ids, department_ids}; empty = everyone
            $table->timestamps();

            $table->index(['company_id', 'leave_type_id', 'effective_from']);
            $table->index(['company_id', 'series_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_policies');
    }
};
