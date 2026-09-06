<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceCommandStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceCommandResource;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceCommandController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Device::class);

        $query = DeviceCommand::query()->with(['device', 'issuer']);

        if ($request->filled('device_id')) {
            $query->where('device_id', Device::query()->where('uuid', $request->string('device_id'))->value('id') ?? 0);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return DeviceCommandResource::collection(
            $query->latest('id')->paginate($request->integer('per_page', 25))
        );
    }

    /**
     * Withdraw a command the device has not collected yet.
     */
    public function destroy(DeviceCommand $command): JsonResponse
    {
        $this->authorize('command', $command->device);

        if ($command->status->isTerminal()) {
            return response()->json([
                'error' => [
                    'code' => 'command_already_resolved',
                    'message' => 'That command has already been resolved.',
                ],
            ], 409);
        }

        $command->forceFill(['status' => DeviceCommandStatus::Expired])->save();

        $this->audit->log('device_command.cancelled', $command);

        return response()->json(['ok' => true]);
    }
}
