<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company-specific onboarding checklists. One is the default for new starters.
        Schema::create('onboarding_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::create('onboarding_template_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('template_id')->constrained('onboarding_templates')->cascadeOnDelete();
            $table->string('title');
            $table->string('description', 1000)->nullable();
            $table->string('owner_type', 20);              // hr | manager | employee | payroll | it
            $table->smallInteger('due_offset_days')->default(0); // days after the start date (negative = before)
            $table->boolean('is_required')->default(true);  // required tasks gate activation
            $table->string('employee_field_group', 30)->nullable(); // employee tasks: which details they fill in (personal | emergency | bank | tax)
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'template_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_template_tasks');
        Schema::dropIfExists('onboarding_templates');
    }
};
