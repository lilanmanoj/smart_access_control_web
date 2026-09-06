<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'device_id', 'type', 'payload', 'status', 'issued_by', 'expires_at'])]
class DeviceCommand extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;

    protected function casts(): array
    {
        return [
            'type' => DeviceCommandType::class,
            'status' => DeviceCommandStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'sent_at' => 'datetime',
            'acked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Commands a polling device should collect: still outstanding, and not
     * past their expiry. A `sent` command is re-offered because the device may
     * have rebooted between collecting it and acting on it — the operations
     * are idempotent on the device side.
     *
     * @param  Builder<DeviceCommand>  $query
     */
    public function scopeDeliverable(Builder $query): void
    {
        $query->whereIn('status', [
            DeviceCommandStatus::Pending->value,
            DeviceCommandStatus::Sent->value,
        ])->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
