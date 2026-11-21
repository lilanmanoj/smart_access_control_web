<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Enums\FingerprintTemplateStatus;
use App\Enums\MemberStatus;
use App\Enums\TemplateSlotOrigin;
use App\Enums\TemplateSlotStatus;
use App\Http\Resources\DeviceResource;
use App\Models\AccessEvent;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\DeviceTemplateSlot;
use App\Models\Enrollment;
use App\Models\FingerprintTemplate;
use App\Models\Member;
use App\Models\TemplateSyncReport;
use App\Models\Tenant;
use App\Services\EnrollmentService;
use App\Support\DeviceContext;
use App\Support\TenantContext;
use Database\Factories\FingerprintTemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Template sync (device API 1.2.0): `GET /fingerprint-templates` and
 * `POST /fingerprint-template-syncs`, and the per-device slot assignments
 * behind them.
 *
 * Panels mirror the response — they delete every slot it does not list — so
 * the failure modes worth testing are the ones that would put the wrong finger
 * in a slot, or erase the right one.
 *
 * Two panels in one tenant throughout: A, where most members enrol, and B,
 * which receives them.
 */
class TemplateSyncTest extends TestCase
{
    use RefreshDatabase;

    /** The contract's 512 synthetic bytes (not a real fingerprint). */
    private const FIXTURE_DATA = 'cHtQC745+ZkdHZBKal34jgOajekuHEcWCmm56bjUgm5VAhJOIEoHQ5PWSCYtZ/nwzKPeN8nmCZlmwCLahBjhflWxEXKQbAhkouKGCfRZiaIXhJjRzlhX+HY0qZ14kUyuXfF6Klwqp2Qm+OAUGQfoeux0raospT7DFXDuS8N5oDvEG7uJTukic/csxeneGKDqBFym3dvp7x13ViqXv+XMCdioswF8p5BWcLHCxZ3AFrXzfvyln2eB51acgW9S0zO4rlYV7I0FDMFQegRvFH9MRRRwQVpnBSBpetkvflKb0eaUY//hE/0HjpO9MNtJAecP/5COM+1lvrIRwmNnlIY05Bsssy/XDTrohQs3ND8n3pp95pcoKpKVbHs7DKwelupdjn4t/QtCRinNmST0Dzi4yvrHjVTA8Bt++NKvQlHKHnCHZq64smZavRKueNYbHvu/D7ggpK1HlmPCkRg24l7EM6EkRSDoLvMoU2jeiO+wRqNt2b1L0wHaPYL+cj81rwnCBxIlLz139rlMhjYoPY3NHyoBPYxjszer/f8YtpcMvDw/ocaTjTGKWwzun1d12X6RTXB0faCCtic2YMZNmX7bFHKzZU+tnw8DGK4Ha7ChU5diQdu/99/YYMHf4KFplKm2KVicXkgRf2qhTxlS2u56hl9BQQqftv7rvs+CaE7aAWY=';

    private const FIXTURE_SHA256 = '1c7fb1b3a4be3a63e8e9a81e871ff1a1e40c237f773a905bb5f5cd91e604a87f';

    private Tenant $tenant;

    private Device $a;

    private DeviceCredential $aCredential;

    private Device $b;

    private DeviceCredential $bCredential;

    private int $phones = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        ['device' => $this->a, 'credential' => $this->aCredential] = $this->provisionDevice($this->tenant);
        ['device' => $this->b, 'credential' => $this->bCredential] = $this->provisionDevice($this->tenant);
    }

    // ---------------------------------------------------------------------
    // §8.1 — parameters
    // ---------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidQueries(): array
    {
        return [
            'no capacity' => [['capacity' => null], 'capacity'],
            'no template_format' => [['template_format' => null], 'template_format'],
            'no device_id' => [['device_id' => null], 'device_id'],
            'unknown format' => [['template_format' => 'r503'], 'template_format'],
            'capacity zero' => [['capacity' => 0], 'capacity'],
            'capacity too large' => [['capacity' => 1025], 'capacity'],
            'capacity not a number' => [['capacity' => 'lots'], 'capacity'],
            'negative offset' => [['offset' => -1], 'offset'],
            'zero limit' => [['limit' => 0], 'limit'],
            'bad since_revision' => [['since_revision' => 'not a revision!'], 'since_revision'],
        ];
    }

    /** @param  array<string, mixed>  $override */
    #[Test]
    #[DataProvider('invalidQueries')]
    public function an_invalid_query_is_a_422_with_fields(array $override, string $field): void
    {
        $this->sync($this->b, $override)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'fields' => [$field]]]);
    }

    #[Test]
    public function another_devices_id_is_a_403(): void
    {
        $this->sync($this->b, ['device_id' => $this->a->device_id])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'device_mismatch');
    }

    #[Test]
    public function a_suspended_device_is_a_403(): void
    {
        $this->b->forceFill(['status' => 'suspended'])->save();

        $this->sync($this->b)->assertForbidden()->assertJsonPath('error.code', 'device_suspended');
    }

    #[Test]
    public function a_limit_above_ten_is_clamped(): void
    {
        $this->sync($this->b, ['limit' => 50])->assertOk()->assertJsonPath('limit', 10);
    }

    #[Test]
    public function the_panels_reported_state_is_recorded(): void
    {
        $this->sync($this->b, ['capacity' => 300, 'enrolled_count' => 4, 'firmware_version' => '1.2.0'])->assertOk();

        $this->b->refresh();
        $this->assertSame(300, $this->b->template_capacity);
        $this->assertSame(4, $this->b->enrolled_count);
        $this->assertSame('1.2.0', $this->b->firmware_version);
    }

    // ---------------------------------------------------------------------
    // §8.2 — the set
    // ---------------------------------------------------------------------

    #[Test]
    public function it_lists_enrolled_here_members_and_pushes_eligible_members_from_elsewhere(): void
    {
        [$here0, $hereSha] = [$this->enrol($this->b, 0, $this->other('b0')), $this->other('b0')['sha256']];
        $here3 = $this->enrol($this->b, 3);
        $pushed = $this->enrolApproved($this->a, 5, $this->fixture());

        $response = $this->sync($this->b)
            ->assertOk()
            ->assertJsonPath('unchanged', false)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('offset', 0)
            ->assertJsonPath('limit', 8)
            ->assertJsonPath('truncated', false)
            ->assertJsonStructure(['revision', 'unchanged', 'total', 'offset', 'limit', 'truncated', 'templates', 'server_time']);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,64}$/', $response->json('revision'));

        $templates = $response->json('templates');
        $this->assertSame([0, 1, 3], array_column($templates, 'slot'));

        // The panel's own enrolment, with its stored bytes.
        $this->assertSame($here0, $templates[0]['member_id']);
        $this->assertSame($hereSha, $templates[0]['template']['sha256']);

        // The pushed member gets the lowest slot nobody holds, carrying
        // exactly what was uploaded at A.
        $this->assertSame($pushed, $templates[1]['member_id']);
        $this->assertSame([
            'format' => 'as608',
            'encoding' => 'base64',
            'size' => 512,
            'sha256' => self::FIXTURE_SHA256,
            'data' => self::FIXTURE_DATA,
        ], $templates[1]['template']);
        $this->assertSame(hash('sha256', base64_decode($templates[1]['template']['data'])), self::FIXTURE_SHA256);

        // Enrolled here without bytes: listed, but `null`, so the panel keeps it.
        $this->assertSame($here3, $templates[2]['member_id']);
        $this->assertNull($templates[2]['template']);
        $this->assertArrayHasKey('template', $templates[2]);

        $row = DeviceTemplateSlot::query()->where('device_id', $this->b->id)->where('slot', 1)->sole();
        $this->assertSame(TemplateSlotOrigin::Pushed, $row->origin);
        $this->assertSame(TemplateSlotStatus::Active, $row->status);
    }

    #[Test]
    public function a_pushed_member_keeps_the_same_slot_sync_after_sync(): void
    {
        $this->enrol($this->b, 0);
        $pushed = $this->enrolApproved($this->a, 9, $this->fixture());

        $first = $this->sync($this->b)->json('templates');
        $this->enrol($this->b, 7);
        $second = $this->sync($this->b)->json('templates');

        $slotOf = fn (array $templates): int => collect($templates)->firstWhere('member_id', $pushed)['slot'];
        $this->assertSame(1, $slotOf($first));
        $this->assertSame(1, $slotOf($second));
    }

    #[Test]
    public function nobody_may_use_the_panel_so_the_set_is_empty(): void
    {
        $this->sync($this->b)
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('templates', []);
    }

    #[Test]
    public function a_device_only_ever_receives_its_own_tenants_templates(): void
    {
        $this->enrolApproved($this->a, 0, $this->fixture());

        ['device' => $foreign, 'credential' => $foreignCredential] = $this->provisionDevice();

        // Each real request starts with nothing bound; in one test process the
        // previous request's tenant would otherwise still be.
        app(TenantContext::class)->forget();
        app(DeviceContext::class)->forget();

        $this->sync($foreign, [], $foreignCredential)
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('templates', []);
    }

    // ---------------------------------------------------------------------
    // §8.3 — paging
    // ---------------------------------------------------------------------

    #[Test]
    public function pages_share_one_revision_ascend_by_slot_and_never_repeat(): void
    {
        $this->enrol($this->b, 0, $this->other('b0'));
        $this->enrol($this->b, 3);
        $this->enrolApproved($this->a, 5, $this->fixture());

        $first = $this->sync($this->b, ['limit' => 2])->assertOk()->assertJsonPath('total', 3);
        $second = $this->sync($this->b, ['limit' => 2, 'offset' => 2])->assertOk()->assertJsonPath('total', 3);

        $this->assertCount(2, $first->json('templates'));
        $this->assertCount(1, $second->json('templates'));
        $this->assertSame($first->json('revision'), $second->json('revision'));

        $slots = array_merge(array_column($first->json('templates'), 'slot'), array_column($second->json('templates'), 'slot'));
        $this->assertSame([0, 1, 3], $slots);
        $this->assertSame($slots, array_values(array_unique($slots)));
    }

    /** The set moving between pages must show as a new revision, never as a mixed set. */
    #[Test]
    public function a_change_between_pages_changes_the_revision(): void
    {
        $this->enrol($this->b, 0);
        $this->enrol($this->b, 1);
        $this->enrol($this->b, 2);

        $first = $this->sync($this->b, ['limit' => 2]);
        $this->enrol($this->b, 3);
        $second = $this->sync($this->b, ['limit' => 2, 'offset' => 2]);

        $this->assertNotSame($first->json('revision'), $second->json('revision'));
        $this->assertSame(4, $second->json('total'));
    }

    // ---------------------------------------------------------------------
    // §8.4 — revisions
    // ---------------------------------------------------------------------

    #[Test]
    public function a_matching_since_revision_is_answered_unchanged(): void
    {
        $this->enrol($this->b, 0, $this->other('b0'));
        $this->enrolApproved($this->a, 5, $this->fixture());

        $revision = $this->sync($this->b)->json('revision');

        $this->sync($this->b, ['since_revision' => $revision])
            ->assertOk()
            ->assertJsonPath('revision', $revision)
            ->assertJsonPath('unchanged', true)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('templates', []);
    }

    #[Test]
    public function any_change_to_the_set_gives_a_new_revision(): void
    {
        $this->enrol($this->b, 0);
        $pushed = $this->enrolApproved($this->a, 5, $this->fixture());
        $other = $this->enrolApproved($this->a, 6, $this->other('a6'));

        $seen = [$this->revision()];

        // A new enrolment.
        $this->enrol($this->b, 4);
        $seen[] = $this->revision();

        // A member suspended.
        Member::query()->where('uuid', $other)->update(['status' => MemberStatus::Suspended->value]);
        $seen[] = $this->revision();

        // A template superseded by a new one: same slot, new bytes.
        $slotBefore = DeviceTemplateSlot::query()->where('device_id', $this->b->id)->where('member_id', $this->memberId($pushed))->active()->sole()->slot;
        $this->replaceTemplate($pushed, 'replacement');
        $seen[] = $this->revision();
        $row = DeviceTemplateSlot::query()->where('device_id', $this->b->id)->where('member_id', $this->memberId($pushed))->active()->sole();
        $this->assertSame($slotBefore, $row->slot);
        $this->assertSame(FingerprintTemplateFactory::apiPayload('replacement')['sha256'], $row->fingerprintTemplate->sha256);

        // The capacity changed.
        $seen[] = $this->revision(['capacity' => 199]);

        $this->assertSame($seen, array_values(array_unique($seen)), 'every change must give a revision not seen before');

        // And nothing changing changes nothing.
        $this->assertSame($seen[4], $this->revision(['capacity' => 199]));
    }

    // ---------------------------------------------------------------------
    // §8.5 — capacity
    // ---------------------------------------------------------------------

    #[Test]
    public function more_than_capacity_keeps_enrolled_here_first_and_truncates(): void
    {
        $here = [$this->enrol($this->b, 0), $this->enrol($this->b, 2)];
        $first = $this->enrolApproved($this->a, 0, $this->other('p1'));
        $this->enrolApproved($this->a, 1, $this->other('p2'));
        $this->enrolApproved($this->a, 2, $this->other('p3'));

        $response = $this->sync($this->b, ['capacity' => 3])
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('truncated', true);

        $templates = collect($response->json('templates'));
        $this->assertCount(3, $templates);
        $this->assertTrue($templates->every(fn (array $t): bool => $t['slot'] < 3));
        $this->assertEqualsCanonicalizing($here, $templates->pluck('member_id')->intersect($here)->values()->all());

        // The oldest enrolment from elsewhere wins the one remaining slot.
        $this->assertSame($first, $templates->firstWhere('slot', 1)['member_id']);

        // Members cut by capacity get no assignment at all.
        $this->assertSame(3, DeviceTemplateSlot::query()->where('device_id', $this->b->id)->count());

        $this->assertTrue($this->b->fresh()->template_set_truncated);
    }

    #[Test]
    public function no_slot_at_or_above_capacity_is_ever_listed(): void
    {
        $this->enrol($this->b, 0);
        $high = $this->enrol($this->b, 150);
        $pushed = $this->enrolApproved($this->a, 0, $this->fixture());

        // A pushed member placed high on a large sensor…
        DeviceTemplateSlot::query()->delete();
        $this->sync($this->b, ['capacity' => 200]);
        DeviceTemplateSlot::query()->where('device_id', $this->b->id)->where('member_id', $this->memberId($pushed))->update(['slot' => 180]);

        // …and then the panel reports a smaller one.
        $response = $this->sync($this->b, ['capacity' => 100])->assertOk()->assertJsonPath('truncated', true);

        $slots = array_column($response->json('templates'), 'slot');
        $this->assertTrue(collect($slots)->every(fn (int $slot): bool => $slot < 100));

        // The pushed member is re-placed within range; their old slot is released.
        $this->assertSame(1, collect($response->json('templates'))->firstWhere('member_id', $pushed)['slot']);
        $this->assertSame(TemplateSlotStatus::Releasing, DeviceTemplateSlot::query()->where('slot', 180)->sole()->status);

        // The enrolment above the capacity cannot be listed.
        $this->assertNotContains($high, array_column($response->json('templates'), 'member_id'));
    }

    // ---------------------------------------------------------------------
    // §8.6 — release before reuse
    // ---------------------------------------------------------------------

    #[Test]
    public function a_released_slot_is_reused_only_after_a_report_shows_it_empty(): void
    {
        $leaving = $this->enrolApproved($this->a, 0, $this->other('leaving'));
        $this->assertSame(0, $this->slotOn($this->b, $leaving));

        Member::query()->where('uuid', $leaving)->update(['status' => MemberStatus::Suspended->value]);

        $response = $this->sync($this->b)->assertJsonPath('total', 0);
        $this->assertNotContains($leaving, array_column($response->json('templates'), 'member_id'));

        $row = DeviceTemplateSlot::query()->where('device_id', $this->b->id)->where('slot', 0)->sole();
        $this->assertSame(TemplateSlotStatus::Releasing, $row->status);

        // Slot 0 is not handed on while the panel may still hold the old finger.
        $second = $this->enrolApproved($this->a, 1, $this->other('second'));
        $this->assertSame(1, $this->slotOn($this->b, $second));

        // An aborted sync deleted nothing, so it proves nothing.
        $this->report($this->b, ['status' => 'aborted', 'abort_reason' => 'revision_changed'])->assertCreated();
        // A report that still shows the slot occupied does not free it either.
        $this->report($this->b, ['slots_present' => [0, 1]])->assertCreated();

        $third = $this->enrolApproved($this->a, 2, $this->other('third'));
        $this->assertSame(2, $this->slotOn($this->b, $third));
        $this->assertSame(TemplateSlotStatus::Releasing, $row->fresh()->status);

        // The panel deleted it: slot 0 is free again.
        $this->report($this->b, ['slots_present' => [1, 2]])->assertCreated();
        $this->assertNull($row->fresh());

        $fourth = $this->enrolApproved($this->a, 3, $this->other('fourth'));
        $this->assertSame(0, $this->slotOn($this->b, $fourth));
    }

    #[Test]
    public function a_revoked_enrolment_here_is_released_and_no_longer_listed(): void
    {
        $member = $this->enrol($this->b, 4, $this->other('b4'));

        app(TenantContext::class)->set($this->tenant);
        app(EnrollmentService::class)->revoke(Enrollment::query()->where('member_id', $this->memberId($member))->sole());

        $this->sync($this->b)->assertJsonPath('total', 0);
        $this->assertSame(TemplateSlotStatus::Releasing, DeviceTemplateSlot::query()->where('slot', 4)->sole()->status);

        // Still resolves while the panel may hold it.
        $this->accessEvent($this->b, 4);
        $this->assertSame($this->memberId($member), AccessEvent::withoutGlobalScopes()->latest('id')->value('member_id'));
    }

    /** Reinstated before the panel confirmed the release: same slot, listed again. */
    #[Test]
    public function a_reinstated_member_gets_their_old_slot_back(): void
    {
        $here = $this->enrol($this->b, 4, $this->other('here'));
        $this->enrol($this->b, 0);
        $pushed = $this->enrolApproved($this->a, 0, $this->other('pushed'));
        $this->assertSame(1, $this->slotOn($this->b, $pushed));

        Member::query()->whereIn('uuid', [$here, $pushed])->update(['status' => MemberStatus::Suspended->value]);
        $this->assertSame([0], array_column($this->sync($this->b)->json('templates'), 'slot'));

        Member::query()->whereIn('uuid', [$here, $pushed])->update(['status' => MemberStatus::Active->value]);
        $templates = $this->sync($this->b)->json('templates');

        $this->assertSame(
            [[1, $pushed], [4, $here]],
            collect($templates)->whereIn('member_id', [$here, $pushed])
                ->map(fn (array $t): array => [$t['slot'], $t['member_id']])->values()->all(),
        );
        $this->assertSame(3, DeviceTemplateSlot::query()->where('device_id', $this->b->id)->active()->count());
        $this->assertSame(0, DeviceTemplateSlot::query()->where('device_id', $this->b->id)->releasing()->count());
    }

    // ---------------------------------------------------------------------
    // §8.7 — the panel's enrolment slot is authoritative
    // ---------------------------------------------------------------------

    #[Test]
    public function enrolling_into_a_pushed_members_slot_moves_the_pushed_member(): void
    {
        $pushed = $this->enrolApproved($this->a, 0, $this->fixture());
        $before = $this->sync($this->b)->json('revision');
        $this->assertSame(0, $this->slotOn($this->b, $pushed, sync: false));

        // The panel never received it (slot 0 was empty on its sensor) and
        // enrolled someone new there.
        $owner = $this->enrol($this->b, 0);

        $owning = DeviceTemplateSlot::query()->where('device_id', $this->b->id)->where('slot', 0)->sole();
        $this->assertSame($this->memberId($owner), $owning->member_id);
        $this->assertSame(TemplateSlotOrigin::EnrolledHere, $owning->origin);

        $moved = DeviceTemplateSlot::query()->where('device_id', $this->b->id)->where('member_id', $this->memberId($pushed))->sole();
        $this->assertSame(1, $moved->slot);
        $this->assertSame(TemplateSlotStatus::Active, $moved->status);

        $response = $this->sync($this->b);
        $this->assertNotSame($before, $response->json('revision'));
        $this->assertSame(
            [[0, $owner], [1, $pushed]],
            array_map(fn (array $t): array => [$t['slot'], $t['member_id']], $response->json('templates')),
        );
    }

    #[Test]
    public function enrolling_into_a_releasing_slot_deletes_the_releasing_row(): void
    {
        $leaving = $this->enrolApproved($this->a, 0, $this->fixture());
        $this->sync($this->b);
        Member::query()->where('uuid', $leaving)->update(['status' => MemberStatus::Suspended->value]);
        $this->sync($this->b);

        $owner = $this->enrol($this->b, 0);

        $this->assertSame(
            [[0, $this->memberId($owner), TemplateSlotStatus::Active]],
            DeviceTemplateSlot::query()->where('device_id', $this->b->id)->get()
                ->map(fn (DeviceTemplateSlot $row): array => [$row->slot, $row->member_id, $row->status])->all(),
        );
    }

    #[Test]
    public function the_enrolments_stored_template_is_linked_to_its_slot(): void
    {
        $member = $this->enrol($this->b, 6, $this->fixture());

        $row = DeviceTemplateSlot::query()->where('device_id', $this->b->id)->sole();
        $this->assertSame(6, $row->slot);
        $this->assertSame($this->memberId($member), $row->member_id);
        $this->assertSame(FingerprintTemplate::query()->sole()->id, $row->fingerprint_template_id);
    }

    // ---------------------------------------------------------------------
    // §8.8 — slot → member goes through the assignments
    // ---------------------------------------------------------------------

    #[Test]
    public function an_event_resolves_to_the_member_pushed_into_that_slot_on_that_panel(): void
    {
        $local = $this->enrol($this->b, 0);
        $x = $this->enrolApproved($this->a, 0, $this->other('x'));
        $y = $this->enrolApproved($this->a, 1, $this->other('y'));

        $this->sync($this->b);
        $this->assertSame(1, $this->slotOn($this->b, $x, sync: false));
        $this->assertSame(2, $this->slotOn($this->b, $y, sync: false));

        // Slot 1 on B is X — pushed there — not Y, who enrolled into slot 1 on A.
        $this->accessEvent($this->b, 1);
        $this->assertSame($this->memberId($x), AccessEvent::withoutGlobalScopes()->latest('id')->value('member_id'));

        $this->accessEvent($this->b, 0);
        $this->assertSame($this->memberId($local), AccessEvent::withoutGlobalScopes()->latest('id')->value('member_id'));

        // And on A, slot 1 is still Y.
        $this->accessEvent($this->a, 1, $this->aCredential);
        $this->assertSame($this->memberId($y), AccessEvent::withoutGlobalScopes()->latest('id')->value('member_id'));
    }

    #[Test]
    public function the_admin_gate_resolves_through_the_assignments(): void
    {
        $admin = $this->enrolApproved($this->a, 3, $this->other('admin'));
        Member::query()->where('uuid', $admin)->update(['is_admin' => true]);
        $this->sync($this->b);
        $slot = $this->slotOn($this->b, $admin, sync: false);

        $this->postJson('/api/v1/authentications', ['enrollment_id' => $slot], $this->headersFor($this->b))
            ->assertOk()
            ->assertJsonPath('is_admin', true)
            ->assertJsonPath('member.member_id', $admin);

        // Slot 3 on B belongs to nobody, whatever it means on A.
        $this->postJson('/api/v1/authentications', ['enrollment_id' => 3], $this->headersFor($this->b))
            ->assertOk()
            ->assertJsonPath('is_admin', false);
    }

    // ---------------------------------------------------------------------
    // §8.9 — reports
    // ---------------------------------------------------------------------

    #[Test]
    public function a_completed_report_frees_released_slots_and_confirms_active_ones(): void
    {
        $staying = $this->enrolApproved($this->a, 0, $this->other('staying'));
        $leaving = $this->enrolApproved($this->a, 1, $this->other('leaving'));
        $this->sync($this->b);

        Member::query()->where('uuid', $leaving)->update(['status' => MemberStatus::Suspended->value]);
        $this->sync($this->b);

        $this->report($this->b, ['slots_present' => [0]])->assertCreated()->assertExactJson(['accepted' => true]);

        $active = DeviceTemplateSlot::query()->where('device_id', $this->b->id)->where('member_id', $this->memberId($staying))->sole();
        $this->assertNotNull($active->confirmed_at);
        $this->assertNull(DeviceTemplateSlot::query()->where('device_id', $this->b->id)->where('member_id', $this->memberId($leaving))->first());

        $released = AuditLog::query()->where('action', 'fingerprint_template_slot.released')->sole();
        $this->assertSame($this->b->id, $released->device_id);
        $this->assertNull($released->user_id);
        $this->assertSame(1, $released->before['slot']);
    }

    #[Test]
    public function a_partial_report_also_frees_released_slots(): void
    {
        $leaving = $this->enrolApproved($this->a, 0, $this->other('leaving'));
        $this->sync($this->b);
        Member::query()->where('uuid', $leaving)->update(['status' => MemberStatus::Suspended->value]);
        $this->sync($this->b);

        $this->report($this->b, [
            'status' => 'partial',
            'failed' => 1,
            'errors' => [['slot' => 9, 'error' => 'store_failed']],
            'slots_present' => [],
        ])->assertCreated();

        $this->assertSame(0, DeviceTemplateSlot::query()->where('device_id', $this->b->id)->count());
    }

    #[Test]
    public function an_aborted_report_changes_no_assignment(): void
    {
        $this->enrolApproved($this->a, 0, $this->other('staying'));
        $leaving = $this->enrolApproved($this->a, 1, $this->other('leaving'));
        $this->sync($this->b);
        Member::query()->where('uuid', $leaving)->update(['status' => MemberStatus::Suspended->value]);
        $this->sync($this->b);

        $before = DeviceTemplateSlot::query()->orderBy('id')->get()->map->only(['slot', 'member_id', 'status', 'confirmed_at'])->all();

        // Even with a slots_present attached, an aborted sync is not ground truth.
        $this->report($this->b, ['status' => 'aborted', 'abort_reason' => 'sensor_error', 'slots_present' => []])
            ->assertCreated();

        $this->assertEquals($before, DeviceTemplateSlot::query()->orderBy('id')->get()->map->only(['slot', 'member_id', 'status', 'confirmed_at'])->all());
        $this->assertSame('sensor_error', TemplateSyncReport::query()->sole()->abort_reason);
        $this->assertNull(TemplateSyncReport::query()->sole()->drift);
    }

    #[Test]
    public function drift_is_recorded_both_ways(): void
    {
        $this->enrolApproved($this->a, 0, $this->other('written'));
        $this->enrolApproved($this->a, 1, $this->other('failed'));
        $this->enrol($this->b, 5); // no bytes: its absence is not drift we can act on
        $this->sync($this->b);

        // The panel failed to write slot 1, and holds a slot 9 nobody owns.
        $this->report($this->b, [
            'status' => 'partial',
            'failed' => 1,
            'errors' => [['slot' => 1, 'error' => 'verify_failed']],
            'slots_present' => [0, 9],
        ])->assertCreated();

        $report = TemplateSyncReport::query()->sole();
        $this->assertSame(['missing_slots' => [1], 'unknown_slots' => [9]], $report->drift);
        $this->assertSame('partial', $report->status->value);
        $this->assertSame(1, $report->failed);
        $this->assertSame([['slot' => 1, 'error' => 'verify_failed']], $report->report['errors']);
    }

    #[Test]
    public function unknown_properties_in_a_report_are_accepted_and_not_kept(): void
    {
        $this->report($this->b, ['quality_histogram' => [1, 2, 3], 'sensor_model' => 'AS608-v2'])->assertCreated();

        $report = TemplateSyncReport::query()->sole();
        $this->assertArrayNotHasKey('sensor_model', $report->report);
        $this->assertSame($this->b->id, $report->device_id);
        $this->assertSame($this->tenant->id, $report->tenant_id);
        $this->assertNotNull($report->received_at);
    }

    #[Test]
    public function an_invalid_report_is_refused(): void
    {
        $this->report($this->b, ['status' => 'finished'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->report($this->b, ['written' => null])->assertStatus(422);
        $this->report($this->b, ['errors' => [['slot' => 1, 'error' => 'gremlins']]])->assertStatus(422);
        $this->report($this->b, ['device_id' => $this->a->device_id])->assertForbidden()->assertJsonPath('error.code', 'device_mismatch');

        $this->assertSame(0, TemplateSyncReport::query()->count());
    }

    #[Test]
    public function a_sync_that_served_templates_is_audited_once_without_bytes(): void
    {
        $this->enrol($this->b, 0);
        $this->enrol($this->b, 1);
        $this->enrolApproved($this->a, 0, $this->fixture());

        $revision = $this->sync($this->b, ['limit' => 2])->json('revision');
        $this->sync($this->b, ['limit' => 2, 'offset' => 2]);
        $this->sync($this->b, ['since_revision' => $revision]);

        $entry = AuditLog::query()->where('action', 'fingerprint_template.sync_served')->sole();
        $this->assertSame($this->b->id, $entry->device_id);
        $this->assertNull($entry->user_id);
        $this->assertSame($revision, $entry->after['revision']);
        $this->assertSame(3, $entry->after['total']);
        $this->assertSame(1, $entry->after['with_template']);

        $this->assertStringNotContainsString(self::FIXTURE_DATA, (string) DB::table('audit_logs')->get()->toJson());
    }

    // ---------------------------------------------------------------------
    // §8.10 — the bytes go nowhere else
    // ---------------------------------------------------------------------

    #[Test]
    public function the_response_is_not_cacheable(): void
    {
        $this->enrolApproved($this->a, 0, $this->fixture());

        $cacheControl = (string) $this->sync($this->b)->assertOk()->headers->get('Cache-Control');

        $this->assertContains('no-store', array_map('trim', explode(',', $cacheControl)));
    }

    #[Test]
    public function no_dashboard_endpoint_or_serializer_exposes_template_bytes(): void
    {
        $member = $this->enrolApproved($this->a, 0, $this->fixture());
        $this->sync($this->b);
        $this->report($this->b, ['slots_present' => [0]]);
        $this->accessEvent($this->b, 0);

        $ciphertext = (string) DB::table('fingerprint_templates')->value('data_encrypted');

        app(TenantContext::class)->set($this->tenant);
        $serialised = [
            DeviceTemplateSlot::query()->with(['fingerprintTemplate', 'member', 'enrollment'])->get()->toJson(),
            TemplateSyncReport::query()->get()->toJson(),
            (string) (new DeviceResource($this->b->fresh()->load('latestTemplateSyncReport')))->response()->getContent(),
        ];

        $this->actingAsOperator('tenant_admin', $this->tenant);

        foreach ([
            "/devices/{$this->b->uuid}", '/devices', "/members/{$member}", '/members',
            '/enrollments', '/audit-logs', '/access-events', '/access-events/export',
        ] as $path) {
            $serialised[$path] = (string) $this->adminGet($path)->assertOk()->getContent();
        }

        foreach ($serialised as $where => $body) {
            $this->assertStringNotContainsString(self::FIXTURE_DATA, $body, (string) $where);
            $this->assertStringNotContainsString(substr(self::FIXTURE_DATA, 0, 64), $body, (string) $where);
            $this->assertStringNotContainsString($ciphertext, $body, (string) $where);
            $this->assertStringNotContainsString('data_encrypted', $body, (string) $where);
        }
    }

    #[Test]
    public function a_dashboard_session_cannot_reach_the_template_endpoint(): void
    {
        $this->enrolApproved($this->a, 0, $this->fixture());
        $this->actingAsOperator('tenant_admin', $this->tenant);

        $this->getJson('/api/v1/fingerprint-templates?'.http_build_query($this->panelQuery($this->b)))
            ->assertUnauthorized()
            ->assertJsonMissingPath('templates');
    }

    #[Test]
    public function both_routes_are_rate_limited_per_device(): void
    {
        foreach (['device.fingerprint-templates.index', 'device.fingerprint-template-syncs.store'] as $name) {
            $this->assertContains('throttle:device-template-sync', Route::getRoutes()->getByName($name)->gatherMiddleware());
        }
    }

    // ---------------------------------------------------------------------
    // §8.11 — pending members
    // ---------------------------------------------------------------------

    #[Test]
    public function a_pending_member_is_listed_only_where_they_enrolled_until_approved(): void
    {
        $member = $this->enrol($this->a, 2, $this->fixture());

        $this->assertSame([$member], array_column($this->sync($this->a, [], $this->aCredential)->json('templates'), 'member_id'));
        $this->assertSame([], $this->sync($this->b)->json('templates'));

        $this->approve($this->a, $member);

        $this->assertSame([$member], array_column($this->sync($this->a, [], $this->aCredential)->json('templates'), 'member_id'));
        $this->assertSame([$member], array_column($this->sync($this->b)->json('templates'), 'member_id'));
    }

    #[Test]
    public function a_suspended_or_deleted_member_is_never_listed(): void
    {
        $suspended = $this->enrolApproved($this->a, 0, $this->other('s'));
        $deleted = $this->enrolApproved($this->a, 1, $this->other('d'));

        Member::query()->where('uuid', $suspended)->update(['status' => MemberStatus::Suspended->value]);
        Member::query()->where('uuid', $deleted)->sole()->delete();

        $this->sync($this->a, [], $this->aCredential)->assertJsonPath('total', 0);
        $this->sync($this->b)->assertJsonPath('total', 0);
    }

    // ---------------------------------------------------------------------
    // Backfill
    // ---------------------------------------------------------------------

    #[Test]
    public function the_backfill_gives_every_slotted_enrolment_an_enrolled_here_row(): void
    {
        $withTemplate = Enrollment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->b->id,
            'member_id' => Member::factory()->for($this->tenant)->create()->id,
            'fingerprint_slot' => 4,
        ]);
        $template = FingerprintTemplate::factory()->forEnrollment($withTemplate)->create();
        $withoutTemplate = Enrollment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->a->id,
            'member_id' => Member::factory()->for($this->tenant)->create()->id,
            'fingerprint_slot' => 8,
        ]);
        Enrollment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $this->a->id,
            'member_id' => Member::factory()->for($this->tenant)->create()->id,
            'fingerprint_slot' => null,
        ]);

        // As the table would be on the day it was created.
        DB::table('device_template_slots')->delete();

        $migration = require database_path('migrations/2026_11_21_000100_create_device_template_slots_table.php');
        (fn () => $this->backfill())->call($migration);

        $rows = DB::table('device_template_slots')->orderBy('slot')->get();

        $this->assertSame(
            [
                [$this->b->id, 4, $withTemplate->member_id, $withTemplate->id, $template->id, 'enrolled_here', 'active'],
                [$this->a->id, 8, $withoutTemplate->member_id, $withoutTemplate->id, null, 'enrolled_here', 'active'],
            ],
            $rows->map(fn ($row): array => [
                (int) $row->device_id, (int) $row->slot, (int) $row->member_id, (int) $row->enrollment_id,
                $row->fingerprint_template_id === null ? null : (int) $row->fingerprint_template_id,
                $row->origin, $row->status,
            ])->all(),
        );
    }

    // ---------------------------------------------------------------------
    // Dashboard
    // ---------------------------------------------------------------------

    #[Test]
    public function the_dashboard_shows_the_last_sync_and_its_drift(): void
    {
        $this->enrolApproved($this->a, 0, $this->fixture());
        $this->sync($this->b, ['capacity' => 1]);
        $this->enrolApproved($this->a, 1, $this->other('cut'));
        $this->sync($this->b, ['capacity' => 1]);
        $this->report($this->b, [
            'status' => 'partial', 'failed' => 1,
            'errors' => [['slot' => 0, 'error' => 'store_failed']],
            'slots_present' => [7],
        ]);

        $this->actingAsOperator('tenant_admin', $this->tenant);

        $this->adminGet("/devices/{$this->b->uuid}")
            ->assertOk()
            ->assertJsonPath('data.template_sync.total', 1)
            ->assertJsonPath('data.template_sync.truncated', true)
            ->assertJsonPath('data.template_sync.active_slots', 1)
            ->assertJsonPath('data.template_sync.last_report.status', 'partial')
            ->assertJsonPath('data.template_sync.last_report.failed', 1)
            ->assertJsonPath('data.template_sync.last_report.errors.0.error', 'store_failed')
            ->assertJsonPath('data.template_sync.last_report.drift.missing_slots', [0])
            ->assertJsonPath('data.template_sync.last_report.drift.unknown_slots', [7]);
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $override  A null value removes the parameter.
     */
    private function sync(Device $device, array $override = [], ?DeviceCredential $credential = null): TestResponse
    {
        $credential ??= $device->is($this->a) ? $this->aCredential : $this->bCredential;
        $query = array_filter(array_merge($this->panelQuery($device), $override), fn ($v): bool => $v !== null);

        return $this->getJson(
            '/api/v1/fingerprint-templates?'.http_build_query($query),
            $this->deviceHeaders($device, $credential),
        );
    }

    /** @return array<string, mixed> What the panel sends. */
    private function panelQuery(Device $device): array
    {
        return ['device_id' => $device->device_id, 'capacity' => 200, 'template_format' => 'as608'];
    }

    /** @param  array<string, mixed>  $override */
    private function revision(array $override = []): string
    {
        return (string) $this->sync($this->b, $override)->assertOk()->json('revision');
    }

    /** @param  array<string, mixed>  $override */
    private function report(Device $device, array $override = []): TestResponse
    {
        $body = array_filter(array_merge([
            'device_id' => $device->device_id,
            'revision' => '9f2c41d07a3be815',
            'status' => 'completed',
            'template_format' => 'as608',
            'capacity' => 200,
            'enrolled_count' => 0,
            'written' => 0,
            'unchanged' => 0,
            'deleted' => 0,
            'retained' => 0,
            'missing' => 0,
            'failed' => 0,
            'duration_ms' => 1840,
            'firmware_version' => '1.0.0',
            'errors' => [],
        ], $override), fn ($v): bool => $v !== null);

        return $this->postJson('/api/v1/fingerprint-template-syncs', $body, $this->headersFor($device));
    }

    /**
     * Enrol on a panel, as the panel does. Returns the member's uuid.
     *
     * @param  array<string, mixed>|null  $template
     */
    private function enrol(Device $device, ?int $slot, ?array $template = null): string
    {
        $body = ['device_id' => $device->device_id, 'fingerprint_slot' => $slot];

        if ($template !== null) {
            $body['fingerprint_template'] = $template;
        }

        $response = $this->postJson('/api/v1/enrollments', $body, $this->headersFor($device))->assertCreated();

        if ($template !== null) {
            $response->assertJsonPath('template_status', 'stored');
        }

        return (string) $response->json('member_id');
    }

    /** The phone screen that follows, which approves the enrolment. */
    private function approve(Device $device, string $member): void
    {
        $this->phones++;

        $this->patchJson("/api/v1/enrollments/{$member}", ['phone' => sprintf('+94771%06d', $this->phones)], $this->headersFor($device))
            ->assertOk()
            ->assertJsonPath('status', 'active');
    }

    /** @param  array<string, mixed>  $template */
    private function enrolApproved(Device $device, int $slot, array $template): string
    {
        $member = $this->enrol($device, $slot, $template);
        $this->approve($device, $member);

        return $member;
    }

    private function accessEvent(Device $device, int $slot, ?DeviceCredential $credential = null): void
    {
        $credential ??= $device->is($this->a) ? $this->aCredential : $this->bCredential;

        $this->postJson('/api/v1/access-events', [
            'events' => [[
                'method' => 'fingerprint',
                'result' => 'denied',
                'reason' => 'no_match',
                'fingerprint_slot' => $slot,
            ]],
        ], $this->deviceHeaders($device, $credential))->assertAccepted();
    }

    /** The slot the member is listed in on a panel (after syncing it, by default). */
    private function slotOn(Device $device, string $member, bool $sync = true): ?int
    {
        if ($sync) {
            $this->sync($device)->assertOk();
        }

        return DeviceTemplateSlot::query()
            ->where('device_id', $device->id)
            ->where('member_id', $this->memberId($member))
            ->active()
            ->value('slot');
    }

    /** Supersede a member's template with a new upload into the same enrolment. */
    private function replaceTemplate(string $member, string $seed): void
    {
        $current = FingerprintTemplate::query()->where('member_id', $this->memberId($member))->active()->sole();
        $current->forceFill(['status' => FingerprintTemplateStatus::Superseded])->save();

        FingerprintTemplate::factory()->forEnrollment($current->enrollment)->seeded($seed)->create();
    }

    private function memberId(string $uuid): int
    {
        return (int) Member::withTrashed()->where('uuid', $uuid)->value('id');
    }

    /** @return array<string, string> */
    private function headersFor(Device $device): array
    {
        return $this->deviceHeaders($device, $device->is($this->a) ? $this->aCredential : $this->bCredential);
    }

    /** @return array<string, mixed> The contract's fixture. */
    private function fixture(): array
    {
        return [
            'format' => 'as608',
            'encoding' => 'base64',
            'size' => 512,
            'sha256' => self::FIXTURE_SHA256,
            'data' => self::FIXTURE_DATA,
        ];
    }

    /** @return array<string, mixed> Another valid template. */
    private function other(string $seed): array
    {
        return FingerprintTemplateFactory::apiPayload($seed);
    }
}
