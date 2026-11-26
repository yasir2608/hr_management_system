<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Time-limited, justified access for M.Y HRMS support staff to one company. Never deleted.
        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('user_id')->constrained(); // the support/ops staff member
            $table->string('reason', 500);
            $table->string('ticket_reference', 100)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('revoked_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'expires_at']);
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_grants');
    }
};
