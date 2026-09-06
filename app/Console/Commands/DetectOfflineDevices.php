<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Services\DeviceHealthRecorder;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Marks devices offline once they have missed roughly three health polls.
 *
 * The panel polls every 30 seconds while it is up, so a gap past the threshold
 * means the door has genuinely gone dark rather than that one request was
 * dropped. This is how the dashboard finds out — nothing else notices a device
 * that has simply stopped calling.
 */
class DetectOfflineDevices extends Command
{
    protected $signature = 'devices:detect-offline';

    protected $description = 'Mark devices offline that have stopped sending health polls';

    public function handle(DeviceHealthRecorder $health, TenantContext $tenants): int
    {
        $threshold = now()->subSeconds((int) config('access.devices.offline_after_seconds'));

        // Fleet-wide: the scheduler runs with no tenant bound, and this has to
        // see every tenant's doors.
        $devices = $tenants->crossTenant(fn () => Device::query()
            ->with('tenant')
            ->where('status', DeviceStatus::Active->value)
            ->where('is_online', true)
            ->where(fn ($q) => $q->whereNull('last_health_at')->orWhere('last_health_at', '<', $threshold))
            ->get());

        foreach ($devices as $device) {
            // Each device is marked inside its own tenant context so the
            // broadcast and webhook fan-out resolve the right subscribers.
            $tenants->forTenant($device->tenant, fn () => $health->markOffline($device));

            $this->warn("Marked offline: {$device->device_id} ({$device->name})");
        }

        $this->info("Checked fleet; {$devices->count()} device(s) went offline.");

        return self::SUCCESS;
    }
}
