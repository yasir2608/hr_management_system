<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Employer registration numbers per country attach here later (payroll module).
        Schema::create('company_payroll_countries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->char('country_code', 2); // PK | GB | US
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'country_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_payroll_countries');
    }
};
