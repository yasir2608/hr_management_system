<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run approval: an optional second review (per payroll country) and the approval itself, after which the run
     * is locked.
     */
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->boolean('require_second_review')->default(false)->after('input_cutoff_days');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->foreignUlid('second_reviewed_by')->nullable()->after('recalculation')->constrained('users')->nullOnDelete();
            $table->timestamp('second_reviewed_at')->nullable()->after('second_reviewed_by');
            $table->foreignUlid('approved_by')->nullable()->after('second_reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('approval_note', 500)->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('second_reviewed_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['second_reviewed_at', 'approved_at', 'approval_note']);
        });
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropColumn('require_second_review');
        });
    }
};
