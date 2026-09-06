<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Minting and rotating device API credentials.
 *
 * The secret is high-entropy and machine-held, so it is digested with SHA-256
 * rather than a password hash: {@see \App\Http\Middleware\AuthenticateDevice}
 * explains why a per-request bcrypt would be the wrong trade for a fleet
 * polling health every thirty seconds.
 *
 * The plaintext secret exists exactly once, in the response to the call that
 * created it.
 */
class DeviceCredentialService
{
    /** Two live credentials, so a rotation overlaps instead of gapping. */
    private const MAX_LIVE_CREDENTIALS = 2;

    /**
     * @return array{credential: DeviceCredential, api_key: string, api_secret: string}
     */
    public function issue(Device $device, ?User $createdBy = null, ?string $label = null): array
    {
        $live = $device->credentials()
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->count();

        if ($live >= self::MAX_LIVE_CREDENTIALS) {
            throw new ApiException(
                'credential_limit_reached',
                'This device already has two live credentials. Revoke one before issuing another.',
                422
            );
        }

        $apiKey = 'sak_'.Str::random(32);
        $apiSecret = Str::random(48);

        $credential = new DeviceCredential([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'api_key' => $apiKey,
            'api_secret_hash' => hash('sha256', $apiSecret),
            'secret_last4' => substr($apiSecret, -4),
            'label' => $label ?? 'Issued '.now()->toDateString(),
            'created_by' => $createdBy?->id,
        ]);

        $credential->save();

        return [
            'credential' => $credential,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ];
    }

    public function revoke(DeviceCredential $credential): DeviceCredential
    {
        $credential->forceFill(['revoked_at' => now()])->save();

        return $credential;
    }

    /**
     * Issue a replacement and put the old one on a deadline rather than
     * killing it immediately — the panel has to be reachable to learn its new
     * secret, and revoking first would lock it out before it could.
     *
     * @return array{credential: DeviceCredential, api_key: string, api_secret: string}
     */
    public function rotate(
        Device $device,
        DeviceCredential $existing,
        ?User $createdBy = null,
        int $overlapMinutes = 60,
    ): array {
        $existing->forceFill(['expires_at' => now()->addMinutes($overlapMinutes)])->save();

        return $this->issue($device, $createdBy, 'Rotation '.now()->toDateTimeString());
    }
}
