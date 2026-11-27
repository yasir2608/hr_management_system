<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contractors are tracked separately from employees and are never paid through payroll.
        Schema::create('contractors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->string('kind', 20);                 // person | supplier
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('address')->nullable();
            $table->foreignUlid('owner_user_id')->nullable()->constrained('users'); // who in the company looks after them
            $table->string('status', 20)->default('active'); // active | inactive
            $table->json('custom_fields')->nullable();  // values for the company's own fields
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('contractor_contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('contractor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // Extra fields each company wants on its contractor records.
        Schema::create('contractor_fields', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->string('key', 50);
            $table->string('label', 100);
            $table->string('type', 20)->default('text'); // text | number | date | select
            $table->json('options')->nullable();          // for select
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_fields');
        Schema::dropIfExists('contractor_contacts');
        Schema::dropIfExists('contractors');
    }
};
