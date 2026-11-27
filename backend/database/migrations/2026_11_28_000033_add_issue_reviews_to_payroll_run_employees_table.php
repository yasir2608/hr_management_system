<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Warnings a payroll user has looked at and accepted, keyed by issue: who, when and why.
     * Blocking issues cannot be marked reviewed; they must be fixed.
     */
    public function up(): void
    {
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->json('reviewed_issues')->nullable()->after('results');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->dropColumn('reviewed_issues');
        });
    }
};
