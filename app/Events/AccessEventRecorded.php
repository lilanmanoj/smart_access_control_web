<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\WebhookEvent;
use App\Enums\AccessResult;
use App\Models\AccessEvent;
use App\Models\Tenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * One access attempt reached the backend.
 *
 * Feeds the dashboard's live ticker and, for denials, a tenant's webhooks.
 */
class AccessEventRecorded implements ShouldBroadcast, WebhookEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly AccessEvent $event,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $tenantUuid = $this->event->tenant->uuid;

        return [
            // The whole-tenant feed, and a per-device one so a device detail
            // screen does not have to filter the firehose.
            new PrivateChannel("tenant.{$tenantUuid}.events"),
            new PrivateChannel("tenant.{$tenantUuid}.devices.{$this->event->device->uuid}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'access.event';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload();
    }

    public function webhookName(): string
    {
        return $this->event->result === AccessResult::Granted
            ? 'access.granted'
            : 'access.denied';
    }

    /** @return array<string, mixed> */
    public function webhookPayload(): array
    {
        return $this->payload();
    }

    public function webhookTenant(): ?Tenant
    {
        return $this->event->tenant;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $event = $this->event->loadMissing(['device', 'member']);

        return [
            'id' => $event->uuid,
            'method' => $event->method->value,
            'result' => $event->result->value,
            'reason' => $event->reason,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'fingerprint_slot' => $event->fingerprint_slot,
            'confidence' => $event->confidence,
            'device' => [
                'id' => $event->device->uuid,
                'device_id' => $event->device->device_id,
                'name' => $event->device->name,
                'location' => $event->device->location,
            ],
            'member' => $event->member === null ? null : [
                'id' => $event->member->uuid,
                'full_name' => $event->member->full_name,
            ],
        ];
    }
}
