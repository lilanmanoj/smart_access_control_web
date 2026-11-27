<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Events\DeviceCommandQueued;
use App\Listeners\RingDeviceDoorbell;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * The backend-to-device channel.
 *
 * The panel cannot be pushed to over HTTPS — it polls. Every operation that
 * has to reach a device goes through this queue: syncing its templates,
 * forcing a backup-code refresh, unlocking remotely, asking for a slot
 * inventory. Since device API 1.4.0 an MQTT doorbell
 * ({@see RingDeviceDoorbell}) tells the panel to collect early;
 * the queue is still the only authority.
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
        $ttl = $ttlSeconds ?? $type->defaultTtlSeconds();

        $command = new DeviceCommand([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'status' => DeviceCommandStatus::Pending,
            'issued_by' => $issuedBy?->id,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        $this->assertPayloadFits($command);

        $command->save();

        // Dispatched after the surrounding transaction commits (the event is
        // ShouldDispatchAfterCommit), so a doorbell can never send the panel
        // to fetch a row it cannot see yet.
        DeviceCommandQueued::dispatch($command);

        return $command;
    }

    /**
     * Ask a panel to run a template sync, unless one is already waiting.
     *
     * Only a PENDING request suppresses a new one. A `sent` one may already be
     * running against the set as it was before this change, so a second
     * request is queued and the panel runs one more pass.
     */
    public function queueSyncTemplates(Device $device, ?User $issuedBy = null): DeviceCommand
    {
        $waiting = $device->commands()
            ->where('type', DeviceCommandType::SyncTemplates->value)
            ->where('status', DeviceCommandStatus::Pending->value)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();

        return $waiting ?? $this->queue($device, DeviceCommandType::SyncTemplates, issuedBy: $issuedBy);
    }

    /**
     * Hand the device its outstanding commands and mark them sent.
     *
     * `sent` rather than `acked`: the device confirms separately, and until it
     * does we do not claim the door opened or the fingerprint was erased.
     *
     * Time-sensitive commands come first and are claimed atomically — the
     * pending→sent update only succeeds for one request — so two overlapping
     * polls (a /health and a doorbell fetch) can never both deliver the same
     * unlock. Commands are added only while the response stays inside the
     * panel's buffer; the rest wait for the next poll.
     *
     * @return Collection<int, DeviceCommand>
     */
    public function collectFor(Device $device): Collection
    {
        $this->expireStale($device);

        $timeSensitive = DeviceCommandType::timeSensitiveValues();
        $placeholders = implode(',', array_fill(0, count($timeSensitive), '?'));

        $candidates = $device->commands()
            ->deliverable()
            ->when($timeSensitive !== [], fn ($q) => $q->orderByRaw(
                "case when type in ({$placeholders}) then 0 else 1 end",
                $timeSensitive,
            ))
            ->orderBy('id')
            ->limit((int) config('access.devices.command_poll_limit', 10))
            ->get();

        $budget = (int) config('access.devices.command_response_budget_bytes', 3000);
        $used = 0;
        $delivered = new Collection;

        foreach ($candidates as $command) {
            $size = strlen((string) json_encode($command->toDeviceArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) + 1;

            if ($used + $size > $budget) {
                break;
            }

            if ($command->status === DeviceCommandStatus::Pending && ! $this->claim($command)) {
                // Another poll took it in the meantime. A sent unlock is never
                // offered again; anything else is still deliverable as sent.
                if ($command->type->isTimeSensitive()) {
                    continue;
                }
            }

            $used += $size;
            $delivered->push($command);
        }

        return $delivered;
    }

    /**
     * Record a device's acknowledgement.
     *
     * The first final acknowledgement wins. Returns false for a repeat, which
     * the caller answers with a plain 200 and no side effects — the panel
     * retries acknowledgements, and a retry must not reconcile, erase or log
     * the door opening twice. A late acknowledgement of an expired command is
     * still accepted: an unlock acknowledged after its expiry did open the
     * door.
     *
     * @param  array<string, mixed>  $result
     */
    public function acknowledge(DeviceCommand $command, string $status, array $result = []): bool
    {
        $resolved = $status === 'failed' ? DeviceCommandStatus::Failed : DeviceCommandStatus::Acked;
        $now = now();

        $updated = DeviceCommand::query()
            ->whereKey($command->getKey())
            ->whereIn('status', [
                DeviceCommandStatus::Pending->value,
                DeviceCommandStatus::Sent->value,
                DeviceCommandStatus::Expired->value,
            ])
            ->update([
                'status' => $resolved->value,
                'acked_at' => $now,
                'result' => $result === [] ? null : json_encode($result, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);

        $command->refresh();

        return $updated === 1;
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

    /** Move one command from pending to sent; false if another poll won. */
    private function claim(DeviceCommand $command): bool
    {
        $now = now();

        $claimed = DeviceCommand::query()
            ->whereKey($command->getKey())
            ->where('status', DeviceCommandStatus::Pending->value)
            ->update([
                'status' => DeviceCommandStatus::Sent->value,
                'sent_at' => $now,
                'updated_at' => $now,
            ]) === 1;

        if ($claimed) {
            $command->forceFill(['status' => DeviceCommandStatus::Sent, 'sent_at' => $now])->syncOriginal();
        }

        return $claimed;
    }

    /**
     * Device API 1.4.0 caps a command's device-facing payload, so a full
     * response always fits the panel's buffer.
     */
    private function assertPayloadFits(DeviceCommand $command): void
    {
        $max = (int) config('access.devices.command_payload_max_bytes', 256);
        $size = strlen((string) json_encode($command->devicePayload() ?: new \stdClass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($size > $max) {
            throw new InvalidArgumentException(
                "A {$command->type->value} payload of {$size} bytes exceeds the {$max}-byte device limit."
            );
        }
    }
}
