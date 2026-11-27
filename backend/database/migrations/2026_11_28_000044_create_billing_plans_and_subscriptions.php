<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual customer billing for M.Y HRMS (managed by operations staff, not by companies):
     * - billing_plans: the plans M.Y HRMS offers
     * - company_subscriptions: each company's agreed plan, price and billing period over time. A new agreement ends the
     *   previous one, so the history is kept. This is separate from payroll and contractor invoices.
     */
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->string('price_basis', 20);          // flat | per_employee
            $table->decimal('price', 12, 2);            // per billing period (per employee when per_employee)
            $table->char('currency', 3);
            $table->string('billing_period', 20);       // monthly | quarterly | annual
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('billing_plan_id')->constrained('billing_plans');
            $table->string('plan_name', 100);           // as agreed, kept if the plan is renamed
            $table->string('price_basis', 20);
            $table->decimal('price', 12, 2);            // agreed price, may differ from the plan's list price
            $table->char('currency', 3);
            $table->string('billing_period', 20);
            $table->unsignedInteger('employee_limit')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('active'); // trial | active
            $table->string('notes', 1000)->nullable();
            $table->string('end_reason', 500)->nullable();
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_subscriptions');
        Schema::dropIfExists('billing_plans');
    }
};
