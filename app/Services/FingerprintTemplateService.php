<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FingerprintTemplateError;
use App\Enums\FingerprintTemplateStatus;
use App\Models\Device;
use App\Models\Enrollment;
use App\Models\FingerprintTemplate;
use App\Models\Member;
use App\Support\FingerprintTemplateIntake;
use Illuminate\Support\Facades\Log;

/**
 * The backend's copies of sensor templates (device API 1.1.0).
 *
 * The golden rule: a bad template never costs a member their enrolment. Every
 * problem with one is reported back as `template_status: rejected` and the
 * enrolment goes ahead without it — which is why validation here returns a
 * verdict instead of throwing.
 */
class FingerprintTemplateService
{
    /** Template families a sensor can produce that we know how to keep. */
    public const FORMATS = ['as608'];

    public const MAX_SIZE = 1024;

    /** Base64 of MAX_SIZE bytes, with padding. */
    public const MAX_ENCODED_LENGTH = 1368;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Validate a template in the contract's order; the first failure wins.
     *
     * A missing sub-field fails the first check that needs it. Unknown
     * properties are ignored — newer firmware may send more.
     */
    public function inspect(Device $device, ?int $slot, #[\SensitiveParameter] mixed $template): FingerprintTemplateIntake
    {
        if ($template === null) {
            return FingerprintTemplateIntake::absent();
        }

        $intake = $this->validate($slot, is_array($template) ? $template : []);

        if ($intake->error !== null) {
            // Never the data: device, slot and reason are enough to chase it.
            Log::warning('Fingerprint template rejected', [
                'device_id' => $device->device_id,
                'fingerprint_slot' => $slot,
                'template_error' => $intake->error->value,
            ]);
        }

        return $intake;
    }

    /**
     * Keep an accepted template as the active one for its slot.
     *
     * Runs inside the enrolment's transaction. Whatever was active in that
     * slot has already been superseded by the slot release before this.
     */
    public function store(Device $device, Enrollment $enrollment, FingerprintTemplateIntake $intake): FingerprintTemplate
    {
        $this->supersedeSlot($device, (int) $enrollment->fingerprint_slot);

        $template = FingerprintTemplate::create([
            'tenant_id' => $enrollment->tenant_id,
            'member_id' => $enrollment->member_id,
            'enrollment_id' => $enrollment->id,
            'device_id' => $device->id,
            'fingerprint_slot' => $enrollment->fingerprint_slot,
            'format' => $intake->format,
            'size' => strlen($intake->bytes()),
            'sha256' => $intake->sha256,
            'data_encrypted' => $intake->bytes(),
            'status' => FingerprintTemplateStatus::Active,
        ]);

        $this->audit->log('fingerprint_template.stored', $template, null, $template->auditAttributes());

        return $template;
    }

    /**
     * The panel has handed this slot out again, so whatever we held as its
     * active template is no longer on the sensor.
     */
    public function supersedeSlot(Device $device, int $slot): void
    {
        $previous = FingerprintTemplate::query()
            ->active()
            ->where('device_id', $device->id)
            ->where('fingerprint_slot', $slot)
            ->lockForUpdate()
            ->get();

        foreach ($previous as $template) {
            $before = $template->auditAttributes();
            $template->forceFill(['status' => FingerprintTemplateStatus::Superseded])->save();

            $this->audit->log('fingerprint_template.superseded', $template, $before, $template->auditAttributes());
        }
    }

    /**
     * The panel acknowledged erasing a slot: erase our copy too.
     *
     * Only templates of enrolments that no longer hold the slot. The panel can
     * erase a slot, re-enrol it and post the new enrolment before the
     * acknowledgement gets through; that new template must survive.
     */
    public function eraseSlot(Device $device, int $slot): int
    {
        $templates = FingerprintTemplate::query()
            ->retained()
            ->where('device_id', $device->id)
            ->where('fingerprint_slot', $slot)
            ->whereDoesntHave('enrollment', fn ($enrollment) => $enrollment
                ->holdingSlot()
                ->where('fingerprint_slot', $slot))
            ->get();

        foreach ($templates as $template) {
            $this->erase($template);
        }

        return $templates->count();
    }

    /**
     * A deleted member's templates go with them. Members are soft-deleted, so
     * the foreign key cascade never fires for them.
     */
    public function eraseForMember(Member $member): int
    {
        $templates = FingerprintTemplate::query()
            ->retained()
            ->where('member_id', $member->id)
            ->get();

        foreach ($templates as $template) {
            $this->erase($template);
        }

        return $templates->count();
    }

    private function erase(FingerprintTemplate $template): void
    {
        $before = $template->auditAttributes();

        $template->forceFill([
            'status' => FingerprintTemplateStatus::Deleted,
            'data_encrypted' => null,
        ])->save();

        $this->audit->log('fingerprint_template.deleted', $template, $before, $template->auditAttributes());
    }

    /** @param  array<mixed>  $template */
    private function validate(?int $slot, #[\SensitiveParameter] array $template): FingerprintTemplateIntake
    {
        // 0. The template is the one in that slot; without a slot it is no one's.
        if ($slot === null) {
            return FingerprintTemplateIntake::rejected(FingerprintTemplateError::SlotMissing);
        }

        // 1. A format we know.
        $format = $template['format'] ?? null;

        if (! is_string($format) || ! in_array($format, self::FORMATS, true)) {
            return FingerprintTemplateIntake::rejected(FingerprintTemplateError::UnsupportedFormat);
        }

        // 2. Strict RFC 4648 base64 with padding. PHP's strict mode still
        // skips whitespace and accepts missing padding, so the decoded bytes
        // must also re-encode to exactly what was sent.
        $data = $template['data'] ?? null;

        if (($template['encoding'] ?? null) !== 'base64'
            || ! is_string($data)
            || $data === ''
            || strlen($data) > self::MAX_ENCODED_LENGTH) {
            return FingerprintTemplateIntake::rejected(FingerprintTemplateError::InvalidEncoding);
        }

        $bytes = base64_decode($data, true);

        if ($bytes === false || base64_encode($bytes) !== $data) {
            return FingerprintTemplateIntake::rejected(FingerprintTemplateError::InvalidEncoding);
        }

        // 3. The size the panel claims, which is also what arrived.
        $size = $template['size'] ?? null;

        if (! is_int($size) || $size < 1 || $size > self::MAX_SIZE || strlen($bytes) !== $size) {
            return FingerprintTemplateIntake::rejected(FingerprintTemplateError::SizeMismatch);
        }

        // 4. The hash the panel computed, over the decoded bytes.
        $sha256 = $template['sha256'] ?? null;

        if (! is_string($sha256)
            || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1
            || hash('sha256', $bytes) !== $sha256) {
            return FingerprintTemplateIntake::rejected(FingerprintTemplateError::Sha256Mismatch);
        }

        return FingerprintTemplateIntake::accepted($format, $sha256, $bytes);
    }
}
