<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->string('documentable_type', 100);
            $table->ulid('documentable_id');
            $table->string('category', 50);
            $table->string('title');
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64)->nullable(); // SHA-256
            $table->string('visibility', 20)->default('hr'); // employee | hr | payroll
            $table->date('expires_on')->nullable();
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'documentable_type', 'documentable_id']);
            $table->index(['company_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
