<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contractor payments are made outside the system (bank transfer, card, ...) and only recorded here. They never enter payroll.
     */
    public function up(): void
    {
        Schema::table('contractor_invoices', function (Blueprint $table) {
            $table->date('paid_on')->nullable()->after('review_reason');
            $table->string('payment_reference', 100)->nullable()->after('paid_on');
            $table->string('payment_method', 30)->nullable()->after('payment_reference');
            $table->text('payment_note')->nullable()->after('payment_method');
            $table->foreignUlid('payment_recorded_by')->nullable()->after('payment_note')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contractor_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_recorded_by');
            $table->dropColumn(['paid_on', 'payment_reference', 'payment_method', 'payment_note']);
        });
    }
};
