<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Enums\BackupCodeSetStatus;
use App\Enums\DeviceStatus;
use App\Events\BackupCodeBruteForceSuspected;
use App\Models\AuditLog;
use App\Models\BackupCode;
use App\Models\BackupCodeSet;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Backup codes.
 *
 * The firmware validates the response strictly — exactly five codes, exactly
 * six digits each — and rejects the whole set otherwise. Since device API
 * 1.3.0 the panel also polls `GET /backup-codes` at every sync, so the fetch
 * must re-serve the current set rather than mint one per call.
 */
class BackupCodeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_exactly_five_six_digit_codes(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $response = $this->getJson('/api/v1/backup-codes', $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonStructure(['set_id', 'codes', 'issued_at']);

        $codes = $response->json('codes');

        $this->assertCount(5, $codes);

        foreach ($codes as $code) {
            // Six digits, leading zeros included. The keypad has no letters on
            // it, so anything else is physically unenterable.
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        }

        $this->assertCount(5, array_unique($codes), 'Duplicates would silently reduce the set.');
    }

    #[Test]
    public function codes_are_hashed_and_the_current_plaintext_is_encrypted_at_rest(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $codes = $this->getJson('/api/v1/backup-codes', $this->deviceHeaders($device, $credential))
            ->json('codes');

        foreach (BackupCode::all() as $stored) {
            $this->assertNotContains($stored->code_hash, $codes);
            $this->assertSame(64, strlen($stored->code_hash));
        }

        // last4 is for display; it is not enough to reconstruct anything.
        $this->assertSame(substr($codes[0], -4), BackupCode::query()->first()->last4);

        // The copy kept for the sync is ciphertext in the database.
        $raw = (string) DB::table('backup_code_sets')->value('codes_encrypted');
        $this->assertNotSame('', $raw);
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $raw);
        }
    }

    /**
     * The panel polls at every sync. Minting per call would rotate the codes
     * every few hours and make an operator's rotation impossible to deliver.
     */
    #[Test]
    public function repeated_fetches_return_the_same_set(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $first = $this->getJson('/api/v1/backup-codes', $headers)->assertOk()->json();
        $this->travel(3)->hours();
        $second = $this->getJson('/api/v1/backup-codes', $headers)->assertOk()->json();

        $this->assertSame($first, $second, 'Same set_id, codes and original issued_at.');
        $this->assertSame(1, BackupCodeSet::withoutGlobalScopes()->count());
        $this->assertSame('initial', BackupCodeSet::withoutGlobalScopes()->sole()->reason);
    }

    #[Test]
    public function a_fetch_after_a_rotation_returns_the_rotated_set(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $before = $this->getJson('/api/v1/backup-codes', $headers)->json();

        $rotation = $this->rotateAsOperator($device);

        $after = $this->getJson('/api/v1/backup-codes', $headers)->assertOk()->json();

        $this->assertNotSame($before['set_id'], $after['set_id']);
        $this->assertSame($rotation['set']['id'], $after['set_id']);
        $this->assertSame($rotation['codes'], $after['codes']);
    }

    #[Test]
    public function a_fetch_after_an_accepted_code_mints_an_after_use_set(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $used = $this->getJson('/api/v1/backup-codes', $headers)->json();
        $this->postJson('/api/v1/backup-codes/attempts',
            ['code' => $used['codes'][2], 'accepted' => true], $headers)->assertOk();

        $next = $this->getJson('/api/v1/backup-codes', $headers)->assertOk()->json();

        $this->assertNotSame($used['set_id'], $next['set_id']);
        $this->assertSame('after_use', BackupCodeSet::withoutGlobalScopes()
            ->where('uuid', $next['set_id'])->sole()->reason);

        // And it is stable from then on.
        $this->assertSame($next, $this->getJson('/api/v1/backup-codes', $headers)->json());
    }

    #[Test]
    public function plaintext_is_deleted_when_a_set_is_superseded_or_retired(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $first = $this->getJson('/api/v1/backup-codes', $headers)->json('set_id');
        $rotated = $this->rotateAsOperator($device);

        // Superseded by the rotation.
        $this->assertNull($this->rawPlaintext($first));
        $this->assertNotNull($this->rawPlaintext($rotated['set']['id']));

        // Retired by a used code.
        $this->getJson('/api/v1/backup-codes', $headers);
        $this->postJson('/api/v1/backup-codes/attempts',
            ['code' => $rotated['codes'][0], 'accepted' => true], $headers)->assertOk();

        $this->assertNull($this->rawPlaintext($rotated['set']['id']));
    }

    /**
     * A rotation waits for the door. The dashboard shows when it arrived, and
     * the first collection — not every poll — is audited.
     */
    #[Test]
    public function the_first_and_latest_collection_are_tracked(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $rotation = $this->rotateAsOperator($device);
        $set = fn (): BackupCodeSet => BackupCodeSet::withoutGlobalScopes()
            ->where('uuid', $rotation['set']['id'])->sole();

        $this->assertNull($rotation['set']['fetched_at']);
        $this->assertNull($set()->fetched_at);

        $this->freezeSecond();
        $this->getJson('/api/v1/backup-codes', $headers)->assertOk();
        $firstSeen = $set()->fetched_at;
        $this->assertNotNull($firstSeen);
        $this->assertEquals($firstSeen, $set()->last_fetched_at);

        $this->travel(3)->hours();
        $this->getJson('/api/v1/backup-codes', $headers)->assertOk();

        $this->assertEquals($firstSeen, $set()->fetched_at);
        $this->assertTrue($set()->last_fetched_at->greaterThan($firstSeen));

        $collected = AuditLog::withoutGlobalScopes()->where('action', 'backup_codes.collected')->sole();
        $this->assertSame($device->id, $collected->device_id);
        $this->assertSame($rotation['set']['id'], $collected->after['set_id']);

        // Shown in the dashboard, never with the plaintext.
        $this->actingAsOperator('tenant_admin', $device->tenant);
        $shown = $this->getJson("/api/admin/v1/devices/{$device->uuid}")->assertOk()
            ->json('data.active_backup_code_set');
        $this->assertNotNull($shown['fetched_at']);
        $this->assertNotNull($shown['last_fetched_at']);
        $this->assertArrayNotHasKey('codes_encrypted', $shown);

        $listed = $this->getJson('/api/admin/v1/backup-code-sets')->assertOk();
        $this->assertStringNotContainsString($rotation['codes'][0], $listed->getContent());
        $this->assertStringNotContainsString('codes_encrypted', $listed->getContent());
    }

    #[Test]
    public function an_age_based_rotation_reaches_the_door_at_its_next_fetch(): void
    {
        config(['access.backup_codes.max_age_days' => 30]);

        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $old = $this->getJson('/api/v1/backup-codes', $headers)->json('set_id');

        $this->travel(31)->days();
        $this->artisan('backup-codes:rotate-stale')->assertSuccessful();

        $new = $this->getJson('/api/v1/backup-codes', $headers)->json('set_id');

        $this->assertNotSame($old, $new);
        $this->assertSame('expired', BackupCodeSet::withoutGlobalScopes()->where('uuid', $new)->sole()->reason);
        $this->assertNull($this->rawPlaintext($old));
    }

    #[Test]
    public function a_device_that_is_not_active_is_refused_its_codes(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $this->getJson('/api/v1/backup-codes', $headers)->assertOk();

        $device->forceFill(['status' => DeviceStatus::Suspended])->save();

        $this->getJson('/api/v1/backup-codes', $headers)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'device_suspended')
            ->assertJsonMissingPath('codes');

        $device->forceFill(['status' => DeviceStatus::Provisioned])->save();

        $this->getJson('/api/v1/backup-codes', $headers)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'device_provisioned');
    }

    /**
     * Sets issued before the plaintext was kept cannot be re-served. Nobody
     * saw them, so the first fetch after the upgrade simply replaces them.
     */
    #[Test]
    public function a_set_without_plaintext_is_replaced_on_the_next_fetch(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $legacy = $this->getJson('/api/v1/backup-codes', $headers)->json('set_id');
        DB::table('backup_code_sets')->where('uuid', $legacy)->update(['codes_encrypted' => null]);

        $next = $this->getJson('/api/v1/backup-codes', $headers)->assertOk()->json();

        $this->assertNotSame($legacy, $next['set_id']);
        $this->assertCount(5, $next['codes']);
        $this->assertSame(1, BackupCodeSet::withoutGlobalScopes()->where('status', 'active')->count());
    }

    /**
     * A cached-set panel's sequence for a correct code: report the attempt with
     * its own verdict, report the access event, fetch again. One log row, one
     * new set, nothing queued.
     */
    #[Test]
    public function a_valid_code_from_a_cached_set_panel_is_logged_once_and_retires_the_set(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $issued = $this->getJson('/api/v1/backup-codes', $headers)->json();
        $code = $issued['codes'][0];

        $this->postJson('/api/v1/backup-codes/attempts', ['code' => $code, 'accepted' => true], $headers)
            ->assertOk()
            ->assertJson(['logged' => true, 'accepted' => true, 'reason' => 'code_accepted']);

        // Using one retires all five — without minting a set nobody will see.
        $this->assertSame(
            BackupCodeSetStatus::Superseded,
            BackupCodeSet::withoutGlobalScopes()->where('uuid', $issued['set_id'])->firstOrFail()->status,
        );
        $this->assertSame(0, BackupCodeSet::withoutGlobalScopes()->where('status', 'active')->count());
        $this->assertDatabaseCount('device_commands', 0);

        // The panel's own report is the log row.
        $this->assertDatabaseCount('access_events', 0);
        $this->reportEvent($headers, 'granted', 'code_accepted');
        $this->assertDatabaseCount('access_events', 1);

        // Its next fetch issues the next set.
        $this->getJson('/api/v1/backup-codes', $headers)->assertOk();
        $this->assertSame(1, BackupCodeSet::withoutGlobalScopes()->where('status', 'active')->count());
    }

    #[Test]
    public function a_wrong_code_from_a_cached_set_panel_is_logged_once(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $this->getJson('/api/v1/backup-codes', $headers);

        $this->postJson('/api/v1/backup-codes/attempts', ['code' => '000000', 'accepted' => false], $headers)
            ->assertOk()
            ->assertJson(['accepted' => false, 'reason' => 'code_invalid']);
        $this->reportEvent($headers, 'denied', 'code_invalid');

        $this->assertDatabaseCount('access_events', 1);
    }

    /**
     * A panel that does not state its own verdict gets its row from here. The
     * used set is retired the same way; the next fetch mints `after_use`.
     */
    #[Test]
    public function a_valid_code_without_a_device_verdict_is_logged_and_retires_the_set(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $code = $this->getJson('/api/v1/backup-codes', $headers)->json('codes')[0];

        $this->postJson('/api/v1/backup-codes/attempts', ['code' => $code], $headers)
            ->assertOk()
            ->assertJson(['accepted' => true, 'reason' => 'code_accepted']);

        $this->assertDatabaseHas('access_events', [
            'method' => 'backup_code',
            'result' => 'granted',
            'reason' => 'code_accepted',
        ]);
        $this->assertSame(0, BackupCodeSet::withoutGlobalScopes()->where('status', 'active')->count());

        $this->getJson('/api/v1/backup-codes', $headers)->assertOk();
        $this->assertSame('after_use', BackupCodeSet::withoutGlobalScopes()
            ->where('status', 'active')->sole()->reason);
    }

    #[Test]
    public function an_unknown_code_is_refused_and_logged(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $this->getJson('/api/v1/backup-codes', $headers);

        $this->postJson('/api/v1/backup-codes/attempts', ['code' => '000000'], $headers)
            ->assertOk()
            ->assertJson(['accepted' => false, 'reason' => 'code_invalid']);

        $this->assertDatabaseHas('access_events', [
            'method' => 'backup_code',
            'result' => 'denied',
            'reason' => 'code_invalid',
        ]);
    }

    /**
     * A code from a superseded set means the panel has not synced since a
     * rotation, not that someone is guessing. Telling those two apart is what
     * makes the brute-force alert worth acting on.
     */
    #[Test]
    public function a_superseded_code_is_reported_as_expired_not_invalid(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $stale = $this->getJson('/api/v1/backup-codes', $headers)->json('codes')[0];
        $this->rotateAsOperator($device); // supersedes the first set

        $this->postJson('/api/v1/backup-codes/attempts', ['code' => $stale], $headers)
            ->assertOk()
            ->assertJson(['accepted' => false, 'reason' => 'code_expired']);
    }

    #[Test]
    public function repeated_failures_raise_a_brute_force_alert(): void
    {
        Event::fake([BackupCodeBruteForceSuspected::class]);

        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $this->getJson('/api/v1/backup-codes', $headers);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/backup-codes/attempts',
                ['code' => str_pad((string) $i, 6, '0', STR_PAD_LEFT)], $headers)->assertOk();
        }

        Event::assertDispatched(BackupCodeBruteForceSuspected::class);
    }

    /**
     * The panel tells us what it decided locally, and acts on that. We reach
     * our own conclusion, and a disagreement — usually a panel running on a
     * set we have superseded — is surfaced in the audit log.
     */
    #[Test]
    public function a_disagreement_with_the_device_is_audited(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $stale = $this->getJson('/api/v1/backup-codes', $headers)->json('codes')[0];
        $this->rotateAsOperator($device);

        $this->postJson('/api/v1/backup-codes/attempts',
            ['code' => $stale, 'accepted' => true], $headers)
            ->assertOk()
            ->assertJson(['accepted' => false, 'reason' => 'code_expired']);

        $entry = AuditLog::withoutGlobalScopes()->where('action', 'backup_code.decision_mismatch')->sole();
        $this->assertSame($device->id, $entry->device_id);
        $this->assertSame('code_expired', $entry->after['backend_reason']);
    }

    /**
     * Each attempt is one row now, so the alert fires on the fifth attempt,
     * not the third.
     */
    #[Test]
    public function the_brute_force_alert_counts_each_cached_set_attempt_once(): void
    {
        Event::fake([BackupCodeBruteForceSuspected::class]);

        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $this->getJson('/api/v1/backup-codes', $headers);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/backup-codes/attempts',
                ['code' => str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'accepted' => false], $headers)->assertOk();
            $this->reportEvent($headers, 'denied', 'code_invalid');
        }

        Event::assertNotDispatched(BackupCodeBruteForceSuspected::class);

        $this->postJson('/api/v1/backup-codes/attempts',
            ['code' => '000009', 'accepted' => false], $headers)->assertOk();

        Event::assertDispatched(BackupCodeBruteForceSuspected::class);
    }

    /**
     * Rotate as an operator, then drop the session so the next request is the
     * device's again.
     *
     * @return array{set: array<string, mixed>, codes: list<string>}
     */
    private function rotateAsOperator(Device $device): array
    {
        $this->actingAsOperator('tenant_admin', $device->tenant);
        $response = $this->postJson("/api/admin/v1/devices/{$device->uuid}/backup-codes/rotations")
            ->assertCreated()
            ->json();
        $this->app['auth']->forgetGuards();

        return ['set' => $response['set'], 'codes' => $response['codes']];
    }

    private function rawPlaintext(string $setUuid): ?string
    {
        return DB::table('backup_code_sets')->where('uuid', $setUuid)->value('codes_encrypted');
    }

    /** @param  array<string, string>  $headers */
    private function reportEvent(array $headers, string $result, string $reason): void
    {
        $this->postJson('/api/v1/access-events', [
            'events' => [['method' => 'backup_code', 'result' => $result, 'reason' => $reason]],
        ], $headers)->assertAccepted();
    }

    #[Test]
    public function the_online_only_posture_refuses_to_hand_out_codes(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $device->tenant->forceFill([
            'settings' => ['backup_codes' => ['online_verification_only' => true]],
        ])->save();

        $this->getJson('/api/v1/backup-codes', $this->deviceHeaders($device, $credential))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'online_verification_only');
    }

    #[Test]
    public function server_side_verification_works_for_the_online_only_posture(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $code = $this->getJson('/api/v1/backup-codes', $headers)->json('codes')[0];

        $this->postJson('/api/v1/backup-code-verifications', ['code' => $code], $headers)
            ->assertOk()
            ->assertJson(['verified' => true]);
    }

    #[Test]
    public function it_rejects_a_non_numeric_code(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->postJson('/api/v1/backup-codes/attempts', ['code' => 'ABC123'],
            $this->deviceHeaders($device, $credential))->assertStatus(422);
    }
}
