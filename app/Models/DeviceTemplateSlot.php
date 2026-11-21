<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemplateSlotOrigin;
use App\Enums\TemplateSlotStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Services\TemplateSlotService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which member owns a slot on one panel (device API 1.2.0).
 *
 * The same member sits in different slots on different panels, so this —
 * not the enrolment's original slot — is what turns `(device, slot)` into a
 * member. Rows are managed by {@see TemplateSlotService}; there
 * is no other writer.
 */
#[Fillable([
    'tenant_id', 'device_id', 'slot', 'member_id', 'enrollment_id',
    'fingerprint_template_id', 'origin', 'status', 'confirmed_at',
])]
class DeviceTemplateSlot extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'device_id' => 'integer',
            'member_id' => 'integer',
            'enrollment_id' => 'integer',
            'fingerprint_template_id' => 'integer',
            'origin' => TemplateSlotOrigin::class,
            'status' => TemplateSlotStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<FingerprintTemplate, $this> */
    public function fingerprintTemplate(): BelongsTo
    {
        return $this->belongsTo(FingerprintTemplate::class);
    }

    public function isActive(): bool
    {
        return $this->status === TemplateSlotStatus::Active;
    }

    /** @param  Builder<DeviceTemplateSlot>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', TemplateSlotStatus::Active->value);
    }

    /** @param  Builder<DeviceTemplateSlot>  $query */
    public function scopeReleasing(Builder $query): void
    {
        $query->where('status', TemplateSlotStatus::Releasing->value);
    }

    /**
     * What the audit log may record about an assignment.
     *
     * @return array<string, mixed>
     */
    public function auditAttributes(): array
    {
        return [
            'uuid' => $this->uuid,
            'device_id' => $this->device_id,
            'slot' => $this->slot,
            'member_id' => $this->member_id,
            'origin' => $this->origin->value,
            'status' => $this->status->value,
        ];
    }
}
