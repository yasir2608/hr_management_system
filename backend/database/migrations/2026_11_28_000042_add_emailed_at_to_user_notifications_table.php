<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the email copy of a notification was sent (NULL: not emailed).
     */
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->timestamp('emailed_at')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropColumn('emailed_at');
        });
    }
};
