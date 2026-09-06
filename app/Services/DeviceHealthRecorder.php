<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\DeviceStatusChanged;
use App\Models\Device;
use App\Models\DeviceHealthReport;
use Carbon\CarbonImmutable;

/**
 * Heartbeat tracking (§9.2).
 *
 * /health is polled every 30s while the panel is up and every 10s while it
 * thinks it is down. Recording each poll individually would be ~2,880 rows per
 * device per day carrying no information, so consecutive polls in the same
 * state extend one run and a new row opens only on a state change or an hourly
 * rollover.
 */
class DeviceHealthRecorder
{
    /**
     * @param  array<string, mixed>  $report  Optional device self-report:
     *                                        firmware_version, uptime_ms,
     *                                        enrolled_count, template_capacity.
     */
    public function record(Device $device, array $report = []): void
    {
        $now = CarbonImmutable::now();
        $wasOnline = $device->is_online;

        $device->forceFill(array_filter([
            'last_seen_at' => $now,
            'last_health_at' => $now,
            'is_online' => true,
            'firmware_version' => $report['firmware_version'] ?? $device->firmware_version,
            'template_capacity' => $report['template_capacity'] ?? $device->template_capacity,
        ], fn ($value): bool => $value !== null))->saveQuietly();

        $this->extendOrOpenRun($device, 'online', $now, $report);

        if (! $wasOnline) {
            DeviceStatusChanged::dispatch($device, 'online');
        }
    }

    /**
     * Mark a device offline after it misses roughly three health intervals.
     * This is how the dashboard learns a door has gone dark.
     */
    public function markOffline(Device $device): void
    {
        if (! $device->is_online) {
            return;
        }

        $device->forceFill(['is_online' => false])->saveQuietly();

        $this->extendOrOpenRun($device, 'offline', CarbonImmutable::now(), []);

        DeviceStatusChanged::dispatch($device, 'offline');
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function extendOrOpenRun(
        Device $device,
        string $state,
        CarbonImmutable $at,
        array $report,
    ): void {
        $open = $device->healthReports()->latest('started_at')->first();

        $sameRun = $open !== null
            && $open->state === $state
            && $open->started_at->diffInMinutes($at) < 60;

        if ($sameRun) {
            $open->forceFill([
                'last_seen_at' => $at,
                'poll_count' => $open->poll_count + 1,
                'uptime_ms' => $report['uptime_ms'] ?? $open->uptime_ms,
                'enrolled_count' => $report['enrolled_count'] ?? $open->enrolled_count,
            ])->save();

            return;
        }

        DeviceHealthReport::create([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'state' => $state,
            'started_at' => $at,
            'last_seen_at' => $at,
            'poll_count' => 1,
            'firmware_version' => $report['firmware_version'] ?? $device->firmware_version,
            'uptime_ms' => $report['uptime_ms'] ?? null,
            'enrolled_count' => $report['enrolled_count'] ?? null,
            'metadata' => $report === [] ? null : $report,
        ]);
    }
}
