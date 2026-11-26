<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->unique()->constrained();
            $table->string('timezone', 64);                       // IANA, e.g. Asia/Karachi
            $table->char('currency_code', 3);                     // ISO 4217
            $table->json('work_days');                            // ISO weekdays, e.g. [1,2,3,4,5]
            $table->unsignedTinyInteger('week_start_day')->default(1); // 1 = Monday
            $table->string('date_format', 20)->nullable();
            $table->string('locale', 10)->default('en');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
