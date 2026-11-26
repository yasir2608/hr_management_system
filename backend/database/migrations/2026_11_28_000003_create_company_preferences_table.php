<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Basic leave and attendance preferences. The whole step can be skipped and set later.
        Schema::create('company_preferences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->unique()->constrained();
            $table->unsignedTinyInteger('leave_year_start_month')->default(1); // 1 = January
            $table->boolean('half_day_leave_allowed')->default(true);
            $table->string('insufficient_balance_rule', 20)->default('block'); // block | allow_unpaid | exception
            $table->boolean('attendance_enabled')->default(false);
            $table->decimal('standard_hours_per_day', 4, 2)->default(8);
            $table->timestamp('skipped_at')->nullable();   // owner chose "set up later"
            $table->timestamp('confirmed_at')->nullable(); // owner saved the preferences
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_preferences');
    }
};
