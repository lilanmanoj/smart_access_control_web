<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| Every channel here is tenant-addressed and private. Channel authorisation is
| the last place tenant isolation can be undone — a subscriber who can join
| another tenant's feed reads their door history in real time — so each
| callback re-checks membership rather than trusting the name in the URL.
|
*/

/**
 * A SuperAdmin may listen anywhere; everyone else only to their own tenant.
 */
$belongsToTenant = function (User $user, string $tenantUuid): bool {
    if ($user->isSuperAdmin()) {
        return true;
    }

    if ($user->tenant_id === null) {
        return false;
    }

    return Tenant::query()
        ->whereKey($user->tenant_id)
        ->where('uuid', $tenantUuid)
        ->exists();
};

/** The tenant-wide live event ticker. */
Broadcast::channel('tenant.{tenantUuid}.events', function (User $user, string $tenantUuid) use ($belongsToTenant): bool {
    return $belongsToTenant($user, $tenantUuid) && $user->hasPermissionTo('event.view');
});

/** Device online/offline transitions across the tenant. */
Broadcast::channel('tenant.{tenantUuid}.devices', function (User $user, string $tenantUuid) use ($belongsToTenant): bool {
    return $belongsToTenant($user, $tenantUuid) && $user->hasPermissionTo('device.view');
});

/** One device's own feed: its events, commands and code rotations. */
Broadcast::channel('tenant.{tenantUuid}.devices.{deviceUuid}', function (
    User $user,
    string $tenantUuid,
    string $deviceUuid,
) use ($belongsToTenant): bool {
    if (! $belongsToTenant($user, $tenantUuid) || ! $user->hasPermissionTo('device.view')) {
        return false;
    }

    // The device must belong to the tenant named in the channel, so a valid
    // tenant plus someone else's device id is not a way in.
    return Device::withoutGlobalScopes()
        ->where('uuid', $deviceUuid)
        ->whereHas('tenant', fn ($q) => $q->where('uuid', $tenantUuid))
        ->exists();
});

/** Brute-force and other alerts worth interrupting an operator for. */
Broadcast::channel('tenant.{tenantUuid}.alerts', function (User $user, string $tenantUuid) use ($belongsToTenant): bool {
    return $belongsToTenant($user, $tenantUuid) && $user->hasPermissionTo('event.view');
});
