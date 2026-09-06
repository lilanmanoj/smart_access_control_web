<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $invitedBy,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The SPA owns this route and exchanges the token for a password.
        $url = rtrim((string) config('app.url'), '/').
            '/accept-invitation?token='.$this->token.
            '&email='.urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('You have been invited to '.config('app.name'))
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->invitedBy} has invited you to manage access control at ".config('app.name').'.')
            ->action('Set your password', $url)
            ->line('This link expires in 60 minutes.')
            ->line('If you were not expecting this invitation, you can ignore it.');
    }
}
