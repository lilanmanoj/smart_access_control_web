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
 * Off unless BACKUP_CODE_MAX_AGE_DAYS is set, and 0 is the recommendation:
 * the door syncs the replacement set, but no operator has seen its codes, so
 * an age-based rotation leaves people holding codes that no longer work.
 * Rotating by hand gives the same protection with codes someone can hand out.
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
                // The door collects the new set at its next sync; until then
                // the set it holds still works, so an offline panel is not
                // locked out by this.
                fn () => $backupCodes->rotate($set->device, reason: 'expired')
            );

            $this->line("Rotated backup codes for {$set->device->device_id}");
        }

        $this->info("Rotated {$stale->count()} stale set(s).");

        return self::SUCCESS;
    }
}
