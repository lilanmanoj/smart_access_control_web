<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DenialReason;
use App\Models\Device;
use App\Models\Member;
use Carbon\CarbonImmutable;

/**
 * Whether a member may be admitted right now, and why not if not.
 *
 * Kept in one place so fingerprint, OTP and backup-code paths cannot drift
 * apart on questions like "is a suspended member still allowed in".
 */
class AccessDecisionService
{
    /**
     * Returns null when the member may enter, or the reason they may not.
     */
    public function denialReasonFor(Member $member, Device $device, ?CarbonImmutable $at = null): ?DenialReason
    {
        if (! $member->isActive()) {
            return DenialReason::MemberSuspended;
        }

        if (! $this->withinSchedule($member, $device, $at ?? CarbonImmutable::now())) {
            return DenialReason::OutsideSchedule;
        }

        return null;
    }

    public function admits(Member $member, Device $device, ?CarbonImmutable $at = null): bool
    {
        return $this->denialReasonFor($member, $device, $at) === null;
    }

    /**
     * A member with no schedules is unrestricted. A member with schedules is
     * admitted by the union of the windows that apply to this device — a
     * schedule attached with a null device applies to every door in the
     * tenant.
     */
    private function withinSchedule(Member $member, Device $device, CarbonImmutable $at): bool
    {
        if (! config('access.schedules.enforce')) {
            return true;
        }

        $schedules = $member->relationLoaded('schedules')
            ? $member->schedules
            : $member->schedules()->with('windows')->get();

        $applicable = $schedules->filter(function ($schedule) use ($device): bool {
            if (! $schedule->is_active) {
                return false;
            }

            $scopedDeviceId = $schedule->pivot->device_id;

            return $scopedDeviceId === null || (int) $scopedDeviceId === $device->id;
        });

        if ($applicable->isEmpty()) {
            return true;
        }

        return $applicable->contains(fn ($schedule): bool => $schedule->admits($at));
    }
}
