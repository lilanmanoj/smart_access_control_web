<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\MemberStatus;
use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\User;
use App\Support\FingerprintTemplateIntake;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Enrolment lifecycle.
 *
 * The device writes a fingerprint template into its own sensor flash and then
 * tells the backend which slot it used. Since device API 1.1.0 the panel also
 * sends the backend a copy, and since 1.2.0 panels pull the template set back
 * (`GET /fingerprint-templates`) and delete every slot it does not list. Since
 * 1.4.0 every change here asks the tenant's panels to run that sync at once
 * ({@see TemplateSyncTrigger}), so a withdrawal reaches the sensors in seconds;
 * `delete_enrollment` is no longer queued.
 *
 * Every enrolment that holds a slot owns it in the per-device assignments
 * ({@see TemplateSlotService}); the Enrollment model keeps that in step.
 */
class EnrollmentService
{
    public function __construct(
        private readonly FingerprintTemplateService $templates,
        private readonly TemplateSlotService $slots,
        private readonly TemplateSyncTrigger $sync,
    ) {}

    /**
     * Begin an enrolment from a freshly stored template.
     *
     * The member is created without a phone number; the device captures that
     * on the next screen. Until then the enrolment is `pending`. A template the
     * panel sent along is stored with it only if it was accepted — a rejected
     * one never stops the enrolment.
     */
    public function beginFromSlot(Device $device, ?int $slot, ?FingerprintTemplateIntake $template = null): Enrollment
    {
        return DB::transaction(function () use ($device, $slot, $template): Enrollment {
            if ($slot !== null) {
                $this->releaseConflictingSlot($device, $slot);
            }

            // Anyone can enrol at the panel, so a tenant may hold new members
            // for approval (§9.2). Otherwise they are approved on creation,
            // which keeps turning the requirement on later from locking out
            // everyone enrolled before it.
            $member = Member::create([
                'tenant_id' => $device->tenant_id,
                'full_name' => $this->placeholderName($device, $slot),
                'status' => MemberStatus::Active,
                'approved_at' => $device->tenant()->first()?->requiresMemberApproval() ? null : now(),
            ]);

            $enrollment = Enrollment::create([
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'member_id' => $member->id,
                'fingerprint_slot' => $slot,
                'status' => EnrollmentStatus::Pending,
                'enrolled_at' => now(),
            ]);

            if ($slot !== null && $template?->isAccepted()) {
                $this->templates->store($device, $enrollment, $template);

                // The assignment was made when the enrolment was saved, before
                // the template existed; link it now, so the panel's own slot
                // is listed with its bytes rather than as `template: null`.
                $this->slots->syncEnrollment($enrollment);
            }

            $this->syncEnrolledCount($device);

            // Other panels may need this finger. The enrolling panel already
            // holds it.
            $this->sync->tenantChanged((int) $device->tenant_id, except: $device);

            return $enrollment->setRelation('member', $member);
        });
    }

    /**
     * Attach the phone number captured on the following screen, activating the
     * enrolment.
     *
     * The device may arrive here with no fingerprint at all — the user skipped
     * it, or registration failed and the template was rolled back — so a
     * phone-only member is a legitimate outcome, not an error.
     */
    public function attachPhone(Device $device, Member $member, string $phone, ?int $slot = null): Enrollment
    {
        try {
            $number = PhoneNumber::parse($phone);
        } catch (InvalidArgumentException $exception) {
            throw new ApiException('invalid_phone', $exception->getMessage(), 422);
        }

        return DB::transaction(function () use ($device, $member, $number, $slot): Enrollment {
            $duplicate = Member::query()
                ->where('phone', (string) $number)
                ->where('id', '!=', $member->id)
                ->first();

            if ($duplicate !== null) {
                throw new ApiException(
                    'phone_in_use',
                    'That phone number already belongs to another member.',
                    409
                );
            }

            $member->forceFill(['phone' => (string) $number])->save();

            $enrollment = $member->enrollments()
                ->where('device_id', $device->id)
                ->latest('id')
                ->first();

            if ($enrollment === null) {
                // Phone-only registration: no template was ever stored.
                $enrollment = Enrollment::create([
                    'tenant_id' => $device->tenant_id,
                    'device_id' => $device->id,
                    'member_id' => $member->id,
                    'fingerprint_slot' => null,
                    'status' => EnrollmentStatus::Active,
                    'enrolled_at' => now(),
                ]);
            } else {
                if ($slot !== null && $enrollment->fingerprint_slot === null) {
                    $this->releaseConflictingSlot($device, $slot);
                    $enrollment->fingerprint_slot = $slot;
                }

                $enrollment->forceFill([
                    'fingerprint_slot' => $enrollment->fingerprint_slot,
                    'status' => EnrollmentStatus::Active,
                    'enrolled_at' => $enrollment->enrolled_at ?? now(),
                ])->save();
            }

            $this->syncEnrolledCount($device);

            $this->sync->tenantChanged((int) $device->tenant_id, except: $device);

            return $enrollment->setRelation('member', $member);
        });
    }

    /**
     * Withdraw an enrolment and have the tenant's panels drop it.
     *
     * The enrolment gives up its slot on our side immediately; its assignment
     * goes to `releasing`, and the slot is reused only once a sync report
     * shows it empty. Every active panel is asked to sync now, so the finger
     * stops opening the door within seconds rather than at the next scheduled
     * sync. Our stored copy of the template is erased at once: nothing lists a
     * revoked enrolment, so nothing will ever need it again.
     */
    public function revoke(Enrollment $enrollment, ?User $revokedBy = null): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $revokedBy): Enrollment {
            $slot = $enrollment->fingerprint_slot;

            $enrollment->forceFill([
                'status' => EnrollmentStatus::Revoked,
                'revoked_at' => now(),
                'revoked_by' => $revokedBy?->id,
                // Freed so the slot can be re-enrolled; the unique index only
                // constrains live rows.
                'fingerprint_slot' => null,
            ])->save();

            if ($slot !== null) {
                // Covers the slot's superseded copies too, and never a
                // template a live enrolment holds.
                $this->templates->eraseSlot($enrollment->device, $slot);
            }

            $this->syncEnrolledCount($enrollment->device);

            $this->sync->tenantChanged((int) $enrollment->tenant_id, by: $revokedBy);

            return $enrollment;
        });
    }

    /**
     * Compare the device's reported slot inventory against our records (§9.4).
     *
     * They will drift — a failed rollback, a sensor swap, a manual erase. The
     * backend does not guess which side is right: it flags the disagreement as
     * `orphaned` and leaves it for an operator.
     *
     * @param  list<int>  $occupiedSlots  What the device says it holds.
     * @return array{orphaned: list<int>, missing: list<int>}
     */
    public function reconcile(Device $device, array $occupiedSlots): array
    {
        $ours = $device->enrollments()
            ->holdingSlot()
            ->pluck('fingerprint_slot')
            ->map(fn ($slot): int => (int) $slot)
            ->all();

        // We think a slot is in use, the device does not.
        $orphaned = array_values(array_diff($ours, $occupiedSlots));

        // The device holds a template we have no record of. Slots the backend
        // assigned to pushed members are records too.
        $missing = array_values(array_diff($occupiedSlots, $ours, $this->slots->assignedSlots($device)));

        if ($orphaned !== []) {
            $device->enrollments()
                ->holdingSlot()
                ->whereIn('fingerprint_slot', $orphaned)
                ->update([
                    'status' => EnrollmentStatus::Orphaned->value,
                    'updated_at' => now(),
                ]);
        }

        $device->enrollments()
            ->holdingSlot()
            ->update(['last_reconciled_at' => now(), 'updated_at' => now()]);

        $this->syncEnrolledCount($device);

        return ['orphaned' => $orphaned, 'missing' => $missing];
    }

    /**
     * A slot the sensor has overwritten belongs to whoever holds it now. This
     * happens when a template was erased on the device without the backend
     * hearing about it, and the sensor handed the slot out again. Any template
     * we held for the slot is no longer on the sensor either.
     */
    private function releaseConflictingSlot(Device $device, int $slot): void
    {
        $this->templates->supersedeSlot($device, $slot);

        $device->enrollments()
            ->holdingSlot()
            ->where('fingerprint_slot', $slot)
            ->update([
                'status' => EnrollmentStatus::Orphaned->value,
                'fingerprint_slot' => null,
                'updated_at' => now(),
            ]);
    }

    private function syncEnrolledCount(Device $device): void
    {
        $device->forceFill([
            'enrolled_count' => $device->enrollments()->holdingSlot()->count(),
        ])->saveQuietly();
    }

    private function placeholderName(Device $device, ?int $slot): string
    {
        // Enrolment starts at the panel, where there is no keyboard to type a
        // name on. An operator renames the member from the dashboard; until
        // then this has to be something they can find.
        return $slot === null
            ? "Unnamed member ({$device->device_id})"
            : "Unnamed member ({$device->device_id} slot {$slot})";
    }
}
