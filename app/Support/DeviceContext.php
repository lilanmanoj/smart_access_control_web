<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Device;
use App\Models\DeviceCredential;
use RuntimeException;

/**
 * The authenticated device for the current request.
 *
 * Bound by {@see \App\Http\Middleware\AuthenticateDevice} once the credential
 * has been verified. Controllers read the device from here rather than from
 * the request body: the `device_id` field the firmware sends is a claim, and
 * the credential is what actually decides which panel is talking.
 */
class DeviceContext
{
    private ?Device $device = null;

    private ?DeviceCredential $credential = null;

    public function set(Device $device, DeviceCredential $credential): void
    {
        $this->device = $device;
        $this->credential = $credential;
    }

    public function forget(): void
    {
        $this->device = null;
        $this->credential = null;
    }

    public function device(): ?Device
    {
        return $this->device;
    }

    public function credential(): ?DeviceCredential
    {
        return $this->credential;
    }

    public function has(): bool
    {
        return $this->device !== null;
    }

    public function deviceOrFail(): Device
    {
        return $this->device ?? throw new RuntimeException(
            'No device is bound to the current request.'
        );
    }
}
