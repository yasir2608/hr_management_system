<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A new starter's own copy of the checklist, so template edits never change it.
        Schema::create('onboarding_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->foreignUlid('template_id')->nullable()->constrained('onboarding_templates')->nullOnDelete();
            $table->string('title');
            $table->string('description', 1000)->nullable();
            $table->string('owner_type', 20);                                   // hr | manager | employee | payroll | it
            $table->foreignUlid('owner_user_id')->nullable()->constrained('users'); // the person who should do it, when known
            $table->date('due_date')->nullable();
            $table->boolean('is_required')->default(true);
            $table->string('employee_field_group', 30)->nullable();
            $table->string('status', 20)->default('todo');                      // todo | submitted | done | blocked
            $table->string('blocked_reason', 500)->nullable();
            $table->string('review_note', 500)->nullable();                     // HR feedback on employee tasks
            $table->timestamp('completed_at')->nullable();
            $table->foreignUlid('completed_by')->nullable()->constrained('users');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'position']);
            $table->index(['company_id', 'owner_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_tasks');
    }
};
