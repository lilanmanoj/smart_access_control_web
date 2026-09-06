<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeviceCommandType;
use App\Http\Controllers\Controller;
use App\Models\DeviceCommand;
use App\Services\DeviceCommandService;
use App\Services\EnrollmentService;
use App\Support\DeviceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The command channel (§9.3).
 *
 * Commands also ride along on the health response, so a device that only polls
 * /health still gets them. This endpoint exists for firmware that would rather
 * fetch them separately.
 */
class DeviceCommandController extends Controller
{
    public function __construct(
        private readonly DeviceCommandService $commands,
        private readonly EnrollmentService $enrollments,
    ) {}

    /** GET /device-commands */
    public function index(DeviceContext $context): JsonResponse
    {
        $queued = $this->commands->collectFor($context->deviceOrFail());

        return response()->json([
            'commands' => $queued->map(fn (DeviceCommand $command): array => [
                'id' => $command->uuid,
                'type' => $command->type->value,
                'payload' => $command->payload ?? new \stdClass,
            ])->all(),
        ]);
    }

    /**
     * POST /device-commands/{command}/acknowledgements
     *
     * Until this arrives, the backend does not claim the fingerprint was
     * actually erased — which is why the dashboard shows a pending command
     * next to a revoked enrolment.
     */
    public function acknowledge(Request $request, DeviceCommand $command, DeviceContext $context): JsonResponse
    {
        $device = $context->deviceOrFail();

        // The tenant scope already limits this to the device's own tenant;
        // this narrows it to the device itself.
        abort_if($command->device_id !== $device->id, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['acked', 'failed'])],
            'result' => ['nullable', 'array', 'max:20'],
        ]);

        $this->commands->acknowledge(
            $command,
            $validated['status'],
            $validated['result'] ?? [],
        );

        // A slot inventory arrives as the result of a report_inventory
        // command; reconciling it here is what turns the reply into a
        // decision about which side is out of date (§9.4).
        if ($command->type === DeviceCommandType::ReportInventory
            && isset($validated['result']['occupied_slots'])
            && is_array($validated['result']['occupied_slots'])) {
            $this->enrollments->reconcile(
                $device,
                array_map('intval', $validated['result']['occupied_slots']),
            );
        }

        return response()->json(['acknowledged' => true]);
    }
}
