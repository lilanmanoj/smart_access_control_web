<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceCommand;
use App\Services\DeviceCommandService;
use App\Services\DeviceHealthRecorder;
use App\Support\DeviceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /health — liveness probe, heartbeat, clock source and command channel.
 *
 * The panel polls this every 30 seconds while it is up and every 10 while it
 * thinks it is down, which makes it the one call guaranteed to happen. Three
 * things ride along on it:
 *
 *  - `server_time`, because the device has no RTC and its status bar otherwise
 *    reads 1970 (§9.1);
 *  - the heartbeat that tells the dashboard a door has gone dark (§9.2);
 *  - any queued commands, so revocation and remote unlock work without the
 *    device having to poll a second endpoint (§9.3).
 *
 * The firmware requires both a 200 and `"status": "ok"` exactly; extra fields
 * are ignored by older builds and picked up by newer ones.
 */
class HealthController extends Controller
{
    public function __invoke(
        Request $request,
        DeviceHealthRecorder $health,
        DeviceCommandService $commands,
        DeviceContext $context,
    ): JsonResponse {
        $device = $context->deviceOrFail();

        // Optional self-report. Sent as query parameters so a plain GET can
        // carry it (firmware change 7).
        $health->record($device, array_filter([
            'firmware_version' => $request->query('firmware_version'),
            'uptime_ms' => $request->integer('uptime_ms') ?: null,
            'enrolled_count' => $request->integer('enrolled_count') ?: null,
            'template_capacity' => $request->integer('template_capacity') ?: null,
        ], fn ($value): bool => $value !== null && $value !== ''));

        $queued = $commands->collectFor($device);

        return response()->json([
            'status' => 'ok',
            'server_time' => now()->toIso8601ZuluString(),
            'device' => [
                'id' => $device->uuid,
                'device_id' => $device->device_id,
                'name' => $device->name,
            ],
            'commands' => $queued->map(fn (DeviceCommand $command): array => [
                'id' => $command->uuid,
                'type' => $command->type->value,
                'payload' => $command->payload ?? new \stdClass,
            ])->all(),
        ]);
    }
}
