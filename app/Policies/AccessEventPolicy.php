<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccessEvent;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithinTenant;

/**
 * Access events are read-only by construction — there is no update or delete
 * ability here because the model refuses both.
 */
class AccessEventPolicy
{
    use AuthorizesWithinTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'event.view');
    }

    public function view(User $user, AccessEvent $event): bool
    {
        return $this->allows($user, 'event.view', $event);
    }

    public function export(User $user): bool
    {
        return $this->allows($user, 'event.export');
    }
}
