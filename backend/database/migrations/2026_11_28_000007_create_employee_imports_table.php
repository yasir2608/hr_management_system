<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A spreadsheet of employees being imported. Rows can hold sensitive data, so they are encrypted.
        Schema::create('employee_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('uploaded_by')->constrained('users');
            $table->string('original_name');
            $table->string('status', 20)->default('uploaded'); // uploaded | validated | completed | cancelled
            $table->json('columns');               // detected header => column key (or null when unknown)
            $table->longText('rows');              // encrypted JSON: [{row, values, errors}]
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('ready_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_imports');
    }
};
