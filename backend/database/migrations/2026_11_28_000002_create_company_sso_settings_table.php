<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional per-company single sign-on. Not every customer has it in release 1.
        Schema::create('company_sso_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->unique()->constrained();
            $table->string('protocol', 10)->default('oidc'); // oidc | saml
            $table->string('display_name', 100);              // e.g. "Northstar Google Workspace"
            $table->string('email_domain')->unique();         // people with this domain are offered SSO
            $table->string('login_url', 500);                 // identity provider sign-in address
            $table->string('client_id')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_enforced')->default(false);   // when on, password sign-in is refused
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_sso_settings');
    }
};
