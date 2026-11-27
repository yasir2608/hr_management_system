<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One request for a decision (leave, attendance correction, invoice, payroll run...).
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->string('type', 50);                 // leave_request | attendance_correction | contractor_invoice | payroll_run | ...
            $table->string('subject_type', 100);
            $table->ulid('subject_id');
            $table->foreignUlid('requested_by')->constrained('users');
            $table->foreignUlid('employee_id')->nullable()->constrained(); // whose request it is, for scope and display
            $table->string('title');
            $table->json('summary')->nullable();         // short facts shown in the inbox (no sensitive values)
            $table->string('status', 20)->default('pending'); // pending | info_requested | approved | declined | cancelled
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
        });

        // The approvers, in order. A step goes to a person, or to anyone holding a role.
        Schema::create('approval_steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('approval_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step_order');
            $table->foreignUlid('approver_user_id')->nullable()->constrained('users');
            $table->string('approver_role', 50)->nullable(); // role key when the step is shared by a role
            $table->string('status', 20)->default('waiting'); // waiting | pending | approved | declined | info_requested
            $table->foreignUlid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('comment', 1000)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'approver_user_id', 'status']);
            $table->index(['company_id', 'approver_role', 'status']);
        });

        // Every action on a request, in order: the decision record.
        Schema::create('approval_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('approval_step_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users');
            $table->string('action', 30); // submitted | approved | declined | info_requested | info_provided | reassigned | cancelled
            $table->string('comment', 1000)->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'approval_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_events');
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_requests');
    }
};
