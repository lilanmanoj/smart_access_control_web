<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Run by the `scheduler` container (docker/entrypoint.sh), which is just
| `php artisan schedule:work` in its own process.
|
*/

// The panel polls health every 30 seconds; checking every minute detects a
// dark door within about two minutes of it going quiet.
Schedule::command('devices:detect-offline')
    ->everyMinute()
    ->withoutOverlapping();

// Housekeeping on abandoned one-time passwords.
Schedule::command('otp:expire')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Backup codes sit in a panel's RAM in plaintext, so a short set lifetime is
// one of the few mitigations that choice leaves. Applied nightly.
Schedule::command('backup-codes:rotate-stale')
    ->dailyAt('03:15')
    ->withoutOverlapping();

// Retention. Does nothing unless EVENT_RETENTION_DAYS is set.
Schedule::command('access-events:prune')
    ->dailyAt('03:45')
    ->withoutOverlapping();

// Reclaim failed queue jobs and stale batches.
Schedule::command('queue:prune-failed --hours=168')->daily();
