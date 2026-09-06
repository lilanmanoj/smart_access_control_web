<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

/**
 * Tenant management is cross-tenant by definition, so it is the one policy
 * that answers on the role rather than on row ownership — and only SuperAdmin
 * holds `tenant.manage` in the first place.
 */
class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('tenant.manage');
    }

    public function view(User $user, Tenant $tenant): bool
    {
        if ($user->hasPermissionTo('tenant.manage')) {
            return true;
        }

        return (int) $user->tenant_id === (int) $tenant->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('tenant.manage');
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->hasPermissionTo('tenant.manage');
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->hasPermissionTo('tenant.manage');
    }

    /** Switching into a tenant is audited wherever it is allowed. */
    public function switchTo(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdmin() && $user->hasPermissionTo('tenant.manage');
    }
}
