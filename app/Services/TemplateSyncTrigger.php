<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Real-time member sync (device API 1.4.0).
 *
 * Whenever something changes what a tenant's panels should hold — a member
 * suspended or reactivated, approved or deleted, an enrolment made or revoked
 * — every active panel in the tenant is asked to run a template sync now,
 * instead of at its next scheduled sync hours later. A panel whose set did
 * not change answers `unchanged: true` in one small request, so asking all of
 * them is cheaper than working out exactly which ones are affected, and it
 * cannot miss one.
 *
 * Queued after the surrounding transaction commits, so the panel's sync sees
 * the change it was woken for.
 */
class TemplateSyncTrigger
{
    public function __construct(
        private readonly DeviceCommandService $commands,
    ) {}

    /**
     * @param  Device|null  $except  The panel the change came from, when it is
     *                               already up to date (it made the enrolment).
     */
    public function tenantChanged(int $tenantId, ?Device $except = null, ?User $by = null): void
    {
        DB::afterCommit(function () use ($tenantId, $except, $by): void {
            Device::query()
                ->where('tenant_id', $tenantId)
                ->where('status', DeviceStatus::Active->value)
                ->when($except !== null, fn ($q) => $q->whereKeyNot($except->getKey()))
                ->orderBy('id')
                ->each(fn (Device $device) => $this->commands->queueSyncTemplates($device, $by));
        });
    }
}
