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
 *    device having to poll a second endpoint (§9.3). The same commands are
 *    served by GET /device-commands when the MQTT doorbell rings; both go
 *    through DeviceCommandService::collectFor(), so an unlock is handed out
 *    once across the two.
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
        // carry it (firmware change 7). A capacity or count of 0 means the
        // sensor has not answered yet and is ignored.
        $health->record($device, array_filter([
            'firmware_version' => $request->query('firmware_version'),
            'uptime_ms' => $request->integer('uptime_ms') ?: null,
            'enrolled_count' => $request->integer('enrolled_count') ?: null,
            'template_capacity' => $request->integer('template_capacity') ?: null,
            // Device API 1.4.0: the doorbell's state. 0 is a real answer for
            // both, so these are filtered on presence, not truthiness.
            'mqtt_connected' => $request->has('mqtt') ? $request->boolean('mqtt') : null,
            'mqtt_reconnects' => $request->has('mqtt_reconnects') ? max(0, $request->integer('mqtt_reconnects')) : null,
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
            'commands' => $queued->map(fn (DeviceCommand $command): array => $command->toDeviceArray())->values()->all(),
        ], 200, [], DeviceCommand::DEVICE_JSON_FLAGS);
    }
}
