<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\DeviceCredential;
use App\Support\DeviceContext;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a panel and binds its tenant for the rest of the request.
 *
 * Every device request carries three headers:
 *
 *     X-Device-Id:   SMA_4821
 *     X-API-Key:     <opaque key>
 *     X-API-Secret:  <opaque secret>
 *
 * The device id is *asserted by the device*. It is checked against the device
 * the credential belongs to and never trusted on its own — otherwise one
 * panel's credential could be used to write another panel's history.
 *
 * Every failure returns the same 401 body. Which of the three values was wrong
 * is not information a caller is entitled to.
 */
class AuthenticateDevice
{
    /**
     * A digest-shaped decoy compared against when no credential matches, so a
     * missing key and a wrong secret do the same comparison work. Without it,
     * response timing distinguishes "this key exists" from "it does not".
     */
    private const TIMING_DECOY = '0000000000000000000000000000000000000000000000000000000000000000';

    public function handle(Request $request, Closure $next): Response
    {
        // HMAC request signing is a documented phase-2 item with no
        // implementation behind it yet (config/access.php explains why).
        // Refusing outright is the only honest response to a deployment that
        // has switched it on: silently ignoring the flag would leave an
        // operator believing requests are signed when they are not.
        if (config('access.security.require_request_signature')) {
            throw ApiException::forbidden(
                'signature_required_but_unimplemented',
                'HMAC request signing is enabled but not implemented. '.
                'Disable DEVICE_REQUIRE_SIGNATURE until the firmware supports it.'
            );
        }

        $deviceId = (string) $request->header('X-Device-Id', '');
        $apiKey = (string) $request->header('X-API-Key', '');
        $apiSecret = (string) $request->header('X-API-Secret', '');

        if ($deviceId === '' || $apiKey === '' || $apiSecret === '') {
            throw ApiException::unauthorized();
        }

        // No tenant is bound yet, so this query is deliberately unscoped: the
        // credential is what resolves the tenant.
        $credential = DeviceCredential::query()
            ->with(['device', 'device.tenant'])
            ->where('api_key', $apiKey)
            ->first();

        $presented = hash('sha256', $apiSecret);

        // The secret is a high-entropy machine credential, not a chosen
        // password, so a fast digest with a constant-time comparison is the
        // right primitive — a per-request bcrypt would cost ~100ms on a fleet
        // polling health every 30 seconds, for no added resistance.
        $secretMatches = hash_equals(
            $credential?->api_secret_hash ?? self::TIMING_DECOY,
            $presented
        );

        if ($credential === null || ! $secretMatches) {
            throw ApiException::unauthorized();
        }

        if (! $credential->isUsable()) {
            throw ApiException::unauthorized();
        }

        $device = $credential->device;

        if ($device === null) {
            throw ApiException::unauthorized();
        }

        // The header is a claim; this is the check that makes it meaningless
        // on its own.
        if (! hash_equals($device->device_id, $deviceId)) {
            throw ApiException::unauthorized();
        }

        if (! $device->status->canAuthenticate()) {
            throw ApiException::forbidden(
                'device_'.$device->status->value,
                'This device is not permitted to use the API.'
            );
        }

        $tenant = $device->tenant;

        if ($tenant === null || ! $tenant->isActive()) {
            throw ApiException::forbidden('tenant_suspended', 'This account is not active.');
        }

        app(TenantContext::class)->set($tenant);
        app(DeviceContext::class)->set($device, $credential);

        $this->touchCredential($credential, $request);

        return $next($request);
    }

    /**
     * Record credential usage, but not on every single request: a fleet
     * polling /health every 30 seconds would otherwise turn a read path into a
     * continuous stream of writes for information that is only ever read at
     * minute granularity.
     */
    private function touchCredential(DeviceCredential $credential, Request $request): void
    {
        if ($credential->last_used_at !== null && $credential->last_used_at->diffInSeconds(now()) < 60) {
            return;
        }

        $credential->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->saveQuietly();
    }
}
