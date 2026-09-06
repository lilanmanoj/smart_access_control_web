<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Enums\BackupCodeSetStatus;
use App\Events\BackupCodeBruteForceSuspected;
use App\Models\BackupCode;
use App\Models\BackupCodeSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Backup codes.
 *
 * The firmware validates the response strictly — exactly five codes, exactly
 * six digits each — and rejects the whole set otherwise, disabling backup
 * access until it can refetch. That contract is what most of this suite is
 * pinning down.
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
    public function only_hashes_are_stored(): void
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
    }

    /**
     * Only hashes are stored, so a set cannot be handed out twice — there is
     * no plaintext left. Each fetch therefore mints a fresh set and retires
     * the previous one.
     */
    #[Test]
    public function each_fetch_issues_a_new_set_and_supersedes_the_old_one(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $first = $this->getJson('/api/v1/backup-codes', $headers)->json();
        $second = $this->getJson('/api/v1/backup-codes', $headers)->json();

        $this->assertNotSame($first['set_id'], $second['set_id']);
        $this->assertNotEquals($first['codes'], $second['codes']);

        $this->assertSame(1, BackupCodeSet::withoutGlobalScopes()
            ->where('status', BackupCodeSetStatus::Active->value)->count());
    }

    #[Test]
    public function a_valid_code_is_accepted_and_retires_the_set(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $issued = $this->getJson('/api/v1/backup-codes', $headers)->json();
        $code = $issued['codes'][0];

        $this->postJson('/api/v1/backup-codes/attempts', ['code' => $code, 'accepted' => true], $headers)
            ->assertOk()
            ->assertJson(['logged' => true, 'accepted' => true, 'reason' => 'code_accepted']);

        // Using one retires all five, and a fresh set replaces it.
        $this->assertSame(
            BackupCodeSetStatus::Superseded,
            BackupCodeSet::withoutGlobalScopes()->where('uuid', $issued['set_id'])->firstOrFail()->status,
        );

        $this->assertDatabaseHas('access_events', [
            'method' => 'backup_code',
            'result' => 'granted',
            'reason' => 'code_accepted',
        ]);
    }

    #[Test]
    public function an_unknown_code_is_refused_and_logged(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $this->getJson('/api/v1/backup-codes', $headers);

        $this->postJson('/api/v1/backup-codes/attempts', ['code' => '000000', 'accepted' => false], $headers)
            ->assertOk()
            ->assertJson(['accepted' => false, 'reason' => 'code_invalid']);

        $this->assertDatabaseHas('access_events', [
            'method' => 'backup_code',
            'result' => 'denied',
            'reason' => 'code_invalid',
        ]);
    }

    /**
     * A code from a superseded set means the panel is running on a stale
     * cache, not that someone is guessing. Telling those two apart is what
     * makes the brute-force alert worth acting on.
     */
    #[Test]
    public function a_superseded_code_is_reported_as_expired_not_invalid(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $stale = $this->getJson('/api/v1/backup-codes', $headers)->json('codes')[0];
        $this->getJson('/api/v1/backup-codes', $headers); // supersedes the first set

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
     * The panel tells us what it decided locally. We record that, and reach our
     * own conclusion — a disagreement is worth surfacing, not reconciling.
     */
    #[Test]
    public function a_disagreement_with_the_device_is_recorded(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $headers = $this->deviceHeaders($device, $credential);

        $this->getJson('/api/v1/backup-codes', $headers);

        $this->postJson('/api/v1/backup-codes/attempts',
            ['code' => '000000', 'accepted' => true], $headers)->assertOk();

        $event = \App\Models\AccessEvent::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->assertTrue($event->metadata['decision_mismatch']);
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
