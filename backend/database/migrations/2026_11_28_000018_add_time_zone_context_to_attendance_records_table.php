<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The time zone and offset in force when each time was recorded, and the local work
        // date, so records read the same even after a location or company changes time zone.
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('clock_in_timezone', 64)->nullable()->after('clock_in_at');
            $table->smallInteger('clock_in_utc_offset')->nullable()->after('clock_in_timezone'); // minutes
            $table->string('clock_out_timezone', 64)->nullable()->after('clock_out_at');
            $table->smallInteger('clock_out_utc_offset')->nullable()->after('clock_out_timezone');
            $table->date('work_date')->nullable()->after('employee_id'); // local date of the clock-in

            $table->index(['company_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'work_date']);
            $table->dropColumn(['clock_in_timezone', 'clock_in_utc_offset', 'clock_out_timezone', 'clock_out_utc_offset', 'work_date']);
        });
    }
};
