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
    /** Payload keys kept for the dashboard and audit trail, never sent to a panel. */
    public const OPERATOR_ONLY_PAYLOAD_KEYS = ['reason'];

    /**
     * Encoding for responses that carry commands to a panel. Unescaped
     * UTF-8 and slashes keep a full response well inside the panel's
     * 4096-byte read; a `\uXXXX` escape costs six bytes per character.
     */
    public const DEVICE_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

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
     * past their expiry.
     *
     * A `sent` command is re-offered until it is acknowledged, because the
     * device may have rebooted between collecting it and acting on it; the
     * panel de-duplicates by id. A time-sensitive command (unlock) is the
     * exception: it is delivered at most once. Opening a door twice because a
     * panel rebooted mid-command is worse than losing one remote unlock, which
     * the operator can simply press again.
     *
     * @param  Builder<DeviceCommand>  $query
     */
    public function scopeDeliverable(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->where('status', DeviceCommandStatus::Pending->value)
                ->orWhere(function (Builder $sent): void {
                    $sent->where('status', DeviceCommandStatus::Sent->value)
                        ->whereNotIn('type', DeviceCommandType::timeSensitiveValues());
                });
        })->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * The command as a panel sees it (device API 1.4.0).
     *
     * `expires_in` is whole seconds left at the moment of serving, rounded
     * up; the panel measures it on its own uptime clock, because its wall
     * clock is only as good as the last `server_time` it saw. `expires_at` is
     * informational. Operator-only payload fields (an unlock's `reason`) stay
     * on the server.
     *
     * @return array{id: string, type: string, payload: array<string, mixed>|\stdClass, expires_at?: string, expires_in?: int}
     */
    public function toDeviceArray(): array
    {
        $payload = $this->devicePayload();

        $out = [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'payload' => $payload === [] ? new \stdClass : $payload,
        ];

        if ($this->expires_at !== null) {
            $out['expires_at'] = $this->expires_at->copy()->utc()->format('Y-m-d\TH:i:s\Z');
            $out['expires_in'] = max(0, (int) ceil(now()->diffInMilliseconds($this->expires_at, false) / 1000));
        }

        return $out;
    }

    /**
     * The part of the payload a panel receives.
     *
     * @return array<string, mixed>
     */
    public function devicePayload(): array
    {
        return array_diff_key($this->payload ?? [], array_flip(self::OPERATOR_ONLY_PAYLOAD_KEYS));
    }
}
