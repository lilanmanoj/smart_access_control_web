<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DeviceCommandQueued;
use App\Services\Realtime\DeviceRealtime;

/**
 * Rings the panel's MQTT doorbell whenever a command is queued for it.
 *
 * Runs in the request, not on the queue: the single queue worker processes
 * jobs one at a time, so a doorbell behind a slow webhook delivery would
 * arrive late. Publishing costs a few milliseconds on the internal network,
 * and a broker failure is swallowed and backed off inside DeviceRealtime.
 *
 * The event is ShouldDispatchAfterCommit, so this never runs before the
 * command row is visible to the panel's fetch.
 */
class RingDeviceDoorbell
{
    public function __construct(
        private readonly DeviceRealtime $realtime,
    ) {}

    public function handle(DeviceCommandQueued $event): void
    {
        $this->realtime->ring($event->command->device);
    }
}
