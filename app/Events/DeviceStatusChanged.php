<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\WebhookEvent;
use App\Models\Device;
use App\Models\Tenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A door came up or went dark.
 */
class DeviceStatusChanged implements ShouldBroadcast, WebhookEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Device $device,
        public readonly string $state, // online | offline
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $tenantUuid = $this->device->tenant->uuid;

        return [
            new PrivateChannel("tenant.{$tenantUuid}.devices"),
            new PrivateChannel("tenant.{$tenantUuid}.devices.{$this->device->uuid}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'device.status';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload();
    }

    public function webhookName(): string
    {
        return "device.{$this->state}";
    }

    /** @return array<string, mixed> */
    public function webhookPayload(): array
    {
        return $this->payload();
    }

    public function webhookTenant(): ?Tenant
    {
        return $this->device->tenant;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'id' => $this->device->uuid,
            'device_id' => $this->device->device_id,
            'name' => $this->device->name,
            'location' => $this->device->location,
            'state' => $this->state,
            'last_health_at' => $this->device->last_health_at?->toIso8601String(),
            'changed_at' => now()->toIso8601String(),
        ];
    }
}
