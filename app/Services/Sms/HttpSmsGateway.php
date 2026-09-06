<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A generic HTTP SMS gateway.
 *
 * Deliberately provider-agnostic: it posts {to, from, text} with a bearer
 * token, which covers most regional gateways. Swap this class out for a
 * provider SDK when the deployment picks one.
 */
class HttpSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): SmsResult
    {
        $endpoint = config('access.sms.http.endpoint');

        if (! $endpoint) {
            return SmsResult::failed('No SMS endpoint configured.');
        }

        try {
            $response = Http::asJson()
                ->withToken((string) config('access.sms.http.token'))
                ->timeout((int) config('access.sms.http.timeout', 10))
                ->retry(2, 200)
                ->post($endpoint, [
                    'to' => $to,
                    'from' => config('access.sms.http.sender'),
                    'text' => $message,
                ]);

            if ($response->successful()) {
                return SmsResult::sent($response->json('id') ?? $response->json('message_id'));
            }

            // The body may echo the message back; only the status is recorded.
            return SmsResult::failed("Gateway responded {$response->status()}");
        } catch (Throwable $exception) {
            return SmsResult::failed($exception->getMessage());
        }
    }
}
