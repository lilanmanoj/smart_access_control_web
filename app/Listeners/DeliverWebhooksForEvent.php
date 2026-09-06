<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookEndpoint;

/**
 * Fans any {@see WebhookEvent} out to the tenant's subscribed endpoints.
 *
 * Registered against the interface rather than each concrete event, so a new
 * broadcast event becomes webhook-capable by implementing the contract and
 * nothing else has to be touched.
 */
class DeliverWebhooksForEvent
{
    public function handle(WebhookEvent $event): void
    {
        $tenant = $event->webhookTenant();

        if ($tenant === null) {
            return;
        }

        $name = $event->webhookName();

        $endpoints = WebhookEndpoint::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($name));

        foreach ($endpoints as $endpoint) {
            DeliverWebhook::dispatch($endpoint->id, $name, $event->webhookPayload());
        }
    }
}
