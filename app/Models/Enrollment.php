<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * (device, fingerprint slot) -> member.
 */
#[Fillable(['tenant_id', 'device_id', 'member_id', 'fingerprint_slot', 'status', 'enrolled_at'])]
class Enrollment extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;

    protected function casts(): array
    {
        return [
            'status' => EnrollmentStatus::class,
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isUsable(): bool
    {
        return $this->status === EnrollmentStatus::Active;
    }

    /** @param  Builder<Enrollment>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', EnrollmentStatus::Active->value);
    }

    /**
     * Enrolments that still hold a slot on the sensor — pending ones do too,
     * because the template was written before the phone number was captured.
     *
     * @param  Builder<Enrollment>  $query
     */
    public function scopeHoldingSlot(Builder $query): void
    {
        $query->whereIn('status', [
            EnrollmentStatus::Pending->value,
            EnrollmentStatus::Active->value,
        ])->whereNotNull('fingerprint_slot');
    }
}
