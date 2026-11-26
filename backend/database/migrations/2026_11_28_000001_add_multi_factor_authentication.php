<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('require_mfa')->default(false)->after('status'); // every member must verify a code at sign-in
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('mfa_enabled')->default(false)->after('status'); // the user opted in personally
        });

        // One-time sign-in codes sent by email. Only a hash of the code is stored.
        Schema::create('mfa_challenges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_challenges');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('mfa_enabled');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('require_mfa');
        });
    }
};
