<?php

namespace Tests\Feature;

use App\Approvals\ApprovalEngine;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AttentionNeeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithCompany;
use Tests\Fixtures\ApprovableThing;
use Tests\TestCase;

class RemindersAndPreferencesTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $requester;

    private User $manager;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-03-02 09:00:00');
        Notification::fake();
        ApprovableThing::createTable();
        $this->setUpCompany();
        $this->requester = $this->memberWithRole(Role::EMPLOYEE);
        $this->manager = $this->memberWithRole(Role::MANAGER);
        $this->employee = $this->asCompany(function (): Employee {
            $boss = Employee::factory()->create(['user_id' => $this->manager->id]);

            return Employee::factory()->create(['user_id' => $this->requester->id, 'current_manager_id' => $boss->id]);
        });
    }

    private function submit(): string
    {
        request()->setUserResolver(fn () => $this->requester);
        $id = $this->asCompany(fn () => app(ApprovalEngine::class)->submit(ApprovableThing::query()->create(), 'leave_request', $this->requester, $this->employee, 'Annual leave for Ava Patel', [], ['manager']))->id;
        request()->setUserResolver(fn () => null);

        return $id;
    }

    private function remind(): void
    {
        $this->artisan('notifications:remind')->assertSuccessful();
    }

    private function managerNotification(): UserNotification
    {
        return $this->asCompany(fn () => UserNotification::query()->where('user_id', $this->manager->id)->where('kind', 'approval.waiting')->firstOrFail());
    }

    private function reminderEmails(User $user): int
    {
        return Notification::sent($user, AttentionNeeded::class)->filter(fn (AttentionNeeded $mail): bool => str_starts_with($mail->title, 'Reminder: '))->count();
    }

    public function test_reminders_follow_the_schedule_and_stop_at_the_limit(): void
    {
        $this->submit();
        $this->inCompany($this->manager)->postJson('/api/notifications/read-all')->assertOk();

        $this->travel(1)->days();
        $this->remind();
        $this->assertSame(0, $this->managerNotification()->reminder_count, 'Too early: reminders start after 2 days.');

        $this->travel(1)->days();
        $this->remind();
        $note = $this->managerNotification();
        $this->assertSame(1, $note->reminder_count);
        $this->assertNull($note->read_at, 'A reminder makes it unread again.');
        $this->assertSame(1, $this->reminderEmails($this->manager));

        $this->remind();
        $this->assertSame(1, $this->managerNotification()->reminder_count, 'Not again until the repeat interval passes.');

        foreach (range(1, 4) as $ignored) {
            $this->travel(2)->days();
            $this->remind();
        }
        $this->assertSame(3, $this->managerNotification()->reminder_count, 'At most three reminders.');
    }

    public function test_no_reminder_after_the_action_is_done_even_if_the_notification_was_missed(): void
    {
        $id = $this->submit();
        $this->inCompany($this->manager)->postJson("/api/approvals/{$id}/decide", ['decision' => 'approve'])->assertOk();
        // Simulate a notification that was not resolved when the action happened.
        $this->asCompany(fn () => UserNotification::query()->where('kind', 'approval.waiting')->update(['resolved_at' => null]));

        $this->travel(3)->days();
        $this->remind();

        $note = $this->managerNotification();
        $this->assertSame(0, $note->reminder_count);
        $this->assertNotNull($note->resolved_at);
        $this->assertSame(0, $this->reminderEmails($this->manager));
    }

    public function test_company_settings_turn_email_and_reminders_off_per_category(): void
    {
        $this->inCompany($this->manager)->putJson('/api/company/notification-settings', [])->assertForbidden();
        $settings = $this->inCompany()->getJson('/api/company/notification-settings')->assertOk()->json();
        $this->assertSame(['approvals', 'payroll', 'payslips', 'invoices', 'onboarding'], array_column($settings['categories'], 'key'));
        $this->assertFalse(collect($settings['categories'])->firstWhere('key', 'payslips')['reminders'], 'Payslips are not reminded.');

        $categories = collect($settings['categories'])->mapWithKeys(fn (array $category): array => [$category['key'] => ['email' => true, 'reminders' => true]])->all();
        $categories['approvals'] = ['email' => false, 'reminders' => false];
        $this->inCompany()->putJson('/api/company/notification-settings', ['categories' => $categories, 'reminder_after_days' => 1, 'reminder_repeat_days' => 1, 'max_reminders' => 2])
            ->assertOk()->assertJsonPath('categories.0.email', false)->assertJsonPath('reminder_after_days', 1);
        $this->assertDatabaseHas('activity_log', ['action' => 'company.notification_settings_updated']);

        $this->submit();
        Notification::assertNotSentTo($this->manager, AttentionNeeded::class);
        $this->assertSame(1, $this->inCompany($this->manager)->getJson('/api/notifications/count')->json('unread_count'), 'The in-product notification is always sent.');

        $this->travel(3)->days();
        $this->remind();
        $this->assertSame(0, $this->managerNotification()->reminder_count);
    }

    public function test_people_choose_which_categories_they_get_emails_for(): void
    {
        $mine = $this->inCompany($this->manager)->getJson('/api/me/notification-preferences')->assertOk()->json('categories');
        $this->assertTrue(collect($mine)->firstWhere('key', 'approvals')['email']);

        $this->inCompany($this->manager)->putJson('/api/me/notification-preferences', ['email_off' => ['approvals']])->assertOk()
            ->assertJsonPath('categories.0.email', false);
        $this->inCompany($this->manager)->putJson('/api/me/notification-preferences', ['email_off' => ['nonsense']])->assertJsonValidationErrors('email_off.0');

        $this->submit();
        Notification::assertNotSentTo($this->manager, AttentionNeeded::class);
        $this->assertSame(1, $this->inCompany($this->manager)->getJson('/api/notifications/count')->json('unread_count'));
    }
}
