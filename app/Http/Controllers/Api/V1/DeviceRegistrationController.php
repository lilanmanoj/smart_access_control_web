<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Realtime\DeviceRealtime;
use App\Support\DeviceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /device-registrations — first contact.
 *
 * A panel reports its identity, firmware version and template capacity so the
 * dashboard can adopt it, rather than an installer typing credentials into a
 * device blind and hoping.
 *
 * This still requires a valid credential: registration tells the backend
 * *about* a device it already trusts, it does not create trust. Self-service
 * enrolment of unknown hardware into a tenant is not something an access
 * control system should offer.
 *
 * Safe to call repeatedly. It updates only the fields sent — a panel whose
 * sensor has not answered omits template_capacity and enrolled_count, and the
 * stored values stand — and, from device API 1.4.0, rotates the panel's MQTT
 * password. It writes no audit entry, queues no command and moves no slot.
 */
class DeviceRegistrationController extends Controller
{
    public function store(Request $request, DeviceContext $context, DeviceRealtime $realtime): JsonResponse
    {
        $validated = $request->validate([
            'firmware_version' => ['nullable', 'string', 'max:32'],
            'template_capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'enrolled_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'metadata' => ['nullable', 'array', 'max:20'],
        ]);

        $device = $context->deviceOrFail();

        $device->forceFill(array_filter([
            'firmware_version' => $validated['firmware_version'] ?? null,
            'template_capacity' => $validated['template_capacity'] ?? null,
            'enrolled_count' => $validated['enrolled_count'] ?? null,
            'metadata' => array_merge($device->metadata ?? [], $validated['metadata'] ?? []),
            'last_seen_at' => now(),
        ], fn ($value): bool => $value !== null))->save();

        $response = [
            'device' => [
                'id' => $device->uuid,
                'device_id' => $device->device_id,
                'name' => $device->name,
                'location' => $device->location,
                'status' => $device->status->value,
                'template_capacity' => $device->template_capacity,
            ],
            'server_time' => now()->toIso8601ZuluString(),
            'settings' => [
                // Enough for the panel to align its own behaviour with the
                // tenant's posture without a separate settings fetch.
                'otp_ttl_seconds' => (int) config('access.otp.ttl'),
                'backup_codes_online_only' => (bool) $device->tenant?->setting(
                    'backup_codes.online_verification_only',
                    config('access.backup_codes.online_verification_only')
                ),
            ],
        ];

        // Device API 1.4.0: the MQTT doorbell login. A fresh password on every
        // call, so each one exists in exactly this response; issuing it
        // disconnects any session still using the previous one. Absent when
        // the doorbell is off or the broker is unreachable — the panel then
        // keeps to /health polling and asks again at its next registration.
        $credentials = $realtime->issueCredentials($device);

        if ($credentials !== null) {
            $response['realtime'] = $credentials;
        }

        return response()->json($response, 201)->header('Cache-Control', 'no-store');
    }
}
