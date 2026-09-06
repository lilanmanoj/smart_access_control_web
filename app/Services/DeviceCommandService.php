<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Events\DeviceCommandQueued;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The backend-to-device channel.
 *
 * The panel cannot receive pushes — it polls. Every operation that has to
 * reach a device goes through this queue: erasing a revoked fingerprint,
 * forcing a backup-code refresh, unlocking remotely, asking for a slot
 * inventory.
 */
class DeviceCommandService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function queue(
        Device $device,
        DeviceCommandType $type,
        array $payload = [],
        ?User $issuedBy = null,
        ?int $ttlSeconds = null,
    ): DeviceCommand {
        $ttl = $ttlSeconds ?? (int) config('access.devices.command_ttl_seconds', 300);

        $command = new DeviceCommand([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'status' => DeviceCommandStatus::Pending,
            'issued_by' => $issuedBy?->id,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        $command->save();

        DeviceCommandQueued::dispatch($command);

        return $command;
    }

    /**
     * Hand the device its outstanding commands and mark them sent.
     *
     * `sent` rather than `acked`: the device confirms separately, and until it
     * does we do not claim the fingerprint was actually erased.
     *
     * @return Collection<int, DeviceCommand>
     */
    public function collectFor(Device $device): Collection
    {
        $this->expireStale($device);

        $commands = $device->commands()
            ->deliverable()
            ->orderBy('id')
            ->limit((int) config('access.devices.command_poll_limit', 10))
            ->get();

        if ($commands->isNotEmpty()) {
            DeviceCommand::query()
                ->whereIn('id', $commands->pluck('id'))
                ->where('status', DeviceCommandStatus::Pending->value)
                ->update([
                    'status' => DeviceCommandStatus::Sent->value,
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return $commands;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function acknowledge(DeviceCommand $command, string $status, array $result = []): DeviceCommand
    {
        $resolved = match ($status) {
            'acked' => DeviceCommandStatus::Acked,
            'failed' => DeviceCommandStatus::Failed,
            default => DeviceCommandStatus::Acked,
        };

        $command->forceFill([
            'status' => $resolved,
            'acked_at' => now(),
            'result' => $result === [] ? null : $result,
        ])->save();

        return $command;
    }

    /**
     * Retire commands the device never collected.
     *
     * A stale `unlock` firing an hour late would open a door for nobody, which
     * is the kind of bug that only shows up as a mystery in the access log.
     */
    public function expireStale(?Device $device = null): int
    {
        $query = DeviceCommand::query()
            ->whereIn('status', [
                DeviceCommandStatus::Pending->value,
                DeviceCommandStatus::Sent->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());

        if ($device !== null) {
            $query->where('device_id', $device->id);
        }

        return $query->update([
            'status' => DeviceCommandStatus::Expired->value,
            'updated_at' => now(),
        ]);
    }
}
