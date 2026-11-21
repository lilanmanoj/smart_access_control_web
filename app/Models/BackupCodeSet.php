<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupCodeSetStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A generation of five backup codes for one device.
 *
 * Using any single code supersedes the whole set — modelled as a new row so
 * "which codes were live on this door last Tuesday" stays answerable.
 *
 * The device's current set also keeps its plaintext, encrypted with the
 * application key, so the panel can collect the same set on every sync. Only
 * the device API reads it; it is hidden from serialisation and cleared when
 * the set is superseded or retired.
 */
#[Fillable(['tenant_id', 'device_id', 'status', 'issued_at', 'issued_by', 'reason', 'codes_encrypted'])]
#[Hidden(['codes_encrypted'])]
class BackupCodeSet extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;

    protected function casts(): array
    {
        return [
            'status' => BackupCodeSetStatus::class,
            'issued_at' => 'datetime',
            'superseded_at' => 'datetime',
            'fetched_at' => 'datetime',
            'last_fetched_at' => 'datetime',
            'codes_encrypted' => 'encrypted:array',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return HasMany<BackupCode, $this> */
    public function codes(): HasMany
    {
        return $this->hasMany(BackupCode::class, 'set_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isActive(): bool
    {
        return $this->status === BackupCodeSetStatus::Active;
    }

    /** True once the device has collected this set at least once. */
    public function isOnDevice(): bool
    {
        return $this->fetched_at !== null;
    }

    public function isStale(): bool
    {
        $maxAge = (int) config('access.backup_codes.max_age_days');

        return $maxAge > 0 && $this->issued_at->addDays($maxAge)->isPast();
    }
}
