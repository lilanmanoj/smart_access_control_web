<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Enums\EnrollmentStatus;
use App\Models\DeviceCommand;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Enrolment from the panel, the admin gate, and the command channel that makes
 * revocation actually reach a sensor.
 */
class EnrollmentAndCommandTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Enrolment
    // ---------------------------------------------------------------------

    #[Test]
    public function it_creates_a_pending_enrollment_from_a_stored_slot(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->postJson('/api/v1/enrollments', ['fingerprint_slot' => 7],
            $this->deviceHeaders($device, $credential))
            ->assertCreated()
            ->assertJsonStructure(['member_id', 'enrollment_id', 'status'])
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseHas('enrollments', [
            'device_id' => $device->id,
            'fingerprint_slot' => 7,
            'status' => EnrollmentStatus::Pending->value,
        ]);
    }

    #[Test]
    public function attaching_a_phone_number_activates_the_enrollment(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $memberId = $this->postJson('/api/v1/enrollments', ['fingerprint_slot' => 7], $headers)
            ->json('member_id');

        $this->patchJson("/api/v1/enrollments/{$memberId}",
            ['phone' => '0771234567', 'fingerprint_slot' => 7], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'active');

        // Normalised to E.164 on the way in, so the OTP lookup can find it.
        $this->assertDatabaseHas('members', ['phone' => '+94771234567']);
    }

    /**
     * The panel can reach the phone screen with no template at all — the user
     * skipped the finger, or registration failed and rolled back.
     */
    #[Test]
    public function a_phone_only_member_is_a_valid_outcome(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $memberId = $this->postJson('/api/v1/enrollments', ['fingerprint_slot' => null], $headers)
            ->assertCreated()
            ->json('member_id');

        $this->patchJson("/api/v1/enrollments/{$memberId}", ['phone' => '0771234567'], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('enrollments', [
            'fingerprint_slot' => null,
            'status' => EnrollmentStatus::Active->value,
        ]);
    }

    #[Test]
    public function it_refuses_a_phone_number_that_will_not_fit_on_the_device(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $memberId = $this->postJson('/api/v1/enrollments', ['fingerprint_slot' => 1], $headers)
            ->json('member_id');

        // 15 characters in E.164 — one more than the panel's field holds.
        $this->patchJson("/api/v1/enrollments/{$memberId}", ['phone' => '+123456789012345'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_phone');
    }

    #[Test]
    public function it_refuses_a_phone_number_already_held_by_another_member(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);
        Member::factory()->for($tenant)->create(['phone' => '+94771234567']);

        $headers = $this->deviceHeaders($device, $credential);
        $memberId = $this->postJson('/api/v1/enrollments', ['fingerprint_slot' => 1], $headers)
            ->json('member_id');

        $this->patchJson("/api/v1/enrollments/{$memberId}", ['phone' => '+94771234567'], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'phone_in_use');
    }

    /**
     * A sensor that handed out a slot again — because a template was erased
     * without the backend hearing — must not produce two live owners.
     */
    #[Test]
    public function re_enrolling_an_occupied_slot_orphans_the_previous_owner(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);

        $previous = Enrollment::factory()->create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'member_id' => Member::factory()->for($tenant)->create()->id,
            'fingerprint_slot' => 4,
        ]);

        $this->postJson('/api/v1/enrollments', ['fingerprint_slot' => 4],
            $this->deviceHeaders($device, $credential))->assertCreated();

        $this->assertSame(EnrollmentStatus::Orphaned, $previous->fresh()->status);
        $this->assertNull($previous->fresh()->fingerprint_slot);
    }

    // ---------------------------------------------------------------------
    // Admin gate
    // ---------------------------------------------------------------------

    #[Test]
    public function it_confirms_an_administrator(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);

        $admin = Member::factory()->for($tenant)->admin()->create();
        Enrollment::factory()->create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'member_id' => $admin->id,
            'fingerprint_slot' => 2,
        ]);

        $this->postJson('/api/v1/authentications', ['enrollment_id' => 2],
            $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonPath('is_admin', true)
            ->assertJsonPath('member.full_name', $admin->full_name);
    }

    #[Test]
    public function it_refuses_a_member_who_is_not_an_administrator(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);

        Enrollment::factory()->create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'member_id' => Member::factory()->for($tenant)->create()->id,
            'fingerprint_slot' => 2,
        ]);

        $this->postJson('/api/v1/authentications', ['enrollment_id' => 2],
            $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonPath('is_admin', false);

        $this->assertDatabaseHas('access_events', [
            'method' => 'admin_auth',
            'result' => 'denied',
            'reason' => 'not_admin',
        ]);
    }

    #[Test]
    public function an_unknown_slot_is_refused_and_logged(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->postJson('/api/v1/authentications', ['enrollment_id' => 99],
            $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonPath('is_admin', false);

        $this->assertDatabaseHas('access_events', [
            'method' => 'admin_auth',
            'reason' => 'not_enrolled',
        ]);
    }

    // ---------------------------------------------------------------------
    // Commands
    // ---------------------------------------------------------------------

    #[Test]
    public function health_carries_queued_commands(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        app(\App\Support\TenantContext::class)->set($device->tenant);
        app(\App\Services\DeviceCommandService::class)
            ->queue($device, DeviceCommandType::RefreshBackupCodes);

        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('commands.0.type', 'refresh_backup_codes')
            // The device's only route to a real clock; its own reads 1970.
            ->assertJsonStructure(['server_time']);
    }

    #[Test]
    public function collecting_a_command_marks_it_sent_but_not_acknowledged(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        app(\App\Support\TenantContext::class)->set($device->tenant);
        $command = app(\App\Services\DeviceCommandService::class)
            ->queue($device, DeviceCommandType::Unlock);

        $this->getJson('/api/v1/device-commands', $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonCount(1, 'commands');

        // Sent, not acked: until the device confirms, we do not claim the door
        // actually opened.
        $this->assertSame(DeviceCommandStatus::Sent, $command->fresh()->status);
    }

    #[Test]
    public function a_device_can_acknowledge_a_command(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        app(\App\Support\TenantContext::class)->set($device->tenant);
        $command = app(\App\Services\DeviceCommandService::class)
            ->queue($device, DeviceCommandType::DeleteEnrollment, ['fingerprint_slot' => 7]);

        $this->postJson("/api/v1/device-commands/{$command->uuid}/acknowledgements",
            ['status' => 'acked', 'result' => ['deleted' => true]],
            $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonPath('acknowledged', true);

        $this->assertSame(DeviceCommandStatus::Acked, $command->fresh()->status);
    }

    #[Test]
    public function a_device_cannot_acknowledge_another_devices_command(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $mine] = $this->provisionDevice($tenant);
        ['device' => $other, 'credential' => $otherCredential] =
            $this->provisionDevice($tenant, 'other-secret-value-000000000000000000');

        app(\App\Support\TenantContext::class)->set($tenant);
        $command = app(\App\Services\DeviceCommandService::class)
            ->queue($mine, DeviceCommandType::Unlock);

        $this->postJson("/api/v1/device-commands/{$command->uuid}/acknowledgements",
            ['status' => 'acked'],
            $this->deviceHeaders($other, $otherCredential, 'other-secret-value-000000000000000000'))
            ->assertNotFound();
    }

    /**
     * A stale unlock opening a door an hour late is a real bug, not a
     * curiosity.
     */
    #[Test]
    public function an_expired_command_is_not_delivered(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        DeviceCommand::create([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'type' => DeviceCommandType::Unlock,
            'status' => DeviceCommandStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v1/device-commands', $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonCount(0, 'commands');
    }

    #[Test]
    public function an_inventory_report_reconciles_slots(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);

        $ours = Enrollment::factory()->create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'member_id' => Member::factory()->for($tenant)->create()->id,
            'fingerprint_slot' => 5,
        ]);

        app(\App\Support\TenantContext::class)->set($tenant);
        $command = app(\App\Services\DeviceCommandService::class)
            ->queue($device, DeviceCommandType::ReportInventory);

        // The device reports it holds nothing — our record is stale.
        $this->postJson("/api/v1/device-commands/{$command->uuid}/acknowledgements",
            ['status' => 'acked', 'result' => ['occupied_slots' => []]],
            $this->deviceHeaders($device, $credential))->assertOk();

        $this->assertSame(EnrollmentStatus::Orphaned, $ours->fresh()->status);
    }
}
