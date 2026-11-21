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

// Age-based backup-code rotation. Does nothing unless BACKUP_CODE_MAX_AGE_DAYS
// is set; the replacement set reaches the door, but no operator has seen it.
Schedule::command('backup-codes:rotate-stale')
    ->dailyAt('03:15')
    ->withoutOverlapping();

// Retention. Does nothing unless EVENT_RETENTION_DAYS is set.
Schedule::command('access-events:prune')
    ->dailyAt('03:45')
    ->withoutOverlapping();

// Reclaim failed queue jobs and stale batches.
Schedule::command('queue:prune-failed --hours=168')->daily();
