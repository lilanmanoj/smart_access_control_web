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
 * Repeated backup-code failures on one door.
 *
 * This is the clearest brute-force signal the system has: the codes are static
 * six-digit secrets cached on the panel, so someone standing there guessing is
 * the thing worth waking an operator for.
 */
class BackupCodeBruteForceSuspected implements ShouldBroadcast, WebhookEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Device $device,
        public readonly int $failures,
        public readonly int $windowMinutes,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $tenantUuid = $this->device->tenant->uuid;

        return [
            new PrivateChannel("tenant.{$tenantUuid}.alerts"),
            new PrivateChannel("tenant.{$tenantUuid}.devices.{$this->device->uuid}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'backup_codes.brute_force';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload();
    }

    public function webhookName(): string
    {
        return 'backup_codes.brute_force';
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
            'device' => [
                'id' => $this->device->uuid,
                'device_id' => $this->device->device_id,
                'name' => $this->device->name,
                'location' => $this->device->location,
            ],
            'failures' => $this->failures,
            'window_minutes' => $this->windowMinutes,
            'detected_at' => now()->toIso8601String(),
        ];
    }
}
