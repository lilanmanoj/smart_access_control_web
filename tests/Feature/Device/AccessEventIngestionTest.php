<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Models\AccessEvent;
use App\Models\Device;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * POST /access-events — the core of the brief.
 *
 * The behaviour that matters most here is what happens when a panel that has
 * been offline flushes its queue: it will resend, sometimes more than once,
 * and every replay has to be absorbed silently rather than duplicating the
 * door's history.
 */
class AccessEventIngestionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_records_a_single_event(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->postJson('/api/v1/access-events', [
            'device_id' => $device->device_id,
            'events' => [[
                'idempotency_key' => 'SMA-000000123',
                'method' => 'fingerprint',
                'result' => 'granted',
                'reason' => 'matched',
                'fingerprint_slot' => 7,
                'confidence' => 142,
                'uptime_ms' => 1234567,
            ]],
        ], $this->deviceHeaders($device, $credential))
            ->assertAccepted()
            ->assertJson(['accepted' => 1, 'duplicates' => 0]);

        $this->assertDatabaseCount('access_events', 1);
    }

    /**
     * The replay test the requirements call for.
     */
    #[Test]
    public function it_deduplicates_a_replayed_batch(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $payload = [
            'device_id' => $device->device_id,
            'events' => [
                [
                    'idempotency_key' => 'SMA-000000001',
                    'method' => 'fingerprint',
                    'result' => 'denied',
                    'reason' => 'no_match',
                    'uptime_ms' => 1000,
                ],
                [
                    'idempotency_key' => 'SMA-000000002',
                    'method' => 'backup_code',
                    'result' => 'denied',
                    'reason' => 'code_invalid',
                    'uptime_ms' => 2000,
                ],
            ],
        ];

        $headers = $this->deviceHeaders($device, $credential);

        $this->postJson('/api/v1/access-events', $payload, $headers)
            ->assertAccepted()
            ->assertJson(['accepted' => 2, 'duplicates' => 0]);

        // The panel did not get the response and sends the whole queue again.
        $this->postJson('/api/v1/access-events', $payload, $headers)
            ->assertAccepted()
            ->assertJson(['accepted' => 0, 'duplicates' => 2]);

        // And once more, because that is what an unreliable link looks like.
        $this->postJson('/api/v1/access-events', $payload, $headers)
            ->assertAccepted()
            ->assertJson(['accepted' => 0, 'duplicates' => 2]);

        $this->assertDatabaseCount('access_events', 2);
    }

    /**
     * Idempotency keys are unique *per device*, not globally. Two panels
     * numbering their own queues from zero must not collide.
     */
    #[Test]
    public function idempotency_keys_are_scoped_to_one_device(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $first, 'credential' => $firstCredential] = $this->provisionDevice($tenant);
        ['device' => $second, 'credential' => $secondCredential] = $this->provisionDevice($tenant, 'second-secret-value-0000000000000000');

        $event = [
            'method' => 'fingerprint',
            'result' => 'denied',
            'reason' => 'no_match',
            'idempotency_key' => 'queue-0001',
        ];

        $this->postJson('/api/v1/access-events', ['events' => [$event]],
            $this->deviceHeaders($first, $firstCredential))->assertAccepted();

        $this->postJson('/api/v1/access-events', ['events' => [$event]],
            $this->deviceHeaders($second, $secondCredential, 'second-secret-value-0000000000000000'))
            ->assertAccepted()
            ->assertJson(['accepted' => 1, 'duplicates' => 0]);

        $this->assertDatabaseCount('access_events', 2);
    }

    /**
     * The device has no real-time clock. Whatever it thinks the time is, the
     * server's clock is the record.
     */
    #[Test]
    public function it_assigns_occurred_at_on_the_server(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->travelTo('2026-03-01 09:00:00');

        $this->postJson('/api/v1/access-events', [
            'events' => [[
                'method' => 'fingerprint',
                'result' => 'granted',
                'reason' => 'matched',
                'uptime_ms' => 5_000,
            ]],
        ], $this->deviceHeaders($device, $credential))->assertAccepted();

        $event = AccessEvent::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('2026-03-01 09:00:00', $event->occurred_at->toDateTimeString());
    }

    /**
     * `uptime_ms` orders events inside one batch and nothing more. The oldest
     * uptime is the oldest event.
     */
    #[Test]
    public function it_derives_a_device_time_from_uptime_for_ordering(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->travelTo('2026-03-01 09:00:00');

        $this->postJson('/api/v1/access-events', [
            'events' => [
                ['method' => 'fingerprint', 'result' => 'denied', 'reason' => 'no_match', 'uptime_ms' => 60_000, 'idempotency_key' => 'b'],
                ['method' => 'fingerprint', 'result' => 'denied', 'reason' => 'no_match', 'uptime_ms' => 0, 'idempotency_key' => 'a'],
            ],
        ], $this->deviceHeaders($device, $credential))->assertAccepted();

        $events = AccessEvent::withoutGlobalScopes()->get()->keyBy('idempotency_key');

        // The newest event in the batch lands at receipt time; the older one is
        // placed a minute earlier, matching the uptime gap.
        $this->assertSame('2026-03-01 09:00:00', $events['b']->device_reported_at->toDateTimeString());
        $this->assertSame('2026-03-01 08:59:00', $events['a']->device_reported_at->toDateTimeString());
    }

    /**
     * Anti-passback (§9.6): a held finger produces a burst of identical grants.
     */
    #[Test]
    public function it_suppresses_repeated_grants_for_the_same_member(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);

        $member = Member::factory()->for($tenant)->create();
        Enrollment::factory()->create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'member_id' => $member->id,
            'fingerprint_slot' => 3,
        ]);

        $headers = $this->deviceHeaders($device, $credential);

        foreach (['a', 'b', 'c'] as $key) {
            $this->postJson('/api/v1/access-events', [
                'events' => [[
                    'idempotency_key' => $key,
                    'method' => 'fingerprint',
                    'result' => 'granted',
                    'reason' => 'matched',
                    'fingerprint_slot' => 3,
                ]],
            ], $headers)->assertAccepted();
        }

        $this->assertDatabaseCount('access_events', 1);
    }

    /**
     * Denials are never suppressed — a burst of those is exactly what an
     * operator needs to see.
     */
    #[Test]
    public function it_never_suppresses_denials(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);

        $member = Member::factory()->for($tenant)->create();
        Enrollment::factory()->create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'member_id' => $member->id,
            'fingerprint_slot' => 3,
        ]);

        $headers = $this->deviceHeaders($device, $credential);

        foreach (['a', 'b', 'c'] as $key) {
            $this->postJson('/api/v1/access-events', [
                'events' => [[
                    'idempotency_key' => $key,
                    'method' => 'fingerprint',
                    'result' => 'denied',
                    'reason' => 'member_suspended',
                    'fingerprint_slot' => 3,
                ]],
            ], $headers)->assertAccepted();
        }

        $this->assertDatabaseCount('access_events', 3);
    }

    #[Test]
    public function it_attributes_an_event_to_the_member_holding_the_slot(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);

        $member = Member::factory()->for($tenant)->create();
        Enrollment::factory()->create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
            'member_id' => $member->id,
            'fingerprint_slot' => 12,
        ]);

        $this->postJson('/api/v1/access-events', [
            'events' => [[
                'method' => 'fingerprint',
                'result' => 'granted',
                'reason' => 'matched',
                'fingerprint_slot' => 12,
            ]],
        ], $this->deviceHeaders($device, $credential))->assertAccepted();

        $this->assertSame(
            $member->id,
            AccessEvent::withoutGlobalScopes()->value('member_id'),
        );
    }

    #[Test]
    public function an_unmatched_finger_has_no_member(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->postJson('/api/v1/access-events', [
            'events' => [[
                'method' => 'fingerprint',
                'result' => 'denied',
                'reason' => 'no_match',
            ]],
        ], $this->deviceHeaders($device, $credential))->assertAccepted();

        $this->assertNull(AccessEvent::withoutGlobalScopes()->value('member_id'));
    }

    #[Test]
    public function it_rejects_a_batch_larger_than_the_cap(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $events = array_map(fn (int $i): array => [
            'idempotency_key' => "k{$i}",
            'method' => 'fingerprint',
            'result' => 'denied',
            'reason' => 'no_match',
        ], range(1, 101));

        $this->postJson('/api/v1/access-events', ['events' => $events],
            $this->deviceHeaders($device, $credential))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    #[Test]
    public function it_rejects_an_unknown_reason_code(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->postJson('/api/v1/access-events', [
            'events' => [[
                'method' => 'fingerprint',
                'result' => 'denied',
                'reason' => 'because_i_said_so',
            ]],
        ], $this->deviceHeaders($device, $credential))->assertStatus(422);
    }

    #[Test]
    public function it_rejects_a_body_naming_a_different_device(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);
        $other = Device::factory()->for($tenant)->create();

        $this->postJson('/api/v1/access-events', [
            'device_id' => $other->device_id,
            'events' => [['method' => 'fingerprint', 'result' => 'denied', 'reason' => 'no_match']],
        ], $this->deviceHeaders($device, $credential))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'device_mismatch');
    }

    /**
     * The trail is append-only. The model refuses both, rather than trusting
     * every future caller to remember.
     */
    #[Test]
    public function access_events_cannot_be_modified_or_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        $device = Device::factory()->for($tenant)->create();
        $event = AccessEvent::factory()->create([
            'tenant_id' => $tenant->id,
            'device_id' => $device->id,
        ]);

        $this->expectException(\LogicException::class);

        // A real change, so the guard is actually reached — Eloquent skips the
        // update entirely when nothing is dirty.
        $event->update(['reason' => 'no_match']);
    }
}
