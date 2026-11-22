<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Creating, listing, editing, activating and deactivating tenants from the
 * dashboard. All of it sits behind the `tenant.manage` permission.
 */
class TenantManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    #[Test]
    public function a_tenant_manager_creates_a_tenant_with_a_slug_derived_from_its_name(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/v1/tenants', ['name' => 'Acme Warehouse'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Acme Warehouse')
            ->assertJsonPath('data.slug', 'acme-warehouse')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.devices_count', 0);

        $this->assertDatabaseHas('tenants', ['slug' => 'acme-warehouse', 'status' => 'active']);
        $this->assertTrue(AuditLog::query()->where('action', 'tenant.created')->exists());
    }

    #[Test]
    public function a_derived_slug_that_is_taken_is_a_validation_error_not_a_server_error(): void
    {
        Tenant::factory()->create(['slug' => 'acme-warehouse']);
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/v1/tenants', ['name' => 'Acme Warehouse'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug', 'error.fields');

        $this->postJson('/api/admin/v1/tenants', ['name' => 'Acme Warehouse', 'slug' => 'acme-north'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'acme-north');
    }

    #[Test]
    public function only_the_known_settings_are_accepted(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/v1/tenants', [
            'name' => 'Acme',
            'settings' => ['anything' => ['goes' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('settings', 'error.fields');

        $this->postJson('/api/admin/v1/tenants', [
            'name' => 'Acme',
            'settings' => ['otp' => ['require_enrollment' => true]],
        ])->assertCreated()->assertJsonPath('data.settings', ['otp' => ['require_enrollment' => true]]);
    }

    #[Test]
    public function the_list_filters_by_name_and_status(): void
    {
        Tenant::factory()->create(['name' => 'Northwind Depot', 'slug' => 'northwind']);
        Tenant::factory()->suspended()->create(['name' => 'Northwind Annex', 'slug' => 'northwind-annex']);
        Tenant::factory()->create(['name' => 'Contoso', 'slug' => 'contoso']);
        $this->actingAsSuperAdmin();

        $this->adminGet('/tenants')->assertOk()->assertJsonCount(3, 'data');

        $this->adminGet('/tenants', ['search' => 'northwind'])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->adminGet('/tenants', ['search' => 'northwind', 'status' => 'suspended'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'northwind-annex');
    }

    #[Test]
    public function editing_merges_settings_and_null_clears_an_override(): void
    {
        $tenant = Tenant::factory()->create([
            'settings' => [
                'otp' => ['require_enrollment' => true],
                'members' => ['require_approval' => true],
            ],
        ]);
        $this->actingAsSuperAdmin();

        $this->patchJson("/api/admin/v1/tenants/{$tenant->uuid}", [
            'name' => 'Renamed',
            'settings' => [
                'backup_codes' => ['online_verification_only' => true],
                'members' => ['require_approval' => null],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.slug', $tenant->slug);

        $this->assertSame([
            'otp' => ['require_enrollment' => true],
            'backup_codes' => ['online_verification_only' => true],
        ], $tenant->fresh()->settings);
    }

    #[Test]
    public function deactivating_a_tenant_shuts_out_its_operators_and_its_panels_until_reactivated(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $tenant = $device->tenant;
        $operator = User::factory()->for($tenant)->create();
        $operator->assignRole('auditor');

        $this->actingAsSuperAdmin();
        $this->patchJson("/api/admin/v1/tenants/{$tenant->uuid}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->actingAsTenantOperator($operator)->getJson('/api/admin/v1/me')->assertForbidden();
        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tenant_suspended');

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAsSuperAdmin();
        $this->patchJson("/api/admin/v1/tenants/{$tenant->uuid}", ['status' => 'active'])->assertOk();

        $this->actingAsTenantOperator($operator)->getJson('/api/admin/v1/me')->assertOk();
        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))->assertOk();
    }

    #[Test]
    public function an_operator_cannot_deactivate_their_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->actingAsOperator('tenant_admin', $tenant);
        $user->givePermissionTo('tenant.manage');

        $this->patchJson("/api/admin/v1/tenants/{$tenant->uuid}", ['status' => 'suspended'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'cannot_suspend_own_tenant');

        $this->assertSame('active', $tenant->fresh()->status);
    }

    #[Test]
    public function an_operator_without_tenant_manage_can_do_none_of_it(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);

        $this->adminGet('/tenants')->assertForbidden();
        $this->postJson('/api/admin/v1/tenants', ['name' => 'Sneaky'])->assertForbidden();
        $this->patchJson("/api/admin/v1/tenants/{$other->uuid}", ['status' => 'suspended'])->assertForbidden();

        $this->assertSame('active', $other->fresh()->status);
    }

    /**
     * Switch from the SuperAdmin to a tenant operator mid-test.
     *
     * The session still carries the SuperAdmin's password hash, which
     * Sanctum's session check would read as a stolen session and sign out.
     */
    private function actingAsTenantOperator(User $operator): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        // Reloaded, so the tenant relation reflects the status just changed.
        return $this->actingAs($operator->fresh(), 'web');
    }
}
