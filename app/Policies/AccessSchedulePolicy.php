<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccessSchedule;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithinTenant;

class AccessSchedulePolicy
{
    use AuthorizesWithinTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'schedule.view');
    }

    public function view(User $user, AccessSchedule $schedule): bool
    {
        return $this->allows($user, 'schedule.view', $schedule);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'schedule.manage');
    }

    public function update(User $user, AccessSchedule $schedule): bool
    {
        return $this->allows($user, 'schedule.manage', $schedule);
    }

    public function delete(User $user, AccessSchedule $schedule): bool
    {
        return $this->allows($user, 'schedule.manage', $schedule);
    }
}
