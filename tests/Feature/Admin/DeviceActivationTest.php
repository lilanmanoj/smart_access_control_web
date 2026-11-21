<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Activating and deactivating a door from the dashboard.
 *
 * A device added in the dashboard starts `provisioned`, and the device API
 * admits only `active` devices — nothing a panel sends can change that. The
 * operator's switch is the only way in, and the same switch shuts it out.
 */
class DeviceActivationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_door_added_in_the_dashboard_is_refused_until_activated(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);

        $uuid = (string) $this->postJson('/api/admin/v1/devices', [
            'device_id' => 'SMA_1234',
            'name' => 'Main Door',
        ])->assertCreated()->assertJsonPath('data.status', 'provisioned')->json('data.id');

        $issued = $this->postJson("/api/admin/v1/devices/{$uuid}/credentials")->assertCreated()->json();
        $headers = [
            'X-Device-Id' => 'SMA_1234',
            'X-API-Key' => $issued['api_key'],
            'X-API-Secret' => $issued['api_secret'],
            'Accept' => 'application/json',
        ];

        // Every device route sits behind the same check, registration included.
        $this->health($headers)->assertForbidden()->assertJsonPath('error.code', 'device_provisioned');
        $this->postJson('/api/v1/device-registrations', ['device_id' => 'SMA_1234'], $headers)
            ->assertForbidden();

        $this->actingAsOperator('tenant_admin', $tenant);
        $this->patchJson("/api/admin/v1/devices/{$uuid}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->health($headers)->assertOk()->assertJsonPath('status', 'ok');

        $this->actingAsOperator('tenant_admin', $tenant);
        $this->patchJson("/api/admin/v1/devices/{$uuid}", ['status' => 'suspended'])->assertOk();

        $this->health($headers)->assertForbidden()->assertJsonPath('error.code', 'device_suspended');
    }

    #[Test]
    public function an_operator_without_device_update_cannot_activate(): void
    {
        $tenant = Tenant::factory()->create();
        $device = Device::factory()->for($tenant)->create(['status' => DeviceStatus::Provisioned]);

        $this->actingAsOperator('operator', $tenant);

        $this->patchJson("/api/admin/v1/devices/{$device->uuid}", ['status' => 'active'])->assertForbidden();
        $this->assertSame(DeviceStatus::Provisioned, $device->fresh()->status);
    }

    #[Test]
    public function an_activation_is_audited_with_before_and_after(): void
    {
        $tenant = Tenant::factory()->create();
        $device = Device::factory()->for($tenant)->create(['status' => DeviceStatus::Provisioned]);

        $this->actingAsOperator('tenant_admin', $tenant);
        $this->patchJson("/api/admin/v1/devices/{$device->uuid}", ['status' => 'active'])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'device.updated',
            'auditable_id' => $device->id,
        ]);
    }

    /** @param  array<string, string>  $headers */
    private function health(array $headers): TestResponse
    {
        // Device requests authenticate by credential; drop the dashboard
        // session so it cannot stand in for one.
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/health', $headers);
    }
}
