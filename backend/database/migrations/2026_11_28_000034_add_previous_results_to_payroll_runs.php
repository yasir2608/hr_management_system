<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keeps the previous calculation of a run when it is recalculated, so the page can show what changed.
     */
    public function up(): void
    {
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->text('previous_results')->nullable()->after('results'); // encrypted JSON, the calculation before the latest one
            $table->boolean('previous_included')->nullable()->after('previous_results');
            $table->boolean('is_new_since_recalculation')->default(false)->after('previous_included');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->timestamp('recalculated_at')->nullable()->after('inputs_gathered_at');
            $table->json('recalculation')->nullable()->after('recalculated_at'); // previous totals and employees no longer in the run
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['recalculated_at', 'recalculation']);
        });
        Schema::table('payroll_run_employees', function (Blueprint $table) {
            $table->dropColumn(['previous_results', 'previous_included', 'is_new_since_recalculation']);
        });
    }
};
