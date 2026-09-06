<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Device;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithinTenant;

class DevicePolicy
{
    use AuthorizesWithinTenant;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'device.view');
    }

    public function view(User $user, Device $device): bool
    {
        return $this->allows($user, 'device.view', $device);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'device.create');
    }

    public function update(User $user, Device $device): bool
    {
        return $this->allows($user, 'device.update', $device);
    }

    public function delete(User $user, Device $device): bool
    {
        return $this->allows($user, 'device.delete', $device);
    }

    /** Remote unlock opens a real door; it is its own permission. */
    public function unlock(User $user, Device $device): bool
    {
        return $this->allows($user, 'device.unlock', $device);
    }

    public function command(User $user, Device $device): bool
    {
        return $this->allows($user, 'device.command', $device);
    }

    public function manageCredentials(User $user, Device $device): bool
    {
        return $this->allows($user, 'device.update', $device);
    }
}
