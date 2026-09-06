<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BackupCodeSetStatus;
use App\Enums\DeviceStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A wall-mounted access panel.
 *
 * `device_id` is the string the firmware generates on first boot (SMA_1234).
 * It arrives in a header on every request and is only ever treated as a claim
 * to be checked against the API credential that accompanies it.
 */
#[Fillable([
    'tenant_id', 'device_id', 'name', 'location', 'status',
    'firmware_version', 'template_capacity', 'metadata',
])]
class Device extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'metadata' => 'array',
            'is_online' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_health_at' => 'datetime',
        ];
    }

    /** @return HasMany<DeviceCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(DeviceCredential::class);
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

    /** @return HasMany<DeviceCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    /** @return HasMany<BackupCodeSet, $this> */
    public function backupCodeSets(): HasMany
    {
        return $this->hasMany(BackupCodeSet::class);
    }

    /** @return HasOne<BackupCodeSet, $this> */
    public function activeBackupCodeSet(): HasOne
    {
        return $this->hasOne(BackupCodeSet::class)
            ->where('status', BackupCodeSetStatus::Active->value)
            ->latestOfMany();
    }

    /** @return HasMany<DeviceHealthReport, $this> */
    public function healthReports(): HasMany
    {
        return $this->hasMany(DeviceHealthReport::class);
    }

    /** @return HasMany<OtpRequest, $this> */
    public function otpRequests(): HasMany
    {
        return $this->hasMany(OtpRequest::class);
    }

    /**
     * A device is considered offline once it misses roughly three health
     * polls. The firmware polls every 30s while up and every 10s while down,
     * so a gap this long means the door has genuinely gone dark.
     */
    public function isOnline(): bool
    {
        if ($this->last_health_at === null) {
            return false;
        }

        return $this->last_health_at->diffInSeconds(now()) < config('access.devices.offline_after_seconds');
    }

    /**
     * The lowest slot id not currently claimed by an active enrolment.
     * Returns null when the sensor is full.
     */
    public function nextAvailableSlot(): ?int
    {
        $taken = $this->enrollments()
            ->whereIn('status', [EnrollmentStatus::Pending->value, EnrollmentStatus::Active->value])
            ->whereNotNull('fingerprint_slot')
            ->pluck('fingerprint_slot')
            ->all();

        $taken = array_flip($taken);

        for ($slot = 0; $slot < $this->template_capacity; $slot++) {
            if (! isset($taken[$slot])) {
                return $slot;
            }
        }

        return null;
    }
}
