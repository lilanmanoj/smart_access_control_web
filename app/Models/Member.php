<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person who walks through doors.
 */
#[Fillable(['tenant_id', 'full_name', 'phone', 'email', 'status', 'is_admin', 'notes'])]
class Member extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'status' => MemberStatus::class,
        ];
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasMany<AccessEvent, $this> */
    public function accessEvents(): HasMany
    {
        return $this->hasMany(AccessEvent::class);
    }

    /** @return HasMany<OtpRequest, $this> */
    public function otpRequests(): HasMany
    {
        return $this->hasMany(OtpRequest::class);
    }

    /** @return BelongsToMany<AccessSchedule, $this> */
    public function schedules(): BelongsToMany
    {
        return $this->belongsToMany(AccessSchedule::class, 'access_schedule_member')
            ->withPivot('device_id')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === MemberStatus::Active;
    }

    /** @param  Builder<Member>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', MemberStatus::Active->value);
    }
}
