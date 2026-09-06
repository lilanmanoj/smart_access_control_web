<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * Development driver: writes the message to the log instead of sending it.
 *
 * The recipient is masked even here. A one-time password in a log file is a
 * one-time password an operator can read, and local habits become production
 * habits.
 */
class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): SmsResult
    {
        $masked = PhoneNumber::tryParse($to)?->masked() ?? 'unknown';

        Log::channel(config('logging.default'))->info('SMS (log driver)', [
            'to' => $masked,
            'length' => strlen($message),
        ]);

        // The body goes only to the debug level, so a production misconfiguration
        // at info level cannot leak a live code.
        Log::debug('SMS body (log driver)', ['to' => $masked, 'message' => $message]);

        return SmsResult::sent('log-'.uniqid());
    }
}
