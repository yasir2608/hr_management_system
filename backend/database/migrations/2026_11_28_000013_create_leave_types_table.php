<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The kinds of leave a company offers (annual, sick, parental, unpaid...).
        Schema::create('leave_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->string('name', 100);
            $table->string('code', 20);
            $table->string('color', 7)->default('#315ee8');
            $table->boolean('is_paid')->default(true);
            $table->boolean('uses_balance')->default(true);   // false = no allowance tracked (e.g. unpaid)
            $table->string('requestable_by', 20)->default('all'); // all | hr_only (HR records it for the employee)
            $table->string('approval', 30)->default('manager');   // none | manager | hr | manager_then_hr
            $table->boolean('reason_required')->default(false);
            $table->unsignedTinyInteger('attachment_after_days')->nullable(); // e.g. sick note after 7 days
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
