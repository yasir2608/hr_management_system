<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email copy of an in-product notification. Subjects and previews carry only the notification's short title,
 * never salary, bank, tax or identity details; anything else is seen after signing in.
 */
class AttentionNeeded extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly ?string $link,
        public readonly string $companyName,
        public readonly bool $actionRequired,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function subject(): string
    {
        return self::safe("{$this->companyName}: {$this->title}");
    }

    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').($this->link ?? '/notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello')
            ->line(self::safe($this->title))
            ->line($this->actionRequired ? 'This needs your attention in M.Y HRMS.' : 'There is an update for you in M.Y HRMS.')
            ->action('Open M.Y HRMS', $this->url())
            ->line('For your privacy, details are only shown after you sign in.');
    }

    /**
     * A last guard for subjects and previews: long runs of digits (account numbers, identifiers) are masked.
     */
    public static function safe(string $text): string
    {
        return (string) preg_replace_callback('/\d[\d\s-]{5,}\d/', fn (array $match): string => str_repeat('•', 4).substr(preg_replace('/\D/', '', $match[0]), -2), $text);
    }
}
