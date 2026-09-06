<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AccessEvent;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Applies the access-event retention period.
 *
 * Retention is a compliance decision, not a technical one (open question 3),
 * so this does nothing until someone sets `EVENT_RETENTION_DAYS`.
 *
 * Rows are deleted through the query builder in bounded chunks: the model
 * refuses individual deletes to keep the trail append-only, and a single
 * unbounded DELETE across a year of door history would hold locks for a long
 * time on a table the device API is actively writing to.
 */
class PruneAccessEvents extends Command
{
    protected $signature = 'access-events:prune {--chunk=5000}';

    protected $description = 'Delete access events past the configured retention period';

    public function handle(TenantContext $tenants): int
    {
        $days = config('access.events.retention_days');

        if ($days === null || (int) $days <= 0) {
            $this->info('No retention period configured; nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $days);
        $chunk = (int) $this->option('chunk');
        $deleted = 0;

        $tenants->crossTenant(function () use ($cutoff, $chunk, &$deleted): void {
            do {
                $batch = AccessEvent::query()
                    ->where('occurred_at', '<', $cutoff)
                    ->limit($chunk)
                    ->delete();

                $deleted += $batch;
            } while ($batch > 0);
        });

        $this->info("Pruned {$deleted} access event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
