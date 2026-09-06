<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AccessEventController;
use App\Http\Controllers\Admin\AccessScheduleController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BackupCodeSetController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceCommandController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\DeviceCredentialController;
use App\Http\Controllers\Admin\EnrollmentController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\OtpRequestController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dashboard API — /api/admin/v1
|--------------------------------------------------------------------------
|
| Sanctum SPA session auth, tenant-scoped by BindTenantForUser, and
| policy-guarded in every controller. Two-factor is enforced for any role that
| can manage devices or members.
|
*/

Route::prefix('admin/v1')->name('admin.')->group(function (): void {

    // ---------------------------------------------------------------------
    // Unauthenticated: sign-in and the two-factor challenge.
    // ---------------------------------------------------------------------
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('login');

    Route::post('/two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])
        ->middleware('throttle:6,1')
        ->name('two-factor.challenge');

    // ---------------------------------------------------------------------
    // Authenticated.
    // ---------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'tenant', 'two-factor'])->group(function (): void {

        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::post('/two-factor/enroll', [AuthController::class, 'enrollTwoFactor'])
            ->name('two-factor.enroll');
        Route::post('/two-factor/confirm', [AuthController::class, 'confirmTwoFactor'])
            ->name('two-factor.confirm');
        Route::delete('/two-factor', [AuthController::class, 'disableTwoFactor'])
            ->name('two-factor.disable');

        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
            ->name('dashboard.summary');

        // -----------------------------------------------------------------
        // Devices
        // -----------------------------------------------------------------
        Route::apiResource('devices', DeviceController::class)->names('devices');

        Route::get('/devices/{device}/health', [DeviceController::class, 'health'])
            ->name('devices.health');
        Route::post('/devices/{device}/unlock', [DeviceController::class, 'unlock'])
            ->name('devices.unlock');
        Route::post('/devices/{device}/backup-codes/rotations', [DeviceController::class, 'rotateBackupCodes'])
            ->name('devices.backup-codes.rotate');
        Route::post('/devices/{device}/inventory-requests', [DeviceController::class, 'requestInventory'])
            ->name('devices.inventory-requests');

        Route::get('/devices/{device}/credentials', [DeviceCredentialController::class, 'index'])
            ->name('devices.credentials.index');
        Route::post('/devices/{device}/credentials', [DeviceCredentialController::class, 'store'])
            ->name('devices.credentials.store');
        Route::delete('/devices/{device}/credentials/{credential}', [DeviceCredentialController::class, 'destroy'])
            ->name('devices.credentials.destroy');

        // -----------------------------------------------------------------
        // Members and enrolments
        // -----------------------------------------------------------------
        Route::apiResource('members', MemberController::class)->names('members');
        Route::put('/members/{member}/schedules', [AccessScheduleController::class, 'assignToMember'])
            ->name('members.schedules.update');

        Route::get('/enrollments', [EnrollmentController::class, 'index'])->name('enrollments.index');
        Route::delete('/enrollments/{enrollment}', [EnrollmentController::class, 'destroy'])
            ->name('enrollments.destroy');

        // -----------------------------------------------------------------
        // Access log
        // -----------------------------------------------------------------
        // Declared before the collection route so `export` is not swallowed by
        // a wildcard segment.
        Route::get('/access-events/export', [AccessEventController::class, 'export'])
            ->name('access-events.export');
        Route::get('/access-events', [AccessEventController::class, 'index'])
            ->name('access-events.index');

        Route::get('/otp-requests', [OtpRequestController::class, 'index'])->name('otp-requests.index');
        Route::get('/backup-code-sets', [BackupCodeSetController::class, 'index'])->name('backup-code-sets.index');

        // -----------------------------------------------------------------
        // Device commands
        // -----------------------------------------------------------------
        Route::get('/device-commands', [DeviceCommandController::class, 'index'])->name('device-commands.index');
        Route::delete('/device-commands/{command}', [DeviceCommandController::class, 'destroy'])
            ->name('device-commands.destroy');

        // -----------------------------------------------------------------
        // Schedules and webhooks
        // -----------------------------------------------------------------
        Route::apiResource('access-schedules', AccessScheduleController::class)
            ->except(['show'])
            ->names('access-schedules');

        Route::get('/webhook-events', [WebhookEndpointController::class, 'availableEvents'])
            ->name('webhook-events.index');
        Route::apiResource('webhooks', WebhookEndpointController::class)
            ->except(['show'])
            ->parameters(['webhooks' => 'endpoint'])
            ->names('webhooks');

        // -----------------------------------------------------------------
        // Users, roles, audit
        // -----------------------------------------------------------------
        Route::get('/roles', [UserController::class, 'roles'])->name('roles.index');
        Route::apiResource('users', UserController::class)
            ->except(['show'])
            ->names('users');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        // -----------------------------------------------------------------
        // Tenants — SuperAdmin only, and every switch is audited.
        // -----------------------------------------------------------------
        Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
        Route::patch('/tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
        Route::post('/tenants/{tenant}/switch', [TenantController::class, 'switchTo'])->name('tenants.switch');
        Route::delete('/tenant-switch', [TenantController::class, 'clearSwitch'])->name('tenants.switch.clear');
    });
});
