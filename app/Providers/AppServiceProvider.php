<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\WebhookEvent;
use App\Listeners\DeliverWebhooksForEvent;
use App\Models\AccessEvent;
use App\Models\AccessSchedule;
use App\Models\AuditLog;
use App\Models\BackupCodeSet;
use App\Models\Device;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Policies\AccessEventPolicy;
use App\Policies\AccessSchedulePolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\BackupCodeSetPolicy;
use App\Policies\DevicePolicy;
use App\Policies\EnrollmentPolicy;
use App\Policies\MemberPolicy;
use App\Policies\TenantPolicy;
use App\Policies\UserPolicy;
use App\Policies\WebhookEndpointPolicy;
use App\Services\Sms\HttpSmsGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\NullSmsGateway;
use App\Services\Sms\SmsGateway;
use App\Support\DeviceContext;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string<Model>, class-string>
     */
    private const POLICIES = [
        Device::class => DevicePolicy::class,
        Member::class => MemberPolicy::class,
        Enrollment::class => EnrollmentPolicy::class,
        AccessEvent::class => AccessEventPolicy::class,
        BackupCodeSet::class => BackupCodeSetPolicy::class,
        User::class => UserPolicy::class,
        Tenant::class => TenantPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        WebhookEndpoint::class => WebhookEndpointPolicy::class,
        AccessSchedule::class => AccessSchedulePolicy::class,
    ];

    public function register(): void
    {
        // One tenant and one device per request, unambiguously.
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(DeviceContext::class);

        $this->app->bind(SmsGateway::class, fn (): SmsGateway => match (config('access.sms.driver')) {
            'http' => new HttpSmsGateway,
            'null' => new NullSmsGateway,
            default => new LogSmsGateway,
        });
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerRateLimiters();

        // One listener covers every webhook-capable event; a new one becomes
        // deliverable by implementing the contract.
        Event::listen(WebhookEvent::class, DeliverWebhooksForEvent::class);

        // Fail loudly in development on a lazy-loaded relation or a mass
        // assignment that silently drops a field, rather than shipping either.
        Model::preventLazyLoading($this->app->isLocal());
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal());

        // A SuperAdmin holds every permission by definition. Kept as a
        // before-gate so no policy has to special-case them.
        Gate::before(fn (User $user): ?bool => $user->isSuperAdmin() ? true : null);
    }

    private function registerPolicies(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /**
     * Per-device limits on the endpoints an attacker holding a credential
     * would hammer (§9.5).
     *
     * Keyed on the authenticated device rather than the IP: a whole site's
     * panels commonly share one public address, and one compromised device
     * must not be able to throttle its neighbours.
     */
    private function registerRateLimiters(): void
    {
        $limits = [
            'device-health' => 'health',
            'device-otp-requests' => 'otp_requests',
            'device-otp-verifications' => 'otp_verifications',
            'device-backup-codes' => 'backup_code_attempts',
            'device-access-events' => 'access_events',
        ];

        foreach ($limits as $name => $configKey) {
            RateLimiter::for($name, function (Request $request) use ($configKey): Limit {
                $perMinute = (int) config("access.rate_limits.{$configKey}", 60);

                return Limit::perMinute($perMinute)->by($this->deviceLimiterKey($request));
            });
        }
    }

    private function deviceLimiterKey(Request $request): string
    {
        $device = app(DeviceContext::class)->device();

        // The middleware runs first, so a device is normally bound. Falling
        // back to the IP covers a rejected request that never got that far.
        return $device !== null
            ? 'device:'.$device->id
            : 'ip:'.$request->ip();
    }
}
