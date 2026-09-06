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
        return $this->allows($user, 'user.view', $subject);
    }

    public function invite(User $user): bool
    {
        return $this->allows($user, 'user.invite');
    }

    public function update(User $user, User $subject): bool
    {
        return $this->allows($user, 'user.update', $subject);
    }

    public function delete(User $user, User $subject): bool
    {
        // Locking yourself out of the system you administer helps nobody.
        if ($user->is($subject)) {
            return false;
        }

        return $this->allows($user, 'user.delete', $subject);
    }

    public function manageRoles(User $user, User $subject): bool
    {
        // Granting yourself a role is how a device_manager becomes a
        // tenant_admin without anyone approving it.
        if ($user->is($subject)) {
            return false;
        }

        return $this->allows($user, 'role.manage', $subject);
    }
}
