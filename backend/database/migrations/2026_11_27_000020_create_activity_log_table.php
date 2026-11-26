<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: no updated_at, no soft deletes. UPDATE/DELETE blocked by triggers (next migration).
        // company_id is NULL for platform-level events (e.g. M.Y HRMS operations actions).
        Schema::create('activity_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->nullable()->constrained();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users');
            $table->string('action', 100); // e.g. employee.updated, role.assigned
            $table->string('subject_type', 100)->nullable();
            $table->ulid('subject_id')->nullable();
            $table->string('description', 500);
            $table->json('properties')->nullable(); // old/new values, sensitive values masked
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'actor_user_id', 'created_at']);
            $table->index(['company_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
