<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global catalogue, seeded by the application (not tenant data).
        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 100)->unique(); // e.g. employees.view, payroll.bank.view
            $table->string('group', 50);
            $table->string('description')->nullable();
            $table->boolean('is_sensitive')->default(false); // salary, tax, bank, payroll exports
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
