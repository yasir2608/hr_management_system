<?php

namespace Tests\Feature;

use App\Approvals\ApprovalEngine;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AttentionNeeded;
use App\Support\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithCompany;
use Tests\Fixtures\ApprovableThing;
use Tests\TestCase;

class EmailNotificationsTest extends TestCase
{
    use InteractsWithCompany, RefreshDatabase;

    private User $requester;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'http://localhost:3000']);
        ApprovableThing::createTable();
        $this->setUpCompany(['name' => 'Northwind Ltd']);
        $this->requester = $this->memberWithRole(Role::EMPLOYEE);
        $this->manager = $this->memberWithRole(Role::MANAGER);
    }

    public function test_each_notification_is_emailed_with_a_safe_subject_and_a_link_but_no_details(): void
    {
        Notification::fake();
        $employee = $this->asCompany(function (): Employee {
            $boss = Employee::factory()->create(['user_id' => $this->manager->id]);

            return Employee::factory()->create(['user_id' => $this->requester->id, 'current_manager_id' => $boss->id]);
        });
        request()->setUserResolver(fn () => $this->requester);
        $approval = $this->asCompany(fn () => app(ApprovalEngine::class)->submit(ApprovableThing::query()->create(), 'leave_request', $this->requester, $employee, 'Annual leave for Ava Patel', ['reason' => 'Family wedding'], ['manager']));

        Notification::assertSentTo($this->manager, AttentionNeeded::class, function (AttentionNeeded $mail) use ($approval): bool {
            $message = $mail->toMail($this->manager);
            $text = implode(' ', [$message->subject, ...$message->introLines, ...$message->outroLines]);

            return $message->subject === 'Northwind Ltd: Approval needed: Annual leave for Ava Patel'
                && $message->actionUrl === 'http://localhost:3000/approvals'
                && ! str_contains($text, 'Family wedding')
                && str_contains($text, 'details are only shown after you sign in')
                && $approval->id !== null;
        });
        Notification::assertNotSentTo($this->requester, AttentionNeeded::class);
        $this->assertNotNull($this->asCompany(fn () => UserNotification::query()->where('user_id', $this->manager->id)->value('emailed_at')));
    }

    public function test_free_text_bodies_stay_out_of_the_email_and_long_numbers_are_masked(): void
    {
        Notification::fake();
        request()->setUserResolver(fn () => null);

        $this->asCompany(fn () => Notifier::send([$this->manager->id], 'approval.declined', 'Declined: account 12345678 change', ['body' => 'Reason: wrong sort code 20-00-00', 'link' => '/leave']));

        Notification::assertSentTo($this->manager, AttentionNeeded::class, function (AttentionNeeded $mail): bool {
            $message = $mail->toMail($this->manager);
            $text = implode(' ', [$message->subject, ...$message->introLines]);

            return $message->subject === 'Northwind Ltd: Declined: account ••••78 change'
                && ! str_contains($text, '12345678') && ! str_contains($text, 'sort code')
                && $message->actionUrl === 'http://localhost:3000/leave';
        });
    }

    public function test_the_email_is_rendered_with_the_subject_recipient_and_link(): void
    {
        config(['mail.default' => 'array']);
        request()->setUserResolver(fn () => null);

        $this->asCompany(fn () => Notifier::send([$this->manager->id], 'payslip.issued', 'Your payslip for March 2026 is ready', ['link' => '/me/payslips/01ABC']));

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $email = $sent[0]->getOriginalMessage();
        $this->assertSame('Northwind Ltd: Your payslip for March 2026 is ready', $email->getSubject());
        $this->assertSame($this->manager->email, $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('http://localhost:3000/me/payslips/01ABC', $email->getHtmlBody());
    }

    public function test_people_who_are_not_active_members_get_neither_notification_nor_email(): void
    {
        Notification::fake();
        $outsider = User::factory()->create();
        request()->setUserResolver(fn () => null);

        $this->asCompany(fn () => Notifier::send([$outsider->id], 'general', 'Hello'));

        Notification::assertNothingSent();
    }
}
