<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SignInCode extends Notification
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The code is in the body only, never the subject, so it does not show in previews.
        return (new MailMessage)
            ->subject('Your M.Y HRMS sign-in code')
            ->line("Your sign-in code is: {$this->code}")
            ->line('It expires in 10 minutes. If you did not try to sign in, reset your password.');
    }
}
