<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Device API 1.5.0: /health tells the panel its timezone and the UTC offset at
 * the moment of the response, so it can show local time without a timezone
 * database of its own.
 */
class HealthTimezoneTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function health_carries_the_devices_timezone_and_offset(): void
    {
        [$device, $credential] = $this->door(deviceTimezone: 'Asia/Kolkata', tenantTimezone: 'Asia/Colombo');

        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonPath('timezone', ['name' => 'Asia/Kolkata', 'utc_offset_seconds' => 19800]);
    }

    #[Test]
    public function a_device_without_a_timezone_gets_its_tenants(): void
    {
        [$device, $credential] = $this->door(deviceTimezone: null, tenantTimezone: 'Asia/Dubai');

        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertJsonPath('timezone', ['name' => 'Asia/Dubai', 'utc_offset_seconds' => 14400]);
    }

    /** Older records: the panel simply stays on UTC. */
    #[Test]
    public function the_timezone_is_left_out_when_neither_has_one(): void
    {
        [$device, $credential] = $this->door(deviceTimezone: null, tenantTimezone: null);

        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonMissingPath('timezone');
    }

    /**
     * The offset is for the instant of the response, so a daylight-saving
     * change reaches the panel at its next poll.
     */
    #[Test]
    public function the_offset_follows_daylight_saving(): void
    {
        [$device, $credential] = $this->door(deviceTimezone: 'Europe/London', tenantTimezone: 'Asia/Colombo');

        $this->travelTo(Carbon::parse('2026-01-15 12:00:00', 'UTC'));
        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertJsonPath('timezone.utc_offset_seconds', 0)
            ->assertJsonPath('server_time', '2026-01-15T12:00:00Z');

        $this->travelTo(Carbon::parse('2026-07-15 12:00:00', 'UTC'));
        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertJsonPath('timezone.utc_offset_seconds', 3600);

        // West of UTC is negative.
        $device->forceFill(['timezone' => 'America/New_York'])->save();
        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertJsonPath('timezone', ['name' => 'America/New_York', 'utc_offset_seconds' => -14400]);
    }

    #[Test]
    public function a_change_in_the_dashboard_reaches_the_next_poll(): void
    {
        [$device, $credential] = $this->door(deviceTimezone: 'Asia/Colombo', tenantTimezone: 'Asia/Colombo');
        $headers = $this->deviceHeaders($device, $credential);

        $this->getJson('/api/v1/health', $headers)->assertJsonPath('timezone.name', 'Asia/Colombo');

        $device->forceFill(['timezone' => 'Australia/Adelaide'])->save();

        $this->getJson('/api/v1/health', $headers)->assertJsonPath('timezone.name', 'Australia/Adelaide');
    }

    /** @return array{0: Device, 1: DeviceCredential} */
    private function door(?string $deviceTimezone, ?string $tenantTimezone): array
    {
        $tenant = Tenant::factory()->create(['timezone' => $tenantTimezone]);
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);
        $device->forceFill(['timezone' => $deviceTimezone])->save();

        return [$device, $credential];
    }
}
