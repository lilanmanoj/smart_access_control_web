<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\DeviceCommand;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A command is waiting for a device to collect it.
 *
 * Broadcast so the device detail screen can show the pending queue — an
 * operator who has just revoked a fingerprint needs to see that the erase has
 * not reached the door yet.
 */
class DeviceCommandQueued implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly DeviceCommand $command,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $tenantUuid = $this->command->tenant->uuid;

        return [
            new PrivateChannel("tenant.{$tenantUuid}.devices.{$this->command->device->uuid}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'device.command';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->command->uuid,
            'type' => $this->command->type->value,
            'status' => $this->command->status->value,
            'payload' => $this->command->payload,
            'expires_at' => $this->command->expires_at?->toIso8601String(),
            'created_at' => $this->command->created_at?->toIso8601String(),
        ];
    }
}
