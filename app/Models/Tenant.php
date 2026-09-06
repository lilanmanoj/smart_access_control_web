<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The isolation root. A device belongs to exactly one tenant, and device
 * authentication is what resolves it for the device-facing API.
 *
 * Tenant itself is not tenant-scoped: it *is* the scope.
 */
#[Fillable(['name', 'slug', 'status', 'settings'])]
class Tenant extends Model
{
    use HasFactory;
    use HasUuid;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<Member, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Read a per-tenant override, falling back to the application default.
     *
     * Used for the settings the requirements make deployment choices rather
     * than product decisions — whether backup codes are verified online, and
     * whether an OTP requires an existing enrolment.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
