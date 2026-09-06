<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccessSchedule;
use App\Models\Device;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\AccessDecisionService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Scheduled access (§9.7).
 *
 * The rule that is easy to get backwards: a member with no schedule is
 * unrestricted, and attaching one *narrows* their access. Adding a schedule
 * can never grant access that was not already there.
 */
class AccessScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Device $device;

    private AccessDecisionService $decisions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($this->tenant);

        $this->device = Device::factory()->for($this->tenant)->create();
        $this->decisions = app(AccessDecisionService::class);
    }

    #[Test]
    public function a_member_with_no_schedule_is_unrestricted(): void
    {
        $member = Member::factory()->for($this->tenant)->create();

        // 3am on a Sunday.
        $at = CarbonImmutable::parse('2026-03-01 03:00:00', 'UTC');

        $this->assertTrue($this->decisions->admits($member, $this->device, $at));
    }

    #[Test]
    public function a_scheduled_member_is_admitted_inside_the_window(): void
    {
        $member = $this->memberWithSchedule(weekday: 1, from: '08:00:00', to: '18:00:00');

        // Monday, mid-morning.
        $at = CarbonImmutable::parse('2026-03-02 09:30:00', 'UTC');

        $this->assertTrue($this->decisions->admits($member, $this->device, $at));
    }

    #[Test]
    public function a_scheduled_member_is_refused_outside_the_window(): void
    {
        $member = $this->memberWithSchedule(weekday: 1, from: '08:00:00', to: '18:00:00');

        // Monday, late evening.
        $at = CarbonImmutable::parse('2026-03-02 22:00:00', 'UTC');

        $this->assertSame(
            'outside_schedule',
            $this->decisions->denialReasonFor($member, $this->device, $at)?->value,
        );
    }

    #[Test]
    public function a_scheduled_member_is_refused_on_a_day_with_no_window(): void
    {
        $member = $this->memberWithSchedule(weekday: 1, from: '08:00:00', to: '18:00:00');

        // Tuesday, inside Monday's hours but not Monday.
        $at = CarbonImmutable::parse('2026-03-03 09:30:00', 'UTC');

        $this->assertFalse($this->decisions->admits($member, $this->device, $at));
    }

    /**
     * A door in Colombo should not open on UTC office hours.
     */
    #[Test]
    public function windows_are_evaluated_in_the_schedules_own_timezone(): void
    {
        $member = $this->memberWithSchedule(
            weekday: 1,
            from: '08:00:00',
            to: '18:00:00',
            timezone: 'Asia/Colombo',
        );

        // 04:00 UTC on Monday is 09:30 in Colombo — inside the window.
        $this->assertTrue($this->decisions->admits(
            $member,
            $this->device,
            CarbonImmutable::parse('2026-03-02 04:00:00', 'UTC'),
        ));

        // 20:00 UTC Monday is 01:30 Tuesday in Colombo — outside it.
        $this->assertFalse($this->decisions->admits(
            $member,
            $this->device,
            CarbonImmutable::parse('2026-03-02 20:00:00', 'UTC'),
        ));
    }

    #[Test]
    public function a_suspended_member_is_refused_regardless_of_schedule(): void
    {
        $member = Member::factory()->for($this->tenant)->suspended()->create();

        $this->assertSame(
            'member_suspended',
            $this->decisions->denialReasonFor($member, $this->device)?->value,
        );
    }

    #[Test]
    public function an_inactive_schedule_is_not_enforced(): void
    {
        $member = $this->memberWithSchedule(weekday: 1, from: '08:00:00', to: '18:00:00');
        $member->schedules->first()->forceFill(['is_active' => false])->save();

        $at = CarbonImmutable::parse('2026-03-02 22:00:00', 'UTC');

        $this->assertTrue($this->decisions->admits($member->fresh(), $this->device, $at));
    }

    /**
     * A schedule scoped to one device does not restrict any other door.
     */
    #[Test]
    public function a_device_scoped_schedule_only_applies_to_that_device(): void
    {
        $member = $this->memberWithSchedule(
            weekday: 1,
            from: '08:00:00',
            to: '18:00:00',
            scopeToDevice: true,
        );

        $otherDoor = Device::factory()->for($this->tenant)->create();
        $at = CarbonImmutable::parse('2026-03-02 22:00:00', 'UTC');

        $this->assertFalse($this->decisions->admits($member, $this->device, $at));
        $this->assertTrue($this->decisions->admits($member->fresh(), $otherDoor, $at));
    }

    private function memberWithSchedule(
        int $weekday,
        string $from,
        string $to,
        string $timezone = 'UTC',
        bool $scopeToDevice = false,
    ): Member {
        $member = Member::factory()->for($this->tenant)->create();

        $schedule = AccessSchedule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test window',
            'timezone' => $timezone,
            'is_active' => true,
        ]);

        $schedule->windows()->create([
            'weekday' => $weekday,
            'starts_at' => $from,
            'ends_at' => $to,
        ]);

        $member->schedules()->attach($schedule->id, [
            'device_id' => $scopeToDevice ? $this->device->id : null,
        ]);

        return $member->load('schedules.windows');
    }
}
