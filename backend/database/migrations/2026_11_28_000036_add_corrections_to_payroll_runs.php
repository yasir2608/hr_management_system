<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corrections after approval, keeping the approved run unchanged:
     * - adjustment: a run for the same period with only the affected employees, paying or recovering the difference
     * - replacement: a full new run for the period; when approved, the original is marked reversed (and kept)
     */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('kind', 20)->default('regular')->after('payroll_group_id'); // regular | adjustment | replacement
            $table->foreignUlid('corrects_run_id')->nullable()->after('kind')->constrained('payroll_runs')->nullOnDelete();
            $table->string('correction_reason', 500)->nullable()->after('corrects_run_id');
            $table->json('limited_to')->nullable()->after('correction_reason'); // adjustment: the employees it covers
            $table->timestamp('reversed_at')->nullable()->after('approval_note');
            $table->foreignUlid('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();

            $table->index(['company_id', 'corrects_run_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrects_run_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['kind', 'correction_reason', 'limited_to', 'reversed_at']);
        });
    }
};
