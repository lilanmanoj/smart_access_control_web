<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OtpStatus;
use App\Models\OtpRequest;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Closes out one-time passwords nobody used.
 *
 * Verification already refuses an expired code, so this is housekeeping rather
 * than enforcement: it keeps the OTP history readable, and it stops a
 * long-abandoned request from sitting in `issued` forever.
 */
class ExpireOtpRequests extends Command
{
    protected $signature = 'otp:expire';

    protected $description = 'Mark unused one-time passwords as expired';

    public function handle(TenantContext $tenants): int
    {
        $count = $tenants->crossTenant(fn (): int => OtpRequest::query()
            ->whereIn('status', [OtpStatus::Issued->value, OtpStatus::Delivered->value])
            ->where('expires_at', '<', now())
            ->update(['status' => OtpStatus::Expired->value, 'updated_at' => now()]));

        $this->info("Expired {$count} one-time password(s).");

        return self::SUCCESS;
    }
}
