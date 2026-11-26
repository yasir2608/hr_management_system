<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rows are never deleted: revoking sets revoked_at / revoked_by, so access history is kept.
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('company_user_id')->constrained();
            $table->foreignUlid('role_id')->constrained();
            $table->string('scope_type', 20)->default('company'); // company | team | location | reporting_line
            $table->ulid('scope_id')->nullable(); // teams.id | locations.id | employees.id (the manager)
            $table->boolean('include_indirect')->default(false); // reporting_line: include indirect reports
            $table->foreignUlid('assigned_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUlid('revoked_by')->nullable()->constrained('users');
            $table->timestamps();

            // At most one active assignment per member + role + scope.
            $table->string('active_key', 100)->nullable()->storedAs($this->portable("IF(`revoked_at` IS NULL, CONCAT_WS('|', `company_user_id`, `role_id`, `scope_type`, IFNULL(`scope_id`, '-')), NULL)"));

            $table->unique(['company_id', 'active_key']);
            $table->index(['company_id', 'company_user_id', 'revoked_at']);
            $table->index(['company_id', 'scope_type', 'scope_id']);
        });
    }

    // Generated-column SQL is written for MySQL; SQLite (the test database) spells IF() as IIF().
    private function portable(string $expression): string
    {
        return DB::getDriverName() === 'sqlite' ? str_replace('IF(', 'IIF(', $expression) : $expression;
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
