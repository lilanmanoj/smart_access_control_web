<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\DeviceCommandType;
use App\Models\Device;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dashboard API's authorisation surface.
 *
 * Permissions are checked by name in policies, so the useful test is that a
 * role which lacks a permission is refused — not that a role name matches.
 */
class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_refuses_an_unauthenticated_request(): void
    {
        $this->adminGet('/devices')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    #[Test]
    public function an_auditor_may_read_but_not_write(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('auditor', $tenant);
        Device::factory()->for($tenant)->create();

        $this->adminGet('/devices')->assertOk();
        $this->adminGet('/audit-logs')->assertOk();

        $this->postJson('/api/admin/v1/devices', [
            'device_id' => 'SMA_1111',
            'name' => 'Should not be created',
        ])->assertForbidden();

        $this->assertDatabaseMissing('devices', ['device_id' => 'SMA_1111']);
    }

    #[Test]
    public function an_operator_may_unlock_but_not_manage_users(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('operator', $tenant);
        $device = Device::factory()->for($tenant)->create();

        $this->postJson("/api/admin/v1/devices/{$device->uuid}/unlock")->assertAccepted();
        $this->adminGet('/users')->assertForbidden();
    }

    /**
     * A remote unlock is an operator decision and has to be attributable.
     */
    #[Test]
    public function a_remote_unlock_is_queued_and_attributed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->actingAsOperator('tenant_admin', $tenant);
        $device = Device::factory()->for($tenant)->create();

        $this->postJson("/api/admin/v1/devices/{$device->uuid}/unlock", ['reason' => 'Courier'])
            ->assertAccepted();

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $device->id,
            'type' => DeviceCommandType::Unlock->value,
            'issued_by' => $user->id,
        ]);

        $this->assertDatabaseHas('access_events', [
            'device_id' => $device->id,
            'method' => 'remote',
            'result' => 'granted',
            'reason' => 'remote_unlock',
            'actor_user_id' => $user->id,
        ]);
    }

    /**
     * Revoking an enrolment has to reach the sensor, or the member is
     * withdrawn on paper and their finger still opens the door.
     */
    #[Test]
    public function revoking_an_enrollment_queues_a_template_erase(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('device_manager', $tenant);

        $device = Device::factory()->for($tenant)->create();
        $enrollment = Enrollment::factory()->create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'member_id' => Member::factory()->for($tenant)->create()->id,
            'fingerprint_slot' => 9,
        ]);

        $this->deleteJson("/api/admin/v1/enrollments/{$enrollment->uuid}")->assertOk();

        $this->assertDatabaseHas('device_commands', [
            'device_id' => $device->id,
            'type' => DeviceCommandType::DeleteEnrollment->value,
        ]);

        $command = \App\Models\DeviceCommand::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(9, $command->payload['fingerprint_slot']);

        // The slot is freed on our side immediately so it can be re-enrolled.
        $this->assertNull($enrollment->fresh()->fingerprint_slot);
    }

    #[Test]
    public function backup_code_rotation_reveals_the_codes_exactly_once(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('device_manager', $tenant);
        $device = Device::factory()->for($tenant)->create();

        $codes = $this->postJson("/api/admin/v1/devices/{$device->uuid}/backup-codes/rotations")
            ->assertCreated()
            ->json('codes');

        $this->assertCount(5, $codes);

        // Nothing else in the API ever returns them again.
        $listed = $this->adminGet('/backup-code-sets')->assertOk()->json('data.0.codes');

        foreach ($listed as $entry) {
            $this->assertArrayNotHasKey('code', $entry);
            $this->assertArrayHasKey('last4', $entry);
        }
    }

    #[Test]
    public function issuing_a_credential_returns_the_secret_once(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);
        $device = Device::factory()->for($tenant)->create();

        $issued = $this->postJson("/api/admin/v1/devices/{$device->uuid}/credentials", [])
            ->assertCreated()
            ->json();

        $this->assertNotEmpty($issued['api_secret']);

        // The stored form is a digest, not the secret.
        $this->assertDatabaseMissing('device_credentials', [
            'api_secret_hash' => $issued['api_secret'],
        ]);

        // Listing them never includes it again.
        $listed = $this->adminGet("/devices/{$device->uuid}/credentials")->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('api_secret', $listed);
    }

    #[Test]
    public function only_two_live_credentials_are_allowed_per_device(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);
        $device = Device::factory()->for($tenant)->create();

        $this->postJson("/api/admin/v1/devices/{$device->uuid}/credentials", [])->assertCreated();
        $this->postJson("/api/admin/v1/devices/{$device->uuid}/credentials", [])->assertCreated();

        $this->postJson("/api/admin/v1/devices/{$device->uuid}/credentials", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'credential_limit_reached');
    }

    /**
     * Two-factor is required for any role that can manage devices or members.
     * Until it is set up, that user reaches the enrolment endpoints and
     * nothing else.
     */
    #[Test]
    public function a_device_managing_role_is_blocked_until_two_factor_is_enrolled(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->for(Tenant::factory())->create();
        $user->assignRole('device_manager');

        $this->actingAs($user);

        $this->adminGet('/devices')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_setup_required');

        // The way out is open.
        $this->adminGet('/me')->assertOk();
        $this->postJson('/api/admin/v1/two-factor/enroll')->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_url', 'qr_svg']);
    }

    #[Test]
    public function an_operator_role_does_not_require_two_factor(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->for(Tenant::factory())->create();
        $user->assignRole('operator');

        $this->actingAs($user);

        $this->adminGet('/devices')->assertOk();
    }

    /**
     * The failure a SuperAdmin hits when they add a device without first
     * choosing a tenant. It has to be a clear 409 they can act on, not a 500
     * carrying a MySQL constraint message.
     */
    #[Test]
    public function a_super_admin_in_fleet_view_gets_an_actionable_error(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Tenant::factory()->create();

        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/v1/devices', [
            'device_id' => 'SMA_DEMO_01',
            'name' => 'Demo Device 01',
            'location' => 'Laboratory',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'tenant_not_selected');

        $this->assertDatabaseMissing('devices', ['device_id' => 'SMA_DEMO_01']);
    }

    /**
     * …and it succeeds once they switch into one, which is what the error
     * tells them to do.
     */
    #[Test]
    public function a_super_admin_can_create_a_device_after_switching_tenant(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $tenant = Tenant::factory()->create();

        $this->actingAsSuperAdmin();

        $this->postJson("/api/admin/v1/tenants/{$tenant->uuid}/switch")->assertOk();

        $this->postJson('/api/admin/v1/devices', [
            'device_id' => 'SMA_DEMO_01',
            'name' => 'Demo Device 01',
            'location' => 'Laboratory',
        ])->assertCreated();

        $this->assertDatabaseHas('devices', [
            'device_id' => 'SMA_DEMO_01',
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * The SuperAdmin's way through from fleet view: name the tenant on the
     * request itself.
     */
    #[Test]
    public function a_super_admin_can_choose_the_tenant_when_creating_a_device(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Tenant::factory()->create();
        $target = Tenant::factory()->create();

        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/v1/devices', [
            'device_id' => 'SMA_DEMO_01',
            'name' => 'Demo Device 01',
            'location' => 'Laboratory',
            'tenant_id' => $target->uuid,
        ])
            ->assertCreated()
            ->assertJsonPath('data.tenant.id', $target->uuid);

        $this->assertDatabaseHas('devices', [
            'device_id' => 'SMA_DEMO_01',
            'tenant_id' => $target->id,
        ]);
    }

    /**
     * The gate is the `tenant.manage` permission, not the role name. A
     * tenant_admin holds every other permission and still cannot reach out of
     * their own tenant.
     */
    #[Test]
    public function an_operator_without_tenant_manage_cannot_choose_a_tenant(): void
    {
        $ownTenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();

        $this->actingAsOperator('tenant_admin', $ownTenant);

        $this->postJson('/api/admin/v1/devices', [
            'device_id' => 'SMA_DEMO_01',
            'name' => 'Demo Device 01',
            'tenant_id' => $otherTenant->uuid,
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tenant_selection_forbidden');

        $this->assertDatabaseMissing('devices', ['device_id' => 'SMA_DEMO_01']);
    }

    #[Test]
    public function choosing_an_unknown_tenant_is_refused(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/v1/devices', [
            'device_id' => 'SMA_DEMO_01',
            'name' => 'Demo Device 01',
            'tenant_id' => '01a00000-0000-7000-8000-000000000000',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'unknown_tenant');
    }

    #[Test]
    public function choosing_a_suspended_tenant_is_refused(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $suspended = Tenant::factory()->suspended()->create();

        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/v1/devices', [
            'device_id' => 'SMA_DEMO_01',
            'name' => 'Demo Device 01',
            'tenant_id' => $suspended->uuid,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'tenant_suspended');
    }

    /**
     * device_id is unique *per tenant*, so the uniqueness rule has to run
     * against the chosen tenant rather than whatever the request had bound.
     */
    #[Test]
    public function the_same_device_id_may_exist_in_two_tenants(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        Device::factory()->for($alpha)->create(['device_id' => 'SMA_4821']);

        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/v1/devices', [
            'device_id' => 'SMA_4821',
            'name' => 'Another tenant, same panel id',
            'tenant_id' => $beta->uuid,
        ])->assertCreated();

        // …but not twice within one tenant.
        $this->postJson('/api/admin/v1/devices', [
            'device_id' => 'SMA_4821',
            'name' => 'Duplicate',
            'tenant_id' => $beta->uuid,
        ])->assertStatus(422);
    }

    #[Test]
    public function mutations_are_written_to_the_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->actingAsOperator('tenant_admin', $tenant);
        $member = Member::factory()->for($tenant)->create(['full_name' => 'Before']);

        $this->patchJson("/api/admin/v1/members/{$member->uuid}", ['full_name' => 'After'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'member.updated',
        ]);

        $log = \App\Models\AuditLog::withoutGlobalScopes()->where('action', 'member.updated')->firstOrFail();

        $this->assertSame('Before', $log->before['full_name']);
        $this->assertSame('After', $log->after['full_name']);
    }

    #[Test]
    public function the_access_event_export_streams_a_csv(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);

        $device = Device::factory()->for($tenant)->create();
        \App\Models\AccessEvent::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
        ]);

        $response = $this->get('/api/admin/v1/access-events/export?format=csv');
        $response->assertOk();

        $body = $response->streamedContent();

        $this->assertStringContainsString('Occurred at (UTC)', $body);
        $this->assertStringContainsString($device->device_id, $body);
    }

    #[Test]
    public function the_dashboard_summary_is_scoped_and_bounded(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);

        Device::factory()->for($tenant)->count(2)->create();
        Device::factory()->for(Tenant::factory())->count(5)->create();

        $this->adminGet('/dashboard/summary', ['days' => 14])
            ->assertOk()
            ->assertJsonPath('devices.total', 2)
            ->assertJsonCount(14, 'trend');
    }
}
