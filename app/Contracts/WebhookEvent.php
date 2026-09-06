<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Tenant;

/**
 * An event a tenant may subscribe to over HTTP (§9.8).
 *
 * Implemented by the broadcast events themselves so there is one definition of
 * "what happened" feeding both the dashboard's live feed and a tenant's own
 * alerting, rather than two that drift.
 */
interface WebhookEvent
{
    /** The subscribable name, e.g. `access.denied`. */
    public function webhookName(): string;

    /** @return array<string, mixed> */
    public function webhookPayload(): array;

    public function webhookTenant(): ?Tenant;
}
