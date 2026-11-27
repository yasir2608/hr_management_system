<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoices M.Y HRMS raises to its customers for their subscription, recorded by operations staff. These are
     * M.Y HRMS's own invoices: separate from company payroll and from contractor invoices inside the HRMS.
     * An invoice raised by mistake is voided with a reason, never deleted.
     */
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('company_subscription_id')->nullable()->constrained('company_subscriptions')->nullOnDelete();
            $table->string('reference', 50)->unique();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->date('issued_on');
            $table->date('due_on');
            $table->string('notes', 1000)->nullable();
            $table->string('status', 20)->default('issued'); // issued | void
            $table->string('void_reason', 500)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'issued_on']);
            $table->index(['status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
    }
};
