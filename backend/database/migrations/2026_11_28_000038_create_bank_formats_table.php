<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank file formats a company has set up. Each one uses a format template the product supports
     * (App\Payroll\BankFormats\BankFormatCatalog) plus the company's own details for that format, such as the
     * account salaries are paid from. Those details are encrypted. One format per country can be the default.
     */
    public function up(): void
    {
        Schema::create('bank_formats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->char('country_code', 2);
            $table->string('template', 40);
            $table->string('name', 100);
            $table->text('settings')->nullable(); // encrypted JSON
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'country_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_formats');
    }
};
