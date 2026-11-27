<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional: limit where people can clock in from, with the company's own explanation.
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->string('attendance_restriction', 20)->default('none')->after('attendance_enabled'); // none | network | location
            $table->json('attendance_allowed_networks')->nullable()->after('attendance_restriction');   // IPs or CIDR ranges
            $table->string('attendance_restriction_message', 500)->nullable()->after('attendance_allowed_networks');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->decimal('latitude', 9, 6)->nullable()->after('timezone');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
            $table->unsignedInteger('clock_radius_m')->nullable()->after('longitude'); // how close people must be to clock in
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'clock_radius_m']);
        });

        Schema::table('company_preferences', function (Blueprint $table) {
            $table->dropColumn(['attendance_restriction', 'attendance_allowed_networks', 'attendance_restriction_message']);
        });
    }
};
