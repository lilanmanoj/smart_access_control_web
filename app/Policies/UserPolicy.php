<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AuthorizesWithinTenant;

class UserPolicy
{
    use AuthorizesWithinTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'user.view');
    }

    public function view(User $user, User $subject): bool
    {
        return $this->allows($user, 'user.view') && $this->reaches($user, $subject);
    }

    public function invite(User $user): bool
    {
        return $this->allows($user, 'user.invite');
    }

    public function update(User $user, User $subject): bool
    {
        return $this->allows($user, 'user.update') && $this->reaches($user, $subject);
    }

    public function delete(User $user, User $subject): bool
    {
        // Locking yourself out of the system you administer helps nobody.
        if ($user->is($subject)) {
            return false;
        }

        return $this->allows($user, 'user.delete') && $this->reaches($user, $subject);
    }

    public function manageRoles(User $user, User $subject): bool
    {
        // Granting yourself a role is how a device_manager becomes a
        // tenant_admin without anyone approving it.
        if ($user->is($subject)) {
            return false;
        }

        return $this->allows($user, 'role.manage') && $this->reaches($user, $subject);
    }

    /**
     * Moving an operator between tenants crosses the isolation boundary, so it
     * takes the same permission as managing tenants themselves.
     */
    public function assignTenant(User $user, User $subject): bool
    {
        if ($user->is($subject)) {
            return false;
        }

        return $this->allows($user, 'tenant.manage') && $this->reaches($user, $subject);
    }

    /**
     * Stricter than {@see AuthorizesWithinTenant::ownsRow()}, which reads a
     * null `tenant_id` as "nobody's row". For a user it means a SuperAdmin, or
     * an account that lost its tenant. Neither is a tenant operator's to
     * touch, and editing a SuperAdmin's e-mail would hand over their account.
     */
    private function reaches(User $user, User $subject): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $subject->tenant_id !== null
            && (int) $subject->tenant_id === (int) $user->tenant_id;
    }
}
