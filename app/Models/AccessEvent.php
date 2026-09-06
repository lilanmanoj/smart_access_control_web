<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessMethod;
use App\Enums\AccessResult;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One access attempt. Append-only.
 *
 * The model refuses updates outright rather than trusting every caller to
 * remember: an audit trail that can be edited is not an audit trail.
 */
#[Fillable([
    'tenant_id', 'device_id', 'member_id', 'method', 'result', 'reason',
    'occurred_at', 'device_reported_at', 'uptime_ms', 'fingerprint_slot',
    'confidence', 'otp_request_id', 'backup_code_id', 'actor_user_id',
    'idempotency_key', 'metadata',
])]
class AccessEvent extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;

    /** Rows are written once; there is no updated_at to maintain. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'method' => AccessMethod::class,
            'result' => AccessResult::class,
            'occurred_at' => 'datetime',
            'device_reported_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Access events are append-only and cannot be modified.');
        });

        static::deleting(function (): never {
            // Retention pruning deletes in bulk through the query builder,
            // which bypasses this guard by design. A single-model delete is
            // always a mistake.
            throw new LogicException('Access events are append-only and cannot be deleted individually.');
        });
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

    /** @return BelongsTo<OtpRequest, $this> */
    public function otpRequest(): BelongsTo
    {
        return $this->belongsTo(OtpRequest::class);
    }

    /** @return BelongsTo<BackupCode, $this> */
    public function backupCode(): BelongsTo
    {
        return $this->belongsTo(BackupCode::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @param  Builder<AccessEvent>  $query */
    public function scopeDenied(Builder $query): void
    {
        $query->where('result', AccessResult::Denied->value);
    }

    /** @param  Builder<AccessEvent>  $query */
    public function scopeGranted(Builder $query): void
    {
        $query->where('result', AccessResult::Granted->value);
    }
}
