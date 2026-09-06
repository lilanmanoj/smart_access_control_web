<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Two questions every policy in this application has to answer:
 * does this user hold the permission, and is this row theirs to touch.
 *
 * Permissions are checked by name against spatie/laravel-permission, never by
 * comparing role strings — roles are a bundle of permissions, and code that
 * branches on `hasRole('tenant_admin')` stops being true the moment someone
 * defines a new role.
 */
trait AuthorizesWithinTenant
{
    protected function allows(User $user, string $permission, ?Model $subject = null): bool
    {
        if (! $user->hasPermissionTo($permission)) {
            return false;
        }

        return $subject === null || $this->ownsRow($user, $subject);
    }

    /**
     * The global scope already prevents a foreign row from being *found*. This
     * is the second lock on the same door: a policy that is handed a model
     * from somewhere else — a route binding resolved before the tenant was
     * bound, say — still refuses it.
     */
    protected function ownsRow(User $user, Model $subject): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $tenantId = $subject->getAttribute('tenant_id');

        if ($tenantId === null) {
            return true;
        }

        return (int) $tenantId === (int) $user->tenant_id;
    }
}
