<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notification controls and reminders:
     * - company settings: email and reminders on or off per category, and when reminders are sent
     * - personal preferences: categories a person does not want emails for
     * - reminders sent per notification, so nobody is reminded too often or after the action is done
     */
    public function up(): void
    {
        Schema::create('company_notification_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->unique()->constrained();
            $table->json('categories')->nullable(); // {category: {email: bool, reminders: bool}}
            $table->unsignedTinyInteger('reminder_after_days')->default(2);
            $table->unsignedTinyInteger('reminder_repeat_days')->default(2);
            $table->unsignedTinyInteger('max_reminders')->default(3);
            $table->timestamps();
        });

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->json('email_off')->nullable(); // categories without email for this person
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        Schema::table('user_notifications', function (Blueprint $table) {
            $table->unsignedTinyInteger('reminder_count')->default(0)->after('emailed_at');
            $table->timestamp('last_reminded_at')->nullable()->after('reminder_count');
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropColumn(['reminder_count', 'last_reminded_at']);
        });
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('company_notification_settings');
    }
};
