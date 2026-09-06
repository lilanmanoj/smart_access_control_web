<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OtpRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly OtpRequest $request,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $device = $this->request->device;
        $minutes = max(1, (int) ceil($this->request->expires_at->diffInSeconds(now()) / 60));

        return (new MailMessage)
            ->subject("Your access code for {$device->name}")
            ->greeting('Your access code')
            // The code is the whole message; nothing should compete with it.
            ->line("**{$this->code}**")
            ->line("Enter this on the keypad at {$device->name}".
                ($device->location ? " ({$device->location})" : '').'.')
            ->line("It expires in {$minutes} minute(s) and can only be used once.")
            ->line('If you did not request this, someone entered your phone number at that door — tell your administrator.');
    }
}
