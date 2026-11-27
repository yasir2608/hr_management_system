<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the company records after uploading a bank file: whether the bank paid it, when, and the bank's reference.
     * This is a record only; M.Y HRMS never sends or confirms payments itself.
     */
    public function up(): void
    {
        Schema::table('bank_exports', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('not_recorded')->after('last_downloaded_at'); // not_recorded | paid | failed
            $table->date('paid_on')->nullable()->after('payment_status');
            $table->string('payment_reference', 100)->nullable()->after('paid_on');
            $table->string('payment_note', 500)->nullable()->after('payment_reference');
            $table->foreignUlid('payment_recorded_by')->nullable()->after('payment_note')->constrained('users')->nullOnDelete();
            $table->timestamp('payment_recorded_at')->nullable()->after('payment_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('bank_exports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_recorded_by');
            $table->dropColumn(['payment_status', 'paid_on', 'payment_reference', 'payment_note', 'payment_recorded_at']);
        });
    }
};
