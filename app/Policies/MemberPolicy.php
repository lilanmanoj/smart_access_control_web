<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Member;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithinTenant;

class MemberPolicy
{
    use AuthorizesWithinTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'member.view');
    }

    public function view(User $user, Member $member): bool
    {
        return $this->allows($user, 'member.view', $member);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'member.create');
    }

    public function update(User $user, Member $member): bool
    {
        return $this->allows($user, 'member.update', $member);
    }

    public function delete(User $user, Member $member): bool
    {
        return $this->allows($user, 'member.delete', $member);
    }
}
