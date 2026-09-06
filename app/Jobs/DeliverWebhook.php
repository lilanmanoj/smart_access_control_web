<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Deliver one event to one tenant endpoint.
 *
 * Signed with the endpoint's shared secret so the receiver can tell a genuine
 * delivery from anything else that finds the URL.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly int $endpointId,
        private readonly string $event,
        private readonly array $payload,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::withoutGlobalScopes()->find($this->endpointId);

        if ($endpoint === null || ! $endpoint->subscribesTo($this->event)) {
            return;
        }

        $body = [
            'event' => $this->event,
            'sent_at' => now()->toIso8601String(),
            'data' => $this->payload,
        ];

        $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $encoded, $endpoint->secret);

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => $this->event,
            'payload' => $body,
            'attempts' => $this->attempts(),
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Signature' => "sha256={$signature}",
                'X-Event' => $this->event,
            ])->timeout(10)->withBody($encoded, 'application/json')->post($endpoint->url);

            $delivery->forceFill([
                'response_status' => $response->status(),
                'delivered_at' => $response->successful() ? now() : null,
                'error' => $response->successful() ? null : 'Endpoint responded '.$response->status(),
            ])->save();

            if ($response->successful()) {
                $endpoint->forceFill([
                    'last_delivered_at' => now(),
                    'consecutive_failures' => 0,
                ])->save();

                return;
            }

            $this->recordFailure($endpoint);
            $this->release($this->backoff()[min($this->attempts() - 1, 2)]);
        } catch (Throwable $exception) {
            $delivery->forceFill(['error' => $exception->getMessage()])->save();
            $this->recordFailure($endpoint);

            throw $exception;
        }
    }

    /**
     * An endpoint that has failed repeatedly is disabled rather than retried
     * forever: a dead URL should not turn every denied entry into queue work.
     */
    private function recordFailure(WebhookEndpoint $endpoint): void
    {
        $failures = $endpoint->consecutive_failures + 1;

        $endpoint->forceFill([
            'consecutive_failures' => $failures,
            'is_active' => $failures < 20,
        ])->save();
    }
}
