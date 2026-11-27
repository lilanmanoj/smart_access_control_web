<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Enums\DeviceStatus;
use App\Enums\MemberStatus;
use App\Models\AccessEvent;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceCredential;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\DeviceCommandService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Device API 1.4.0: command delivery the MQTT doorbell depends on, idempotent
 * acknowledgements, real-time template sync, the doorbell itself, and the
 * `realtime` login in the registration response.
 */
class RealtimeCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    private DeviceCredential $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        ['device' => $this->device, 'credential' => $this->credential] = $this->provisionDevice($this->tenant);
    }

    // ---------------------------------------------------------------------
    // Command objects and delivery
    // ---------------------------------------------------------------------

    #[Test]
    public function commands_carry_expires_in_and_expires_at_on_both_endpoints(): void
    {
        $this->queue(DeviceCommandType::RefreshBackupCodes);

        foreach (['/api/v1/health', '/api/v1/device-commands'] as $path) {
            $command = $this->getJson($path, $this->headers())->assertOk()->json('commands.0');

            $this->assertSame('refresh_backup_codes', $command['type']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $command['expires_at']);
            $this->assertIsInt($command['expires_in']);
            $this->assertGreaterThan(290, $command['expires_in']);
            $this->assertLessThanOrEqual(300, $command['expires_in']);
        }
    }

    #[Test]
    public function an_unlock_lives_sixty_seconds_and_a_sync_thirty_minutes(): void
    {
        $unlock = $this->queue(DeviceCommandType::Unlock);
        $sync = $this->queue(DeviceCommandType::SyncTemplates);

        $this->assertEqualsWithDelta(60, now()->diffInSeconds($unlock->expires_at), 2);
        $this->assertEqualsWithDelta(1800, now()->diffInSeconds($sync->expires_at), 2);
    }

    #[Test]
    public function unlocks_are_listed_first(): void
    {
        $this->queue(DeviceCommandType::RefreshBackupCodes);
        $this->queue(DeviceCommandType::SyncTemplates);
        $this->queue(DeviceCommandType::Unlock);

        $types = collect($this->getJson('/api/v1/health', $this->headers())->json('commands'))->pluck('type')->all();

        $this->assertSame(['unlock', 'refresh_backup_codes', 'sync_templates'], $types);
    }

    /**
     * The panel runs commands from whichever response delivers them first and
     * never fetches the other endpoint for them. An unlock must therefore be
     * handed out exactly once across both.
     */
    #[Test]
    public function a_sent_unlock_is_never_offered_again_on_either_endpoint(): void
    {
        $unlock = $this->queue(DeviceCommandType::Unlock);

        $this->getJson('/api/v1/health', $this->headers())->assertJsonCount(1, 'commands');
        $this->assertSame(DeviceCommandStatus::Sent, $unlock->fresh()->status);

        $this->getJson('/api/v1/device-commands', $this->headers())->assertJsonCount(0, 'commands');
        $this->getJson('/api/v1/health', $this->headers())->assertJsonCount(0, 'commands');
    }

    #[Test]
    public function any_other_sent_command_is_offered_again_until_acknowledged(): void
    {
        $sync = $this->queue(DeviceCommandType::SyncTemplates);

        $this->getJson('/api/v1/health', $this->headers())->assertJsonPath('commands.0.id', $sync->uuid);
        $this->getJson('/api/v1/device-commands', $this->headers())->assertJsonPath('commands.0.id', $sync->uuid);

        $this->ack($sync, 'acked', ['status' => 'unchanged']);

        $this->getJson('/api/v1/health', $this->headers())->assertJsonCount(0, 'commands');
    }

    #[Test]
    public function an_expired_command_is_never_sent(): void
    {
        $unlock = $this->queue(DeviceCommandType::Unlock);
        $this->travel(61)->seconds();

        $this->getJson('/api/v1/device-commands', $this->headers())->assertJsonCount(0, 'commands');
        $this->assertSame(DeviceCommandStatus::Expired, $unlock->fresh()->status);
    }

    #[Test]
    public function an_unlock_reason_stays_on_the_server(): void
    {
        $this->queue(DeviceCommandType::Unlock, ['reason' => 'Courier at the back door']);

        $command = $this->getJson('/api/v1/health', $this->headers())->json('commands.0');

        $this->assertSame([], (array) $command['payload']);
        $this->assertStringNotContainsString('Courier', $this->getJson('/api/v1/health', $this->headers())->getContent());
    }

    /**
     * The panel reads at most 4096 bytes of a /health body.
     */
    #[Test]
    public function ten_commands_fit_the_panels_4096_byte_read(): void
    {
        $this->device->forceFill(['name' => str_repeat('Back door ', 12)])->save();

        $this->queue(DeviceCommandType::Unlock);
        foreach (range(1, 6) as $slot) {
            $this->queue(DeviceCommandType::DeleteEnrollment, ['fingerprint_slot' => 100 + $slot]);
        }
        $this->queue(DeviceCommandType::RefreshBackupCodes);
        $this->queue(DeviceCommandType::ReportInventory);
        $this->queue(DeviceCommandType::SyncTemplates);

        $response = $this->getJson('/api/v1/health', $this->headers())->assertJsonCount(10, 'commands');

        $this->assertLessThan(4096, strlen((string) $response->getContent()));
    }

    #[Test]
    public function commands_that_would_overflow_the_response_wait_for_the_next_poll(): void
    {
        config(['access.devices.command_response_budget_bytes' => 300]);

        $unlock = $this->queue(DeviceCommandType::Unlock);
        $sync = $this->queue(DeviceCommandType::SyncTemplates);
        $refresh = $this->queue(DeviceCommandType::RefreshBackupCodes);

        $this->getJson('/api/v1/health', $this->headers())
            ->assertJsonCount(2, 'commands')
            ->assertJsonPath('commands.0.id', $unlock->uuid);

        // Never claimed, so nothing is lost: it is the first thing next time.
        $this->assertSame(DeviceCommandStatus::Pending, $refresh->fresh()->status);
        $this->assertSame(DeviceCommandStatus::Sent, $sync->fresh()->status);
    }

    #[Test]
    public function a_payload_over_256_bytes_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->queue(DeviceCommandType::UpdateSettings, ['blob' => str_repeat('x', 300)]);
    }

    // ---------------------------------------------------------------------
    // Acknowledgements
    // ---------------------------------------------------------------------

    #[Test]
    public function a_repeated_acknowledgement_changes_nothing(): void
    {
        $unlock = $this->queue(DeviceCommandType::Unlock);

        $this->ack($unlock, 'acked', ['opened' => true])->assertExactJson(['acknowledged' => true]);
        $first = $unlock->fresh();

        $this->travel(5)->seconds();
        $this->ack($unlock, 'acked', ['opened' => true])->assertExactJson(['acknowledged' => true]);
        $this->ack($unlock, 'failed', ['error' => 'busy'])->assertExactJson(['acknowledged' => true]);

        $this->assertSame(1, AccessEvent::query()->count());
        $this->assertSame(DeviceCommandStatus::Acked, $unlock->fresh()->status);
        $this->assertEquals($first->acked_at, $unlock->fresh()->acked_at);
    }

    #[Test]
    public function a_repeated_inventory_acknowledgement_does_not_reconcile_twice(): void
    {
        $enrollment = Enrollment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->device->id,
            'member_id' => Member::factory()->for($this->tenant)->create()->id,
            'fingerprint_slot' => 5,
        ]);
        $inventory = $this->queue(DeviceCommandType::ReportInventory);

        $this->ack($inventory, 'acked', ['occupied_slots' => []]);
        $orphanedAt = $enrollment->fresh()->updated_at;

        $this->travel(5)->seconds();
        $this->ack($inventory, 'acked', ['occupied_slots' => []]);

        $this->assertEquals($orphanedAt, $enrollment->fresh()->updated_at);
    }

    /**
     * The door opened; the log has to say so even though the acknowledgement
     * arrived after the command's expiry.
     */
    #[Test]
    public function an_unlock_acknowledged_after_expiry_is_still_recorded(): void
    {
        $unlock = $this->queue(DeviceCommandType::Unlock);
        $this->getJson('/api/v1/health', $this->headers());
        $this->travel(90)->seconds();
        $this->artisan('device-commands:expire')->assertSuccessful();
        $this->assertSame(DeviceCommandStatus::Expired, $unlock->fresh()->status);

        $this->ack($unlock, 'acked', ['opened' => true])->assertOk();

        $this->assertSame(DeviceCommandStatus::Acked, $unlock->fresh()->status);
        $this->assertDatabaseHas('access_events', ['method' => 'remote', 'result' => 'granted']);
    }

    #[Test]
    public function an_unlock_that_did_not_open_is_audited_not_logged_as_access(): void
    {
        $unlock = $this->queue(DeviceCommandType::Unlock);

        $this->ack($unlock, 'failed', ['error' => 'busy'])->assertOk();

        $this->assertDatabaseCount('access_events', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.unlock_failed']);
        $this->assertSame(['error' => 'busy'], $unlock->fresh()->result);
    }

    #[Test]
    public function an_inventory_of_300_slots_is_accepted(): void
    {
        $inventory = $this->queue(DeviceCommandType::ReportInventory);

        $this->ack($inventory, 'acked', ['occupied_slots' => range(0, 299)])->assertOk();

        $this->assertCount(300, $inventory->fresh()->result['occupied_slots']);
    }

    /** Every code the 1.4.0 contract lists for a failed acknowledgement. */
    #[Test]
    public function every_documented_failure_code_is_accepted(): void
    {
        $codes = ['busy', 'expired', 'unsupported', 'not_idle', 'sync_failed', 'still_listed',
            'sensor_error', 'sensor_unavailable', 'invalid_payload', 'fetch_failed', 'out_of_memory'];

        foreach ($codes as $code) {
            $command = $this->queue(DeviceCommandType::RefreshBackupCodes);
            $this->ack($command, 'failed', ['error' => $code])->assertOk();
            $this->assertSame($code, $command->fresh()->result['error']);
        }
    }

    #[Test]
    public function sync_results_are_stored_as_sent(): void
    {
        $sync = $this->queue(DeviceCommandType::SyncTemplates);

        $this->ack($sync, 'acked', ['status' => 'complete', 'written' => 2, 'deleted' => 1])->assertOk();

        // MySQL's JSON type does not keep key order.
        $this->assertEqualsCanonicalizing(['status' => 'complete', 'written' => 2, 'deleted' => 1], $sync->fresh()->result);
        $this->assertSame(['opened' => true], $this->ackedResult(DeviceCommandType::Unlock, ['opened' => true]));
    }

    // ---------------------------------------------------------------------
    // Real-time template sync
    // ---------------------------------------------------------------------

    #[Test]
    public function suspending_a_member_asks_every_active_panel_to_sync(): void
    {
        $second = Device::factory()->for($this->tenant)->create();
        $dark = Device::factory()->for($this->tenant)->suspended()->create();
        $elsewhere = Device::factory()->create();
        $member = Member::factory()->for($this->tenant)->create();

        $this->actingAsOperator('tenant_admin', $this->tenant);
        $this->patchJson("/api/admin/v1/members/{$member->uuid}", ['status' => MemberStatus::Suspended->value])->assertOk();

        $this->assertSame(1, $this->syncCount($this->device));
        $this->assertSame(1, $this->syncCount($second));
        $this->assertSame(0, $this->syncCount($dark));
        $this->assertSame(0, $this->syncCount($elsewhere));
    }

    #[Test]
    public function editing_a_member_without_a_status_change_queues_nothing(): void
    {
        $member = Member::factory()->for($this->tenant)->create();

        $this->actingAsOperator('tenant_admin', $this->tenant);
        $this->patchJson("/api/admin/v1/members/{$member->uuid}", ['full_name' => 'Alex Example'])->assertOk();

        $this->assertSame(0, $this->syncCount($this->device));
    }

    #[Test]
    public function deleting_and_approving_members_ask_the_panels_to_sync(): void
    {
        $this->actingAsOperator('tenant_admin', $this->tenant);

        $waiting = Member::factory()->for($this->tenant)->create(['approved_at' => null]);
        $this->postJson("/api/admin/v1/members/{$waiting->uuid}/approval")->assertOk();
        $this->assertSame(1, $this->syncCount($this->device));

        $this->pickUpPendingSync();

        $this->deleteJson("/api/admin/v1/members/{$waiting->uuid}")->assertOk();
        $this->assertSame(2, $this->syncCount($this->device));
    }

    #[Test]
    public function a_pending_sync_absorbs_the_next_change_but_a_sent_one_does_not(): void
    {
        $member = Member::factory()->for($this->tenant)->create();
        $this->actingAsOperator('tenant_admin', $this->tenant);

        $this->patchJson("/api/admin/v1/members/{$member->uuid}", ['status' => 'suspended'])->assertOk();
        $this->patchJson("/api/admin/v1/members/{$member->uuid}", ['status' => 'active'])->assertOk();
        $this->assertSame(1, $this->syncCount($this->device));

        // The panel collects it and starts a pass against the older set; the
        // next change needs a pass of its own.
        $this->pickUpPendingSync();

        $this->patchJson("/api/admin/v1/members/{$member->uuid}", ['status' => 'suspended'])->assertOk();
        $this->assertSame(2, $this->syncCount($this->device));
    }

    #[Test]
    public function an_enrolment_asks_the_other_panels_but_not_the_enrolling_one(): void
    {
        $other = Device::factory()->for($this->tenant)->create();

        $this->postJson('/api/v1/enrollments', ['fingerprint_slot' => 7], $this->headers())->assertCreated();

        $this->assertSame(0, $this->syncCount($this->device));
        $this->assertSame(1, $this->syncCount($other));
    }

    // ---------------------------------------------------------------------
    // The doorbell
    // ---------------------------------------------------------------------

    #[Test]
    public function queuing_a_command_rings_the_panels_doorbell_after_the_commit(): void
    {
        $this->enableMqtt();

        DB::transaction(function (): void {
            $this->queue(DeviceCommandType::Unlock);

            // The command row is not visible to the panel yet; neither is
            // the doorbell.
            $this->assertSame([], $this->broker->published);
        });

        $this->assertSame([[
            'topic' => "sa/v1/dev/{$this->device->uuid}/notify",
            'message' => '{"v":1}',
        ]], $this->broker->published);
    }

    #[Test]
    public function the_doorbell_is_silent_while_mqtt_is_off(): void
    {
        $this->queue(DeviceCommandType::Unlock);

        $this->assertSame([], $this->broker->published);
    }

    #[Test]
    public function a_dead_broker_never_breaks_the_operators_request(): void
    {
        $this->enableMqtt();
        $this->broker->down = true;
        $this->actingAsOperator('tenant_admin', $this->tenant);

        $this->postJson("/api/admin/v1/devices/{$this->device->uuid}/unlock")->assertAccepted();

        // The command still waits for the panel's next /health.
        $this->assertSame(1, DeviceCommand::withoutGlobalScopes()->where('type', 'unlock')->count());

        // And the backend stops trying for a while instead of timing out on
        // every command.
        $this->broker->down = false;
        $this->postJson("/api/admin/v1/devices/{$this->device->uuid}/unlock")->assertAccepted();
        $this->assertSame([], $this->broker->published);
    }

    #[Test]
    public function the_test_command_rings_a_bare_doorbell(): void
    {
        $this->enableMqtt();

        $this->artisan('devices:test-command', ['device' => $this->device->device_id, '--doorbell' => true])
            ->assertSuccessful();

        $this->assertCount(1, $this->broker->published);
        $this->assertSame(0, DeviceCommand::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_test_command_queues_a_command_for_one_panel(): void
    {
        $this->artisan('devices:test-command', ['device' => $this->device->uuid, 'type' => 'sync_templates'])
            ->assertSuccessful();

        $this->assertSame(1, $this->syncCount($this->device));
    }

    /**
     * Mosquitto disconnects a login whose roles change — here the backend's
     * own connection — so bootstrap must survive that and read the result back.
     */
    #[Test]
    public function bootstrap_survives_the_broker_dropping_its_own_connection(): void
    {
        $this->enableMqtt();
        config(['access.mqtt.username' => 'sa-backend']);
        $this->broker->dropsOnOwnRoleChange = 'sa-backend';

        $this->artisan('mqtt:bootstrap')->assertSuccessful();
        $this->artisan('mqtt:bootstrap')->expectsOutputToContain('Role already assigned')->assertSuccessful();

        $role = collect($this->broker->commands)->firstWhere('command', 'createRole');
        $this->assertSame([['acltype' => 'publishClientSend', 'topic' => 'sa/v1/dev/+/notify', 'allow' => true]], $role['acls']);
        $this->assertSame(1, collect($this->broker->commands)->where('command', 'addClientRole')->count());
    }

    // ---------------------------------------------------------------------
    // Registration and the realtime login
    // ---------------------------------------------------------------------

    /** A panel whose sensor has not answered leaves both fields out. */
    #[Test]
    public function registration_without_a_sensor_keeps_the_stored_capacity(): void
    {
        $this->device->forceFill(['template_capacity' => 200, 'enrolled_count' => 12])->save();

        $this->postJson('/api/v1/device-registrations', ['firmware_version' => '1.4.0'], $this->headers())
            ->assertCreated()
            ->assertJsonMissingPath('realtime');

        $this->postJson('/api/v1/device-registrations',
            ['firmware_version' => '1.4.0', 'template_capacity' => null, 'enrolled_count' => null], $this->headers())
            ->assertCreated();

        $this->assertSame(200, $this->device->fresh()->template_capacity);
        $this->assertSame(12, $this->device->fresh()->enrolled_count);
    }

    #[Test]
    public function registration_hands_out_a_new_mqtt_password_every_time(): void
    {
        $this->enableMqtt();

        $first = $this->postJson('/api/v1/device-registrations', ['firmware_version' => '1.4.0'], $this->headers())
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('realtime');
        $second = $this->postJson('/api/v1/device-registrations', ['firmware_version' => '1.4.0'], $this->headers())
            ->json('realtime');

        $uuid = $this->device->uuid;
        $this->assertSame([
            'protocol' => 'mqtt',
            'host' => 'mqtt.example.com',
            'port' => 1883,
            'username' => "dev-{$uuid}",
            'client_id' => $uuid,
            'topic' => "sa/v1/dev/{$uuid}/notify",
            'keepalive' => 60,
        ], array_diff_key($first, ['password' => true]));

        $this->assertSame(32, strlen($first['password']));
        $this->assertNotSame($first['password'], $second['password']);
        $this->assertSame([$first['password'], $second['password']], $this->broker->passwordsFor("dev-{$uuid}"));
    }

    #[Test]
    public function the_panels_login_may_subscribe_to_its_own_topic_only(): void
    {
        $this->enableMqtt();

        $this->postJson('/api/v1/device-registrations', [], $this->headers())->assertCreated();

        $role = collect($this->broker->commands)->firstWhere('command', 'createRole');
        $this->assertSame([[
            'acltype' => 'subscribeLiteral',
            'topic' => "sa/v1/dev/{$this->device->uuid}/notify",
            'allow' => true,
        ]], $role['acls']);
    }

    #[Test]
    public function registration_leaves_realtime_out_while_the_broker_is_down(): void
    {
        $this->enableMqtt();
        $this->broker->down = true;

        $this->postJson('/api/v1/device-registrations', [], $this->headers())
            ->assertCreated()
            ->assertJsonMissingPath('realtime');
    }

    #[Test]
    public function registration_is_limited_per_hour_with_retry_after(): void
    {
        foreach (range(1, 10) as $_) {
            $this->postJson('/api/v1/device-registrations', [], $this->headers())->assertCreated();
        }

        $this->postJson('/api/v1/device-registrations', [], $this->headers())
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertHeader('Retry-After');
    }

    #[Test]
    public function the_doorbell_fetch_is_limited_with_retry_after(): void
    {
        foreach (range(1, 30) as $_) {
            $this->getJson('/api/v1/device-commands', $this->headers())->assertOk();
        }

        $response = $this->getJson('/api/v1/device-commands', $this->headers())
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    #[Test]
    public function deactivating_a_door_disables_its_login_and_reactivating_restores_it(): void
    {
        $this->enableMqtt();
        $this->actingAsOperator('tenant_admin', $this->tenant);

        $this->patchJson("/api/admin/v1/devices/{$this->device->uuid}", ['status' => DeviceStatus::Suspended->value])->assertOk();
        $this->patchJson("/api/admin/v1/devices/{$this->device->uuid}", ['status' => DeviceStatus::Active->value])->assertOk();

        $this->assertSame(['disableClient', 'enableClient'], $this->broker->commandNames());
    }

    // ---------------------------------------------------------------------
    // Health parameters
    // ---------------------------------------------------------------------

    #[Test]
    public function health_records_the_doorbell_state_including_zeroes(): void
    {
        $this->getJson('/api/v1/health?mqtt=0&mqtt_reconnects=0', $this->headers())->assertOk();

        $device = $this->device->fresh();
        $this->assertFalse($device->mqtt_connected);
        $this->assertSame(0, $device->mqtt_reconnects);
        $this->assertNotNull($device->mqtt_reported_at);

        $this->getJson('/api/v1/health?mqtt=1&mqtt_reconnects=3', $this->headers());
        $this->assertTrue($this->device->fresh()->mqtt_connected);
        $this->assertSame(3, $this->device->fresh()->mqtt_reconnects);
    }

    #[Test]
    public function an_older_panel_leaves_the_doorbell_state_unreported(): void
    {
        $this->getJson('/api/v1/health?template_capacity=0&enrolled_count=0', $this->headers())->assertOk();

        $this->assertNull($this->device->fresh()->mqtt_connected);
        $this->assertNull($this->device->fresh()->mqtt_reported_at);
        $this->assertSame(200, $this->device->fresh()->template_capacity);
    }

    // ---------------------------------------------------------------------

    /** @param  array<string, mixed>  $payload */
    private function queue(DeviceCommandType $type, array $payload = []): DeviceCommand
    {
        return app(TenantContext::class)->forTenant(
            $this->tenant,
            fn () => app(DeviceCommandService::class)->queue($this->device, $type, $payload),
        );
    }

    /** @param  array<string, mixed>  $result */
    private function ack(DeviceCommand $command, string $status, array $result = []): TestResponse
    {
        return $this->postJson("/api/v1/device-commands/{$command->uuid}/acknowledgements",
            ['status' => $status] + ($result === [] ? [] : ['result' => $result]),
            $this->headers());
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function ackedResult(DeviceCommandType $type, array $result): ?array
    {
        $command = $this->queue($type);
        $this->ack($command, 'acked', $result)->assertOk();

        return $command->fresh()->result;
    }

    private function syncCount(Device $device): int
    {
        return DeviceCommand::withoutGlobalScopes()
            ->where('device_id', $device->id)
            ->where('type', DeviceCommandType::SyncTemplates->value)
            ->count();
    }

    /** As the panel collecting its pending sync_templates would. */
    private function pickUpPendingSync(): void
    {
        DeviceCommand::withoutGlobalScopes()
            ->where('type', DeviceCommandType::SyncTemplates->value)
            ->where('status', DeviceCommandStatus::Pending->value)
            ->update(['status' => DeviceCommandStatus::Sent->value]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->deviceHeaders($this->device, $this->credential);
    }
}
