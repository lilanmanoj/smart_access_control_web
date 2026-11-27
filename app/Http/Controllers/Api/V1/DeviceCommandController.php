<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccessMethod;
use App\Enums\AccessResult;
use App\Enums\DenialReason;
use App\Enums\DeviceCommandType;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\AccessEventRecorder;
use App\Services\AuditLogger;
use App\Services\DeviceCommandService;
use App\Services\EnrollmentService;
use App\Services\FingerprintTemplateService;
use App\Support\DeviceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The command channel (§9.3).
 *
 * Commands also ride along on the health response, so a device that only polls
 * /health still gets them. This endpoint is what a panel fetches when its MQTT
 * doorbell rings (device API 1.4.0); both share
 * DeviceCommandService::collectFor(), so the two can never both deliver the
 * same unlock.
 */
class DeviceCommandController extends Controller
{
    public function __construct(
        private readonly DeviceCommandService $commands,
        private readonly EnrollmentService $enrollments,
        private readonly FingerprintTemplateService $templates,
        private readonly AccessEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /device-commands */
    public function index(DeviceContext $context): JsonResponse
    {
        $queued = $this->commands->collectFor($context->deviceOrFail());

        return response()->json([
            'commands' => $queued->map(fn (DeviceCommand $command): array => $command->toDeviceArray())->values()->all(),
        ], 200, [], DeviceCommand::DEVICE_JSON_FLAGS);
    }

    /**
     * POST /device-commands/{command}/acknowledgements
     *
     * Idempotent (device API 1.4.0): the first final acknowledgement wins and
     * runs its side effects; a repeat — the panel retries after a timeout —
     * answers the same 200 and changes nothing.
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
            'result.error' => ['sometimes', 'string', 'max:64'],
            // A report_inventory answer: one entry per occupied sensor slot.
            'result.occupied_slots' => ['sometimes', 'array', 'max:1024'],
            'result.occupied_slots.*' => ['integer', 'min:0', 'max:65535'],
        ]);

        // Read from the request, not from $validated: with nested rules
        // present, validated() keeps only the nested keys it has rules for
        // and drops the rest of the object (a sync's `written`, an unlock's
        // `opened`). The rules above have already checked its shape.
        $result = (array) ($request->input('result') ?? []);

        if (! $this->commands->acknowledge($command, $validated['status'], $result)) {
            return response()->json(['acknowledged' => true]);
        }

        $acked = $validated['status'] === 'acked';

        if ($command->type === DeviceCommandType::Unlock) {
            $this->resolveUnlock($device, $command, $acked, $result);
        }

        // A slot inventory arrives as the result of a report_inventory
        // command; reconciling it here is what turns the reply into a
        // decision about which side is out of date (§9.4).
        if ($command->type === DeviceCommandType::ReportInventory && $acked && isset($result['occupied_slots'])) {
            $this->enrollments->reconcile($device, array_map('intval', $result['occupied_slots']));
        }

        // Deprecated since 1.4.0, but one queued before the upgrade can still
        // be acknowledged. Erases only a template no live enrolment holds, so
        // a slot handed on since is never touched.
        if ($command->type === DeviceCommandType::DeleteEnrollment && $acked && isset($command->payload['fingerprint_slot'])) {
            $this->templates->eraseSlot($device, (int) $command->payload['fingerprint_slot']);
        }

        return response()->json(['acknowledged' => true]);
    }

    /**
     * The access log records a remote unlock when the panel says the door
     * opened — not when an operator pressed the button — so an unlock that
     * expired or was refused never appears as an opening.
     *
     * @param  array<string, mixed>  $result
     */
    private function resolveUnlock(Device $device, DeviceCommand $command, bool $acked, array $result): void
    {
        if ($acked) {
            $this->events->record($device, [
                'method' => AccessMethod::Remote,
                'result' => AccessResult::Granted,
                'reason' => DenialReason::RemoteUnlock,
                'actor_user_id' => $command->issued_by,
                // A second record for the same command is impossible anyway
                // (first ack wins); the key makes that true at the database.
                'idempotency_key' => 'command:'.$command->uuid,
                'metadata' => ['command_id' => $command->uuid],
            ]);

            return;
        }

        $this->audit->log('device.unlock_failed', $device, after: [
            'command_id' => $command->uuid,
            'error' => isset($result['error']) ? (string) $result['error'] : null,
        ]);
    }
}
