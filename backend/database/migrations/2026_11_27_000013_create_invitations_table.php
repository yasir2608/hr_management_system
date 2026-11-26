<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->string('email');
            $table->char('token_hash', 64)->unique(); // SHA-256 of the emailed token
            $table->foreignUlid('role_id')->constrained();
            $table->string('scope_type', 20)->default('company'); // company | team | location | reporting_line
            $table->ulid('scope_id')->nullable();
            $table->foreignUlid('employee_id')->nullable()->constrained();
            $table->foreignUlid('invited_by')->nullable()->constrained('users');
            $table->string('status', 20)->default('pending'); // pending | accepted | revoked | expired
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('send_count')->default(1);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUlid('accepted_user_id')->nullable()->constrained('users');
            $table->timestamps();

            // At most one pending invitation per email per company.
            $table->string('pending_email')->nullable()
                ->storedAs("IF(`status` = 'pending', `email`, NULL)");

            $table->unique(['company_id', 'pending_email']);
            $table->index(['company_id', 'email']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
