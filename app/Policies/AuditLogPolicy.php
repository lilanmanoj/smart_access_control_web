<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithinTenant;

class AuditLogPolicy
{
    use AuthorizesWithinTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'audit.view');
    }

    public function view(User $user, AuditLog $log): bool
    {
        return $this->allows($user, 'audit.view', $log);
    }
}
