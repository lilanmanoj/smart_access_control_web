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
