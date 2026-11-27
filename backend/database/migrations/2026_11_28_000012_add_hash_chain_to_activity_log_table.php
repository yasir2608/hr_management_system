<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each entry stores a hash of its contents plus the previous entry's hash (per company),
        // so changing or removing any past entry breaks the chain and is detectable.
        Schema::table('activity_log', function (Blueprint $table) {
            $table->char('previous_hash', 64)->nullable()->after('user_agent');
            $table->char('hash', 64)->nullable()->after('previous_hash');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn(['previous_hash', 'hash']);
        });
    }
};
