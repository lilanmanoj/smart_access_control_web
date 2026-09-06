<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupCodeSetStatus;
use App\Enums\DeviceStatus;
use App\Models\BackupCodeSet;
use App\Services\BackupCodeService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Ages out backup code sets that have been live too long.
 *
 * These codes sit in a panel's RAM in plaintext so the door still opens when
 * the network is down. A short lifetime is one of the few mitigations that
 * choice leaves available, so it is worth actually applying rather than
 * documenting.
 */
class RotateStaleBackupCodes extends Command
{
    protected $signature = 'backup-codes:rotate-stale';

    protected $description = 'Rotate backup code sets older than the configured maximum age';

    public function handle(BackupCodeService $backupCodes, TenantContext $tenants): int
    {
        $maxAge = (int) config('access.backup_codes.max_age_days');

        if ($maxAge <= 0) {
            $this->info('No maximum set age configured; nothing rotated.');

            return self::SUCCESS;
        }

        $stale = $tenants->crossTenant(fn () => BackupCodeSet::query()
            ->with(['device.tenant'])
            ->where('status', BackupCodeSetStatus::Active->value)
            ->where('issued_at', '<', now()->subDays($maxAge))
            ->whereHas('device', fn ($q) => $q->where('status', DeviceStatus::Active->value))
            ->get());

        foreach ($stale as $set) {
            $tenants->forTenant(
                $set->device->tenant,
                // The device is told to refetch; until it does, its cached set
                // still works, so an offline panel is not locked out by this.
                fn () => $backupCodes->rotate($set->device, reason: 'expired')
            );

            $this->line("Rotated backup codes for {$set->device->device_id}");
        }

        $this->info("Rotated {$stale->count()} stale set(s).");

        return self::SUCCESS;
    }
}
