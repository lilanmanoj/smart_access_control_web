<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\WebhookEvent;
use App\Models\BackupCodeSet;
use App\Models\Tenant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A device's backup codes were replaced.
 *
 * The plaintext codes are never in this payload — only the set's metadata.
 * They are shown once, to the operator who generated them, and nowhere else.
 */
class BackupCodesRotated implements ShouldBroadcast, WebhookEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly BackupCodeSet $set,
        public readonly string $reason,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $tenantUuid = $this->set->tenant->uuid;

        return [
            new PrivateChannel("tenant.{$tenantUuid}.devices.{$this->set->device->uuid}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'backup_codes.rotated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->payload();
    }

    public function webhookName(): string
    {
        return 'backup_codes.rotated';
    }

    /** @return array<string, mixed> */
    public function webhookPayload(): array
    {
        return $this->payload();
    }

    public function webhookTenant(): ?Tenant
    {
        return $this->set->tenant;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'set_id' => $this->set->uuid,
            'device' => [
                'id' => $this->set->device->uuid,
                'device_id' => $this->set->device->device_id,
                'name' => $this->set->device->name,
            ],
            'reason' => $this->reason,
            'issued_at' => $this->set->issued_at->toIso8601String(),
        ];
    }
}
