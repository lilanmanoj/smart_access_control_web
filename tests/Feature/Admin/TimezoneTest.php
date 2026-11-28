<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Device;
use App\Models\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every tenant and every device has an IANA timezone: required when one is
 * created, settable but never clearable on an edit. Rows from before the
 * column existed stay null until someone edits them.
 */
class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ---------------------------------------------------------------------
    // Tenants
    // ---------------------------------------------------------------------

    #[Test]
    public function a_new_tenant_needs_a_valid_timezone(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/v1/tenants', ['name' => 'Acme'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone', 'error.fields');

        $this->postJson('/api/admin/v1/tenants', ['name' => 'Acme', 'timezone' => 'Mars/Olympus_Mons'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone', 'error.fields');

        $this->postJson('/api/admin/v1/tenants', ['name' => 'Acme', 'timezone' => 'Europe/London'])
            ->assertCreated()
            ->assertJsonPath('data.timezone', 'Europe/London');

        $this->assertDatabaseHas('tenants', ['slug' => 'acme', 'timezone' => 'Europe/London']);
    }

    #[Test]
    public function a_tenant_timezone_can_be_changed_but_not_cleared(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->create(['timezone' => null]);

        $this->patchJson("/api/admin/v1/tenants/{$tenant->uuid}", ['timezone' => 'Asia/Dubai'])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Dubai');

        $this->patchJson("/api/admin/v1/tenants/{$tenant->uuid}", ['timezone' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone', 'error.fields');

        $this->assertSame('Asia/Dubai', $tenant->fresh()->timezone);
    }

    /** Deactivating sends only `status`; it must not trip the timezone rule. */
    #[Test]
    public function a_status_only_change_does_not_need_a_timezone(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->create(['timezone' => null]);

        $this->patchJson("/api/admin/v1/tenants/{$tenant->uuid}", ['status' => 'suspended'])->assertOk();

        $this->assertNull($tenant->fresh()->timezone);
    }

    // ---------------------------------------------------------------------
    // Devices
    // ---------------------------------------------------------------------

    #[Test]
    public function a_new_device_needs_a_valid_timezone(): void
    {
        $tenant = Tenant::factory()->create(['timezone' => 'Asia/Colombo']);
        $this->actingAsOperator('tenant_admin', $tenant);

        $this->postJson('/api/admin/v1/devices', ['device_id' => 'SMA_2001', 'name' => 'Gate'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone', 'error.fields');

        $this->postJson('/api/admin/v1/devices', ['device_id' => 'SMA_2001', 'name' => 'Gate', 'timezone' => 'Nowhere'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone', 'error.fields');

        // A door in another timezone than its tenant is allowed.
        $this->postJson('/api/admin/v1/devices', ['device_id' => 'SMA_2001', 'name' => 'Gate', 'timezone' => 'Asia/Kolkata'])
            ->assertCreated()
            ->assertJsonPath('data.timezone', 'Asia/Kolkata')
            ->assertJsonPath('data.tenant.timezone', 'Asia/Colombo');
    }

    #[Test]
    public function a_device_without_a_timezone_gets_one_on_its_next_edit(): void
    {
        $tenant = Tenant::factory()->create(['timezone' => 'Asia/Colombo']);
        $this->actingAsOperator('tenant_admin', $tenant);
        $device = Device::factory()->for($tenant)->create(['timezone' => null]);

        // The page shows the tenant's timezone to default to.
        $this->getJson("/api/admin/v1/devices/{$device->uuid}")
            ->assertOk()
            ->assertJsonPath('data.timezone', null)
            ->assertJsonPath('data.tenant.timezone', 'Asia/Colombo');

        // Activating or deactivating sends only `status`.
        $this->patchJson("/api/admin/v1/devices/{$device->uuid}", ['status' => 'suspended'])->assertOk();

        $this->patchJson("/api/admin/v1/devices/{$device->uuid}", [
            'name' => 'Front Gate', 'location' => null, 'timezone' => 'Asia/Colombo',
        ])->assertOk()->assertJsonPath('data.timezone', 'Asia/Colombo');

        $this->patchJson("/api/admin/v1/devices/{$device->uuid}", ['timezone' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone', 'error.fields');

        $this->assertSame('Asia/Colombo', $device->fresh()->timezone);
    }

    // ---------------------------------------------------------------------
    // Reference data
    // ---------------------------------------------------------------------

    #[Test]
    public function the_timezone_list_is_what_the_validation_accepts(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('operator', $tenant);

        $zones = collect($this->getJson('/api/admin/v1/timezones')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL), $zones->keys()->all());
        $this->assertSame('+05:30', $zones['Asia/Colombo']['offset']);
        $this->assertSame('+00:00', $zones['UTC']['offset']);
        $this->assertSame('-03:00', $zones['America/Argentina/Buenos_Aires']['offset']);
    }

    #[Test]
    public function the_signed_in_operator_sees_their_tenants_timezone(): void
    {
        $tenant = Tenant::factory()->create(['timezone' => 'Asia/Colombo']);
        $this->actingAsOperator('operator', $tenant);

        $this->getJson('/api/admin/v1/me')->assertOk()->assertJsonPath('user.tenant.timezone', 'Asia/Colombo');
    }

    #[Test]
    public function the_timezone_list_needs_a_signed_in_operator(): void
    {
        $this->getJson('/api/admin/v1/timezones')->assertUnauthorized();
    }
}
