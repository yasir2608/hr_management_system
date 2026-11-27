<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank files created from approved payroll runs. The file is kept (encrypted) exactly as first downloaded, so
     * downloading it again gives the same file. Creating a file does not send money.
     */
    public function up(): void
    {
        Schema::create('bank_exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('payroll_run_id')->constrained('payroll_runs');
            $table->foreignUlid('bank_format_id')->constrained('bank_formats'); // a format with files cannot be deleted
            $table->string('format_name', 100);
            $table->string('template', 40);
            $table->string('file_name');
            $table->string('mime', 60);
            $table->char('currency', 3);
            $table->unsignedInteger('payment_count');
            $table->unsignedBigInteger('total_cents');
            $table->json('excluded')->nullable(); // names and reasons only, no bank details
            $table->longText('content');           // encrypted file
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_exports');
    }
};
