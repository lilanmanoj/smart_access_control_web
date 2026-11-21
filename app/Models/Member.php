<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Services\FingerprintTemplateService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A person who walks through doors.
 */
#[Fillable(['tenant_id', 'full_name', 'phone', 'email', 'status', 'is_admin', 'approved_at', 'approved_by', 'notes'])]
class Member extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected static function booted(): void
    {
        // Members are soft-deleted, so the cascade on fingerprint_templates
        // never fires. Their templates must not outlive them regardless.
        static::deleted(function (Member $member): void {
            app(FingerprintTemplateService::class)->eraseForMember($member);
        });
    }

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'status' => MemberStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasMany<FingerprintTemplate, $this> */
    public function fingerprintTemplates(): HasMany
    {
        return $this->hasMany(FingerprintTemplate::class);
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

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Whether this member is still waiting for an operator's approval.
     *
     * Only ever true while the tenant requires approval: switching the
     * requirement off admits everyone waiting, without rewriting their rows.
     */
    public function awaitsApproval(?Tenant $tenant = null): bool
    {
        if ($this->approved_at !== null) {
            return false;
        }

        $tenant ??= $this->relationLoaded('tenant')
            ? $this->tenant
            : (app(TenantContext::class)->tenant() ?? $this->tenant()->first());

        return $tenant?->requiresMemberApproval() ?? false;
    }

    /** @param  Builder<Member>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', MemberStatus::Active->value);
    }

    /** @param  Builder<Member>  $query */
    public function scopeApproved(Builder $query): void
    {
        $query->whereNotNull('approved_at');
    }
}
