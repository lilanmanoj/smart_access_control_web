<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DeviceCommandType;
use App\Models\Device;
use App\Services\DeviceCommandService;
use App\Services\Realtime\DeviceRealtime;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Queue a command for one panel, or ring its doorbell, for firmware testing.
 *
 *   php artisan devices:test-command SMA_4821 unlock
 *   php artisan devices:test-command SMA_4821 sync_templates
 *   php artisan devices:test-command SMA_4821 --doorbell
 *
 * Exactly what the dashboard does, minus the dashboard: the command goes
 * through the same queue, so it rings the doorbell, expires on the same TTL
 * and is acknowledged the same way. Audited as issued by nobody.
 */
class SendTestDeviceCommand extends Command
{
    protected $signature = 'devices:test-command
        {device : The device UUID, or its device_id (e.g. SMA_4821)}
        {type? : unlock | sync_templates | refresh_backup_codes | report_inventory}
        {--doorbell : Only ring the MQTT doorbell; queue nothing}
        {--tenant= : Tenant UUID, when a device_id exists in more than one tenant}';

    protected $description = 'Queue a test command for one panel, or ring its MQTT doorbell';

    private const ALLOWED = [
        DeviceCommandType::Unlock,
        DeviceCommandType::SyncTemplates,
        DeviceCommandType::RefreshBackupCodes,
        DeviceCommandType::ReportInventory,
    ];

    public function handle(DeviceCommandService $commands, DeviceRealtime $realtime, TenantContext $tenants): int
    {
        $device = $this->resolveDevice($tenants);

        if ($device === null) {
            return self::FAILURE;
        }

        if ($this->option('doorbell')) {
            if (! $realtime->enabled()) {
                $this->error('The doorbell is off: set MQTT_ENABLED, MQTT_PUBLIC_HOST and MQTT_ADMIN_PASSWORD.');

                return self::FAILURE;
            }

            if (! $realtime->ring($device)) {
                $this->error('The broker could not be reached (or is in its 30 s back-off). See the application log.');

                return self::FAILURE;
            }

            $this->info("Rang {$realtime->topicFor($device)}");

            return self::SUCCESS;
        }

        $type = DeviceCommandType::tryFrom((string) $this->argument('type'));

        if ($type === null || ! in_array($type, self::ALLOWED, true)) {
            $this->error('Give a type: '.implode(', ', array_map(fn ($t) => $t->value, self::ALLOWED)).', or --doorbell.');

            return self::FAILURE;
        }

        $command = $tenants->forTenant($device->tenant, fn () => $commands->queue($device, $type));

        $this->info("Queued {$type->value} {$command->uuid} for {$device->device_id} ({$device->name}), expires {$command->expires_at?->toIso8601String()}.");
        $this->line($realtime->enabled()
            ? 'Doorbell rung on '.$realtime->topicFor($device).' (unless the broker is unreachable; see the log).'
            : 'The doorbell is off; the panel collects this at its next /health poll.');

        return self::SUCCESS;
    }

    private function resolveDevice(TenantContext $tenants): ?Device
    {
        $key = (string) $this->argument('device');

        $matches = $tenants->crossTenant(fn () => Device::query()
            ->with('tenant')
            ->where(fn ($q) => $q->where('uuid', $key)->orWhere('device_id', $key))
            ->when($this->option('tenant'), fn ($q, $tenant) => $q->whereHas('tenant', fn ($t) => $t->where('uuid', $tenant)))
            ->get());

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $this->error($matches->isEmpty()
            ? "No device matches {$key}."
            : "{$key} matches devices in several tenants; pass --tenant=<uuid>.");

        return null;
    }
}
