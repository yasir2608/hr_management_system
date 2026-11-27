<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-product notifications: things that need a person's attention, each linking to the page where they act.
     * Messages never hold salary, bank, tax or identity details. A notification that needs a permission is hidden
     * when the person no longer has it, and one that asked for an action is marked resolved once it is done.
     */
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 60);
            $table->string('title', 200);
            $table->string('body', 500)->nullable();
            $table->string('link', 300)->nullable();
            $table->string('permission', 80)->nullable(); // needed to see it, e.g. payroll.approve
            $table->string('subject_type', 60)->nullable();
            $table->ulid('subject_id')->nullable();
            $table->boolean('action_required')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable(); // the action was done (by anyone)
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'user_id', 'read_at']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
