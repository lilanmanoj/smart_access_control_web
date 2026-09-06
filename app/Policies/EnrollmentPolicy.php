<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithinTenant;

class EnrollmentPolicy
{
    use AuthorizesWithinTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'enrollment.view');
    }

    public function view(User $user, Enrollment $enrollment): bool
    {
        return $this->allows($user, 'enrollment.view', $enrollment);
    }

    /**
     * Revocation is the only mutation: enrolments are created at the panel,
     * because that is where the finger is.
     */
    public function revoke(User $user, Enrollment $enrollment): bool
    {
        return $this->allows($user, 'enrollment.revoke', $enrollment);
    }
}
