<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Sends nothing, reports failure honestly.
 *
 * For deployments where the panel's own modem is the only SMS path, so the
 * delivery log shows "not attempted server-side" rather than a false success.
 */
class NullSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): SmsResult
    {
        return SmsResult::failed('No server-side SMS gateway is configured.');
    }
}
