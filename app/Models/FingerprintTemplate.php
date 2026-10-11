<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FingerprintTemplateStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The backend's copy of a template a panel read back out of its sensor.
 *
 * Biometric data. It is encrypted at rest with the application key, hidden
 * from every serialisation, and no endpoint returns it — only server code can
 * decrypt it. The future sync will push `active` templates back to panels;
 * nothing does yet.
 */
#[Fillable([
    'tenant_id', 'member_id', 'enrollment_id', 'device_id', 'fingerprint_slot',
    'format', 'size', 'sha256', 'data_encrypted', 'status',
])]
#[Hidden(['data_encrypted'])]
class FingerprintTemplate extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected function casts(): array
    {
        return [
            // Raw template bytes in, ciphertext at rest.
            'data_encrypted' => 'encrypted',
            'status' => FingerprintTemplateStatus::class,
            'fingerprint_slot' => 'integer',
            'size' => 'integer',
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

    /** @param  Builder<FingerprintTemplate>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', FingerprintTemplateStatus::Active->value);
    }

    /**
     * Rows that still hold template bytes.
     *
     * @param  Builder<FingerprintTemplate>  $query
     */
    public function scopeRetained(Builder $query): void
    {
        $query->whereIn('status', [
            FingerprintTemplateStatus::Active->value,
            FingerprintTemplateStatus::Superseded->value,
        ]);
    }

    /**
     * What the audit log may record about a template — never the bytes.
     *
     * @return array<string, mixed>
     */
    public function auditAttributes(): array
    {
        return [
            'uuid' => $this->uuid,
            'enrollment_id' => $this->enrollment_id,
            'device_id' => $this->device_id,
            'fingerprint_slot' => $this->fingerprint_slot,
            'format' => $this->format,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'status' => $this->status->value,
        ];
    }
}
