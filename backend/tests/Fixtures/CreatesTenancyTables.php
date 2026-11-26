<?php

namespace Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the minimal tenancy tables in the in-memory test database.
 *
 * The real migrations are not run here: some use MySQL-only features (triggers) that SQLite
 * does not support.
 */
trait CreatesTenancyTables
{
    protected function createTenancyTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('status', 20)->default('active');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->char('country_code', 2);
            $table->string('contact_email')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('company_users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('user_id')->constrained();
            $table->string('status', 20)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('scoped_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->string('name');
            $table->timestamps();
        });
    }
}
