<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personal and current employment details that sit on the employee record itself.
        Schema::table('employees', function (Blueprint $table) {
            $table->string('gender', 30)->nullable()->after('date_of_birth');
            $table->char('nationality', 2)->nullable()->after('gender');
            $table->string('address_line1')->nullable()->after('phone');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('city', 100)->nullable()->after('address_line2');
            $table->string('region', 100)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('region');
            $table->char('address_country', 2)->nullable()->after('postal_code');
            $table->string('emergency_contact_name')->nullable()->after('address_country');
            $table->string('emergency_contact_relationship', 50)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_phone', 30)->nullable()->after('emergency_contact_relationship');
            $table->char('work_country', 2)->nullable()->after('current_location_id'); // country of employment; decides payroll rules
            $table->string('current_employment_type', 20)->nullable()->after('current_job_title'); // full_time | part_time | fixed_term | intern
            $table->string('current_work_arrangement', 20)->nullable()->after('current_location_id'); // onsite | remote | hybrid
        });

        // Pay, effective-dated. Sensitive: visible only with payroll.salary.view.
        Schema::create('employee_compensation_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->string('pay_basis', 20);     // salary | hourly
            $table->decimal('amount', 14, 2);    // per `frequency`
            $table->char('currency', 3);
            $table->string('frequency', 20);     // annual | monthly | hourly
            $table->decimal('hours_per_week', 5, 2)->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('reason')->nullable();
            $table->foreignUlid('changed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->boolean('is_current')->nullable()->storedAs($this->portable('IF(`valid_to` IS NULL, 1, NULL)'));

            $table->unique(['company_id', 'employee_id', 'valid_from'], 'emp_comp_hist_valid_from_unique');
            $table->unique(['company_id', 'employee_id', 'is_current'], 'emp_comp_hist_is_current_unique');
        });

        // Country-specific identifiers (CNIC, NI number, SSN...). Values are encrypted at rest.
        Schema::create('employee_tax_details', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->char('country_code', 2);
            $table->text('details'); // encrypted JSON of the fields in config/countries.php
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'country_code']);
        });

        // Bank details for salary payments. Account numbers are encrypted at rest.
        Schema::create('employee_bank_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('employee_id')->constrained();
            $table->string('account_holder');
            $table->string('bank_name')->nullable();
            $table->text('account_number');        // encrypted
            $table->text('bank_code')->nullable(); // encrypted: sort code / routing number / IBAN
            $table->char('account_last4', 4);      // for masked display without decrypting
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'employee_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `employee_compensation_history` ADD CONSTRAINT `employee_compensation_history_valid_range_check` CHECK (`valid_to` IS NULL OR `valid_to` > `valid_from`)');
        }
    }

    // Generated-column SQL is written for MySQL; SQLite (the test database) spells IF() as IIF().
    private function portable(string $expression): string
    {
        return DB::getDriverName() === 'sqlite' ? str_replace('IF(', 'IIF(', $expression) : $expression;
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_bank_accounts');
        Schema::dropIfExists('employee_tax_details');
        Schema::dropIfExists('employee_compensation_history');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'gender', 'nationality', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'address_country',
                'emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone',
                'work_country', 'current_employment_type', 'current_work_arrangement',
            ]);
        });
    }
};
