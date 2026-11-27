<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DeviceCommandService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Marks commands expired once their TTL passes, fleet-wide.
 *
 * A panel's own poll already expires its stale commands, but a panel that has
 * gone dark never polls. Without this the dashboard would show a remote unlock
 * as "waiting" for ever, when it can in fact no longer open the door.
 */
class ExpireDeviceCommands extends Command
{
    protected $signature = 'device-commands:expire';

    protected $description = 'Expire device commands past their TTL';

    public function handle(DeviceCommandService $commands, TenantContext $tenants): int
    {
        $expired = $tenants->crossTenant(fn (): int => $commands->expireStale());

        $this->info("Expired {$expired} command(s).");

        return self::SUCCESS;
    }
}
