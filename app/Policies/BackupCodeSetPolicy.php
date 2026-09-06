<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BackupCodeSet;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithinTenant;

class BackupCodeSetPolicy
{
    use AuthorizesWithinTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'backupcode.view');
    }

    public function view(User $user, BackupCodeSet $set): bool
    {
        return $this->allows($user, 'backupcode.view', $set);
    }

    public function rotate(User $user, ?BackupCodeSet $set = null): bool
    {
        return $this->allows($user, 'backupcode.rotate', $set);
    }
}
