<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationSent extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly string $companyName,
        public readonly string $inviterName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return config('app.frontend_url').'/accept-invitation?token='.$this->token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You have been invited to M.Y HRMS')
            ->line("{$this->inviterName} has invited you to join {$this->companyName} on M.Y HRMS.")
            ->action('Accept invitation', $this->url())
            ->line('The invitation expires in 7 days.');
    }
}
