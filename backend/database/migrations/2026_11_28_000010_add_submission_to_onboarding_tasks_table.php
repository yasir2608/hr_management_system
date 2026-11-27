<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_tasks', function (Blueprint $table) {
            // Details the new starter entered, waiting for review. Encrypted; cleared once applied.
            $table->text('submission')->nullable()->after('employee_field_group');
            $table->timestamp('submitted_at')->nullable()->after('submission');
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_tasks', function (Blueprint $table) {
            $table->dropColumn(['submission', 'submitted_at']);
        });
    }
};
