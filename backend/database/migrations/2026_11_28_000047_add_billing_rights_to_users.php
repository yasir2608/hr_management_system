<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which M.Y HRMS operations staff may change customer billing (plans, agreements, invoices, payment status).
     * Operations staff who exist when this runs keep the access they had; new staff start without it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_manage_billing')->default(false)->after('platform_role');
        });

        DB::table('users')->where('platform_role', 'ops')->update(['can_manage_billing' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_manage_billing');
        });
    }
};
