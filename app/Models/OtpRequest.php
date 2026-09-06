<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OtpStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'device_id', 'member_id', 'phone', 'code_hash', 'status',
    'max_attempts', 'expires_at', 'delivery', 'request_ip',
])]
#[Hidden(['code_hash'])]
class OtpRequest extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;

    protected function casts(): array
    {
        return [
            'status' => OtpStatus::class,
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'delivery' => 'array',
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

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function attemptsLeft(): int
    {
        return max(0, $this->max_attempts - $this->attempts);
    }

    public function isVerifiable(): bool
    {
        return $this->status->isOpen()
            && ! $this->isExpired()
            && $this->attemptsLeft() > 0;
    }
}
