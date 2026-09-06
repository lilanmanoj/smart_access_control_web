<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OtpStatus;
use App\Models\OtpRequest;
use App\Notifications\OtpCodeNotification;
use App\Services\Sms\SmsGateway;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Fan a one-time password out to SMS and e-mail.
 *
 * The two channels are independent by design. E-mail is not a fallback
 * triggered by an SMS failure: the panel's own SIM800L reports its status only
 * locally, so the backend can never know whether a device-sent message
 * actually went out, and a person standing at a door should not be waiting on
 * that question.
 *
 * Queued so a slow gateway never stalls the panel — the user is watching the
 * screen for it to advance.
 *
 * The plaintext code travels in the job payload. It lives in Redis for the
 * seconds until the job runs and for the OTP's own TTL at most; the database
 * only ever holds a keyed hash.
 */
class DeliverOtp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        private readonly int $otpRequestId,
        private readonly string $code,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(SmsGateway $sms, TenantContext $tenants): void
    {
        // Jobs start with no tenant bound; the request resolves its own.
        $request = OtpRequest::withoutGlobalScopes()
            ->with(['member', 'tenant', 'device'])
            ->find($this->otpRequestId);

        if ($request === null || ! $request->status->isOpen()) {
            return;
        }

        $tenants->forTenant($request->tenant, function () use ($request, $sms): void {
            $delivery = $request->delivery ?? [];

            $delivery['sms'] = $sms->send($request->phone, $this->smsBody($request))->toArray();
            $delivery['email'] = $this->deliverEmail($request);

            $anyDelivered = ($delivery['sms']['status'] ?? null) === 'sent'
                || ($delivery['email']['status'] ?? null) === 'sent';

            $request->forceFill([
                'delivery' => $delivery,
                'status' => $anyDelivered ? OtpStatus::Delivered : $request->status,
            ])->save();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function deliverEmail(OtpRequest $request): array
    {
        $email = $request->member?->email;

        if ($email === null) {
            return ['status' => 'skipped', 'reason' => 'no_email_on_record'];
        }

        try {
            Notification::route('mail', $email)->notify(
                new OtpCodeNotification($this->code, $request)
            );

            return ['status' => 'sent', 'at' => now()->toIso8601String()];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'at' => now()->toIso8601String(),
            ];
        }
    }

    private function smsBody(OtpRequest $request): string
    {
        $minutes = max(1, (int) ceil($request->expires_at->diffInSeconds(now()) / 60));
        $name = $request->device->name;

        return "{$this->code} is your access code for {$name}. It expires in {$minutes} minute(s). Do not share it.";
    }
}
