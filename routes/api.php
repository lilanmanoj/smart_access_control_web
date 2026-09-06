<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccessEventController;
use App\Http\Controllers\Api\V1\AuthenticationController;
use App\Http\Controllers\Api\V1\BackupCodeController;
use App\Http\Controllers\Api\V1\DeviceCommandController;
use App\Http\Controllers\Api\V1\DeviceRegistrationController;
use App\Http\Controllers\Api\V1\EnrollmentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\OtpController;
use App\Http\Controllers\Api\V1\OtpDeliveryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Device API — /api/v1
|--------------------------------------------------------------------------
|
| These paths are appended directly to the device's configured
| `api_base_url`, which already includes the version prefix:
|
|     https://example.com/api/v1  +  /health
|
| Naming follows the convention the firmware already established: kebab-case
| plural nouns, the HTTP method carries the verb, sub-resources nested.
|
| Every route here is authenticated by device credential
| ({@see \App\Http\Middleware\AuthenticateDevice}), which is also what binds
| the tenant for the rest of the request.
|
*/

Route::prefix('v1')
    ->middleware('device')
    ->group(function (): void {

        // Liveness, heartbeat, clock and piggybacked commands. Polled every
        // 30s while up, 10s while down — by far the busiest route here.
        Route::get('/health', HealthController::class)
            ->middleware('throttle:device-health')
            ->name('device.health');

        // The Admin Settings gate. Grants only on is_admin: true.
        Route::post('/authentications', [AuthenticationController::class, 'store'])
            ->name('device.authentications.store');

        // Enrolment: template first, phone number on the next screen.
        Route::post('/enrollments', [EnrollmentController::class, 'store'])
            ->name('device.enrollments.store');
        Route::patch('/enrollments/{member}', [EnrollmentController::class, 'update'])
            ->name('device.enrollments.update');

        // One-time passwords. Issuance never returns the code (§7.3).
        Route::post('/otp-requests', [OtpController::class, 'store'])
            ->middleware('throttle:device-otp-requests')
            ->name('device.otp-requests.store');
        Route::post('/otp-verifications', [OtpController::class, 'verify'])
            ->middleware('throttle:device-otp-verifications')
            ->name('device.otp-verifications.store');

        // Legacy fan-out hook, kept so shipped firmware does not see a 404.
        Route::post('/otp-deliveries', [OtpDeliveryController::class, 'store'])
            ->name('device.otp-deliveries.store');

        // Backup codes.
        Route::get('/backup-codes', [BackupCodeController::class, 'index'])
            ->name('device.backup-codes.index');
        Route::post('/backup-codes/attempts', [BackupCodeController::class, 'attempt'])
            ->middleware('throttle:device-backup-codes')
            ->name('device.backup-codes.attempts.store');
        Route::post('/backup-code-verifications', [BackupCodeController::class, 'verify'])
            ->middleware('throttle:device-backup-codes')
            ->name('device.backup-code-verifications.store');

        // The audit trail. Single events or an offline batch.
        Route::post('/access-events', [AccessEventController::class, 'store'])
            ->middleware('throttle:device-access-events')
            ->name('device.access-events.store');

        // Command queue.
        Route::get('/device-commands', [DeviceCommandController::class, 'index'])
            ->name('device.commands.index');
        Route::post('/device-commands/{command}/acknowledgements', [DeviceCommandController::class, 'acknowledge'])
            ->name('device.commands.acknowledge');

        // First contact / fleet self-report.
        Route::post('/device-registrations', [DeviceRegistrationController::class, 'store'])
            ->name('device.registrations.store');
    });

/*
|--------------------------------------------------------------------------
| Dashboard API — /api/admin/v1
|--------------------------------------------------------------------------
*/

require __DIR__.'/admin.php';
