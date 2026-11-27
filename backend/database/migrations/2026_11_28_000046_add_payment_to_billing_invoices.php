<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment status for M.Y HRMS's own subscription invoices. Operations staff mark an invoice paid once payment is
     * confirmed through the customer's chosen method; the product never collects the money itself.
     * Status becomes issued | paid | void.
     */
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->date('paid_on')->nullable()->after('voided_at');
            $table->string('payment_method', 30)->nullable()->after('paid_on');
            $table->string('payment_reference', 100)->nullable()->after('payment_method');
            $table->string('payment_note', 500)->nullable()->after('payment_reference');
            $table->foreignUlid('payment_recorded_by')->nullable()->after('payment_note')->constrained('users')->nullOnDelete();
            $table->timestamp('payment_recorded_at')->nullable()->after('payment_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_recorded_by');
            $table->dropColumn(['paid_on', 'payment_method', 'payment_reference', 'payment_note', 'payment_recorded_at']);
        });
    }
};
