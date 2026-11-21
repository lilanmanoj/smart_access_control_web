<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\FingerprintTemplateStatus;
use App\Enums\MemberStatus;
use App\Enums\TemplateSlotOrigin;
use App\Enums\TemplateSlotStatus;
use App\Models\Device;
use App\Models\DeviceTemplateSlot;
use App\Models\Enrollment;
use App\Models\FingerprintTemplate;
use App\Models\Member;
use App\Support\TemplateSet;
use App\Support\TemplateSetEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-device slot assignments (device API 1.2.0).
 *
 * Panels mirror whatever `GET /fingerprint-templates` lists, deleting every
 * slot it does not. So the backend decides, per panel, who sits in which slot,
 * and three rules keep that safe:
 *
 *  - **The panel's own enrolment slot is authoritative.** It only enrols into
 *    a slot that is empty on its sensor, so whatever we had there is gone.
 *  - **Assignments are stable.** A member keeps their slot sync after sync;
 *    every move costs the panel a delete and a write.
 *  - **A slot is never reused while the panel may still hold it.** A row that
 *    stops being listed goes to `releasing`, and only a sync report whose
 *    `slots_present` lacks the slot deletes it. Hand it on sooner and the old
 *    member's finger is logged as the new one's.
 *
 * Every write here happens with the device row locked, so two requests for
 * one panel serialise and allocation cannot race.
 */
class TemplateSlotService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    // ---------------------------------------------------------------------
    // Resolution
    // ---------------------------------------------------------------------

    /**
     * Who owns `slot` on this panel — the only correct way to turn a slot a
     * panel reported into a member. A releasing slot still resolves: until the
     * panel confirms it empty, the finger in it is still that member's.
     */
    public function memberIdAt(Device $device, int $slot): ?int
    {
        $memberId = DeviceTemplateSlot::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('device_id', $device->id)
            ->where('slot', $slot)
            ->whereIn('status', TemplateSlotStatus::values())
            ->value('member_id');

        return $memberId === null ? null : (int) $memberId;
    }

    /** @return list<int> Every slot this panel has assigned, active or releasing. */
    public function assignedSlots(Device $device): array
    {
        return DeviceTemplateSlot::query()
            ->where('device_id', $device->id)
            ->pluck('slot')
            ->map(fn ($slot): int => (int) $slot)
            ->all();
    }

    // ---------------------------------------------------------------------
    // Enrolments
    // ---------------------------------------------------------------------

    /**
     * Keep an enrolment's `enrolled_here` assignment in step with it.
     *
     * Called whenever an enrolment is saved, so an enrolment that holds a slot
     * always owns that slot here; one that stops holding it (revoked) releases
     * it, and the panel deletes it at its next sync.
     */
    public function syncEnrollment(Enrollment $enrollment): void
    {
        DB::transaction(function () use ($enrollment): void {
            $device = $this->lockDevice((int) $enrollment->device_id);

            if ($device === null) {
                return;
            }

            if ($this->holdsSlot($enrollment)) {
                $this->claim($device, $enrollment, $this->templateIdFor($enrollment), (int) $device->template_capacity);

                return;
            }

            DeviceTemplateSlot::query()
                ->where('device_id', $device->id)
                ->where('enrollment_id', $enrollment->id)
                ->active()
                ->get()
                ->each(fn (DeviceTemplateSlot $row) => $this->release($row));
        });
    }

    /**
     * Give the enrolment its slot. Whoever else is recorded there loses it:
     * a pushed member not yet delivered moves to another free slot, and
     * anything else (a releasing row, an overwritten enrolment) is deleted —
     * the panel enrolled into an empty slot, so none of it is on the sensor.
     */
    private function claim(Device $device, Enrollment $enrollment, ?int $templateId, int $capacity): DeviceTemplateSlot
    {
        $slot = (int) $enrollment->fingerprint_slot;
        $memberId = (int) $enrollment->member_id;
        $rows = $this->rowsFor($device);

        $occupant = $rows->first(fn (DeviceTemplateSlot $row): bool => $row->slot === $slot);

        // Already this enrolment's (or this member's) slot: reuse the row.
        $own = $occupant !== null
            && ($occupant->enrollment_id === $enrollment->id || $occupant->member_id === $memberId)
                ? $occupant
                : null;

        if ($occupant !== null && $own === null) {
            $rows = $rows->reject(fn (DeviceTemplateSlot $row): bool => $row->is($occupant));

            if ($occupant->origin === TemplateSlotOrigin::Pushed && $occupant->isActive()) {
                $this->relocate($occupant, $rows, $capacity, $slot);
            } else {
                $occupant->delete();
            }
        }

        // One listed slot per member per panel. Any other row the member has
        // here is released, never moved: the panel may still hold that slot.
        $rows->filter(fn (DeviceTemplateSlot $row): bool => $row->member_id === $memberId
            && $row->isActive()
            && ! $row->is($own))
            ->each(fn (DeviceTemplateSlot $row) => $this->release($row));

        $own ??= new DeviceTemplateSlot([
            'tenant_id' => $enrollment->tenant_id,
            'device_id' => $device->id,
        ]);

        // The panel just enrolled into this slot, which it only does when the
        // slot is empty on its sensor; whatever it confirmed before is gone.
        $reenrolled = $own->exists && ! $own->isActive();

        $own->fill([
            'slot' => $slot,
            'member_id' => $memberId,
            'enrollment_id' => $enrollment->id,
            'fingerprint_template_id' => $templateId,
            'origin' => TemplateSlotOrigin::EnrolledHere,
            'status' => TemplateSlotStatus::Active,
        ]);

        if ($reenrolled) {
            $own->confirmed_at = null;
        }

        $own->save();

        return $own;
    }

    /**
     * Move a pushed row out of the way. If the panel has no free slot left
     * for it, it is dropped; the next sync re-places or truncates it.
     *
     * @param  Collection<int, DeviceTemplateSlot>  $others
     */
    private function relocate(DeviceTemplateSlot $row, Collection $others, int $capacity, int $vacating): void
    {
        $taken = $others->pluck('slot')->push($vacating)->flip();
        $free = $this->lowestFree($taken->all(), $capacity);

        if ($free === null) {
            $row->delete();

            return;
        }

        $row->forceFill(['slot' => $free, 'confirmed_at' => null])->save();
    }

    // ---------------------------------------------------------------------
    // The desired set
    // ---------------------------------------------------------------------

    /**
     * Compute — and persist the assignments behind — the set of templates
     * this panel should hold. See `GET /fingerprint-templates` in
     * openapi/device-api-v1.yaml for the contract this implements.
     *
     * Must be called inside a transaction; it locks the device row.
     */
    public function desiredSet(Device $device, int $capacity, string $format): TemplateSet
    {
        $this->lockDevice($device->id);
        $tenantId = (int) $device->tenant_id;

        // Members enrolled on this panel. A pending enrolment counts: a fresh
        // enrolment keeps working at its own door until it is approved.
        $here = Enrollment::query()
            ->where('tenant_id', $tenantId)
            ->where('device_id', $device->id)
            ->holdingSlot()
            ->whereHas('member', fn ($member) => $member->where('status', MemberStatus::Active->value))
            ->orderBy('fingerprint_slot')
            ->get(['id', 'tenant_id', 'device_id', 'member_id', 'fingerprint_slot', 'status']);

        // Usable templates of this format, without their bytes.
        $templates = FingerprintTemplate::query()
            ->join('enrollments', 'enrollments.id', '=', 'fingerprint_templates.enrollment_id')
            ->where('fingerprint_templates.tenant_id', $tenantId)
            ->where('fingerprint_templates.status', FingerprintTemplateStatus::Active->value)
            ->where('fingerprint_templates.format', $format)
            ->whereIn('enrollments.status', [EnrollmentStatus::Pending->value, EnrollmentStatus::Active->value])
            ->whereNotNull('enrollments.fingerprint_slot')
            ->orderBy('fingerprint_templates.id')
            ->get([
                'fingerprint_templates.id',
                'fingerprint_templates.member_id',
                'fingerprint_templates.enrollment_id',
                'fingerprint_templates.sha256',
            ]);

        $templatesById = $templates->keyBy('id');
        $templateIdByEnrollment = $templates->mapWithKeys(fn ($t): array => [(int) $t->enrollment_id => (int) $t->id]);

        // Members enrolled elsewhere may only come here once approved: active,
        // not deleted, and holding at least one active enrolment.
        $hereMembers = $here->pluck('member_id')->map(fn ($id): int => (int) $id)->flip();

        $approved = Member::query()
            ->where('tenant_id', $tenantId)
            ->where('status', MemberStatus::Active->value)
            ->whereIn('id', $templates->pluck('member_id')->unique()->values())
            ->whereHas('enrollments', fn ($enrollment) => $enrollment->where('status', EnrollmentStatus::Active->value))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        // One template per member (their newest), oldest enrolment first.
        $pushedCandidates = $templates
            ->filter(fn ($t): bool => isset($approved[(int) $t->member_id]) && ! isset($hereMembers[(int) $t->member_id]))
            ->groupBy(fn ($t): int => (int) $t->member_id)
            ->map(fn (Collection $group) => $group->sortByDesc('id')->first())
            ->sortBy(fn ($t): int => (int) $t->enrollment_id)
            ->values();

        // Capacity: this panel's own enrolments first, then the rest in order.
        $keptHere = $here->filter(fn (Enrollment $e): bool => (int) $e->fingerprint_slot < $capacity)->keyBy('id');
        $budget = max(0, $capacity - $keptHere->count());
        $keptPushed = $pushedCandidates->take($budget)->keyBy(fn ($t): int => (int) $t->member_id);
        $truncated = $keptHere->count() < $here->count() || $keptPushed->count() < $pushedCandidates->count();

        // An enrolment here without an active assignment in its slot gets one
        // now: one written before this table existed, one changed by a path
        // that bypassed the model, or a member reinstated after suspension.
        $rows = $this->rowsFor($device);
        $listedHere = $rows
            ->filter(fn (DeviceTemplateSlot $row): bool => $row->isActive() && $row->origin === TemplateSlotOrigin::EnrolledHere)
            ->mapWithKeys(fn (DeviceTemplateSlot $row): array => [$row->enrollment_id.':'.$row->slot => true]);
        $claimed = false;

        foreach ($keptHere as $enrollment) {
            if (! isset($listedHere[$enrollment->id.':'.(int) $enrollment->fingerprint_slot])) {
                $this->claim($device, $enrollment, $templateIdByEnrollment[$enrollment->id] ?? null, $capacity);
                $claimed = true;
            }
        }

        if ($claimed) {
            $rows = $this->rowsFor($device);
        }

        // Rows that stay listed are refreshed; the rest are released.
        foreach ($rows->filter(fn (DeviceTemplateSlot $row): bool => $row->isActive()) as $row) {
            if ($row->origin === TemplateSlotOrigin::EnrolledHere) {
                $enrollment = $row->enrollment_id === null ? null : $keptHere->get($row->enrollment_id);

                if ($enrollment === null || (int) $enrollment->fingerprint_slot !== $row->slot) {
                    $this->release($row);

                    continue;
                }

                $this->saveIfDirty($row->fill([
                    'member_id' => (int) $enrollment->member_id,
                    'fingerprint_template_id' => $templateIdByEnrollment[$enrollment->id] ?? null,
                ]));

                continue;
            }

            $template = $keptPushed->get($row->member_id);

            if ($template === null || $row->slot >= $capacity) {
                $this->release($row);

                continue;
            }

            // A superseding template keeps the slot and replaces the bytes.
            $this->saveIfDirty($row->fill(['fingerprint_template_id' => (int) $template->id]));
        }

        // Place pushed members who have no listed slot yet: back into one of
        // their own releasing slots if there is one in range (the panel may
        // well still hold them there), otherwise into the lowest slot no
        // active or releasing row is using.
        $placed = $rows->filter(fn (DeviceTemplateSlot $row): bool => $row->isActive())
            ->pluck('member_id')
            ->flip();
        $taken = $rows->pluck('slot')->flip()->all();
        $releasingByMember = $rows
            ->filter(fn (DeviceTemplateSlot $row): bool => ! $row->isActive() && $row->slot < $capacity)
            ->groupBy('member_id');
        $deferred = 0;

        foreach ($keptPushed as $memberId => $template) {
            if (isset($placed[$memberId])) {
                continue;
            }

            $previous = $releasingByMember->get($memberId)?->sortBy('slot')->first();

            if ($previous !== null) {
                $previous->fill([
                    'origin' => TemplateSlotOrigin::Pushed,
                    'status' => TemplateSlotStatus::Active,
                    'enrollment_id' => null,
                    'fingerprint_template_id' => (int) $template->id,
                ])->save();

                continue;
            }

            $free = $this->lowestFree($taken, $capacity);

            if ($free === null) {
                // Fits the capacity, but every free slot is still releasing.
                $deferred++;

                continue;
            }

            $taken[$free] = true;

            $rows->push(DeviceTemplateSlot::create([
                'tenant_id' => $tenantId,
                'device_id' => $device->id,
                'slot' => $free,
                'member_id' => $memberId,
                'enrollment_id' => null,
                'fingerprint_template_id' => (int) $template->id,
                'origin' => TemplateSlotOrigin::Pushed,
                'status' => TemplateSlotStatus::Active,
            ]));
        }

        $listed = $rows->filter(fn (DeviceTemplateSlot $row): bool => $row->isActive())->sortBy('slot')->values();

        $uuids = Member::query()
            ->whereIn('id', $listed->pluck('member_id')->unique()->values())
            ->pluck('uuid', 'id');

        $entries = $listed->map(function (DeviceTemplateSlot $row) use ($uuids, $templatesById): TemplateSetEntry {
            $template = $row->fingerprint_template_id === null ? null : $templatesById->get($row->fingerprint_template_id);

            return new TemplateSetEntry(
                slot: $row->slot,
                memberUuid: (string) $uuids[$row->member_id],
                templateId: $template === null ? null : (int) $template->id,
                sha256: $template?->sha256,
            );
        })->all();

        return new TemplateSet($format, $capacity, $entries, $truncated, $deferred);
    }

    // ---------------------------------------------------------------------
    // Sync reports
    // ---------------------------------------------------------------------

    /**
     * Apply the ground truth of a sync that ran to the end: what the sensor
     * actually holds now.
     *
     * Must be called inside a transaction; it locks the device row.
     *
     * @param  list<int>  $present  The report's `slots_present`.
     * @return array{missing_slots: list<int>, unknown_slots: list<int>, released_slots: list<int>}
     */
    public function applyReport(Device $device, array $present): array
    {
        $this->lockDevice($device->id);

        $present = array_flip($present);
        $rows = $this->rowsFor($device);
        $released = [];

        foreach ($rows as $row) {
            $held = isset($present[$row->slot]);

            if (! $row->isActive() && ! $held) {
                // Confirmed empty: the slot is free again.
                $this->audit->log('fingerprint_template_slot.released', $row, $row->auditAttributes());
                $row->delete();
                $released[] = $row->slot;

                continue;
            }

            if ($row->isActive() && $held && $row->confirmed_at === null) {
                $row->forceFill(['confirmed_at' => now()])->save();
            }
        }

        $remaining = $rows->reject(fn (DeviceTemplateSlot $row): bool => in_array($row->slot, $released, true));
        $assigned = $remaining->pluck('slot')->flip();

        // Not auto-corrected: the next sync tries again, and the dashboard
        // shows what did not line up.
        $missing = $remaining
            ->filter(fn (DeviceTemplateSlot $row): bool => $row->isActive()
                && $row->fingerprint_template_id !== null
                && ! isset($present[$row->slot]))
            ->pluck('slot')
            ->sort()
            ->values()
            ->all();

        $unknown = collect(array_keys($present))
            ->reject(fn (int $slot): bool => isset($assigned[$slot]))
            ->sort()
            ->values()
            ->all();

        sort($released);

        return ['missing_slots' => $missing, 'unknown_slots' => $unknown, 'released_slots' => $released];
    }

    // ---------------------------------------------------------------------

    private function release(DeviceTemplateSlot $row): void
    {
        $row->forceFill(['status' => TemplateSlotStatus::Releasing])->save();
    }

    private function saveIfDirty(DeviceTemplateSlot $row): void
    {
        if ($row->isDirty()) {
            $row->save();
        }
    }

    private function holdsSlot(Enrollment $enrollment): bool
    {
        return $enrollment->fingerprint_slot !== null
            && in_array($enrollment->status, [EnrollmentStatus::Pending, EnrollmentStatus::Active], true);
    }

    private function templateIdFor(Enrollment $enrollment): ?int
    {
        $id = FingerprintTemplate::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', FingerprintTemplateStatus::Active->value)
            ->max('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The device row, locked for the rest of the transaction. This is the
     * mutex every assignment write for one panel goes through.
     */
    private function lockDevice(int $deviceId): ?Device
    {
        return Device::withoutGlobalScopes()->whereKey($deviceId)->lockForUpdate()->first();
    }

    /** @return Collection<int, DeviceTemplateSlot> */
    private function rowsFor(Device $device): Collection
    {
        return DeviceTemplateSlot::query()
            ->where('device_id', $device->id)
            ->orderBy('slot')
            ->get()
            ->toBase();
    }

    /** @param  array<int, mixed>  $taken  Keyed by slot. */
    private function lowestFree(array $taken, int $capacity): ?int
    {
        for ($slot = 0; $slot < $capacity; $slot++) {
            if (! isset($taken[$slot])) {
                return $slot;
            }
        }

        return null;
    }
}
