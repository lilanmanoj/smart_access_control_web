<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AccessMethod;
use App\Enums\AccessResult;
use App\Enums\DenialReason;
use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Enums\DeviceStatus;
use App\Http\Controllers\Admin\Concerns\ResolvesTargetTenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\BackupCodeSetResource;
use App\Http\Resources\DeviceCommandResource;
use App\Http\Resources\DeviceResource;
use App\Models\BackupCodeSet;
use App\Models\Device;
use App\Services\AccessEventRecorder;
use App\Services\AuditLogger;
use App\Services\BackupCodeService;
use App\Services\DeviceCommandService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    use ResolvesTargetTenant;

    public function __construct(
        private readonly DeviceCommandService $commands,
        private readonly BackupCodeService $backupCodes,
        private readonly AccessEventRecorder $events,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Device::class);

        $devices = Device::query()
            // A SuperAdmin in fleet view sees every tenant's doors at once, so
            // the owning tenant has to travel with each row.
            ->with('tenant')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)
                    ->orWhere('device_id', 'like', $term)
                    ->orWhere('location', 'like', $term));
            })
            ->when($request->boolean('online_only'), fn ($q) => $q->where('is_online', true))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return DeviceResource::collection($devices);
    }

    public function show(Device $device): DeviceResource
    {
        $this->authorize('view', $device);

        return new DeviceResource($device->load([
            'tenant',
            'activeBackupCodeSet.codes',
            'credentials',
            // Only what has not landed yet: a resolved command is history, and
            // history lives on the commands endpoint.
            'commands' => fn ($q) => $q->whereIn('status', [
                DeviceCommandStatus::Pending->value,
                DeviceCommandStatus::Sent->value,
            ])->latest('id'),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Device::class);

        // Resolved before validation, because the device_id uniqueness rule is
        // scoped per tenant — checking it against the wrong one would either
        // reject a legitimate id or allow a duplicate.
        $target = $this->resolveTargetTenant($request);

        return $this->withinTargetTenant($target, function () use ($request): JsonResponse {
            $validated = $request->validate([
                // Device-asserted, generated on the panel's first boot.
                'device_id' => [
                    'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                    Rule::unique('devices')->where('tenant_id', app(TenantContext::class)->id()),
                ],
                'name' => ['required', 'string', 'max:120'],
                'location' => ['nullable', 'string', 'max:160'],
                'template_capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
                // Only honoured for an operator holding `tenant.manage`; see
                // ResolvesTargetTenant.
                'tenant_id' => ['sometimes', 'nullable', 'uuid'],
            ]);

            $device = Device::create([
                ...Arr::except($validated, ['tenant_id']),
                'status' => DeviceStatus::Provisioned,
                'template_capacity' => $validated['template_capacity']
                    ?? config('access.devices.default_template_capacity'),
            ]);

            $this->audit->log('device.created', $device, after: $device->getAttributes());

            // `->response()` keeps the `data` envelope that every other resource
            // response uses; `response()->json($resource)` would silently drop it.
            // Re-read so database defaults (is_online, enrolled_count) are in
            // the response rather than nulls the model never loaded.
            return (new DeviceResource($device->fresh(['tenant'])))
                ->response()
                ->setStatusCode(201);
        });
    }

    public function update(Request $request, Device $device): DeviceResource
    {
        $this->authorize('update', $device);

        $before = $device->getAttributes();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'location' => ['sometimes', 'nullable', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(DeviceStatus::values())],
            'template_capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $device->update($validated);

        $this->audit->log('device.updated', $device, $before, $device->getAttributes());

        return new DeviceResource($device);
    }

    public function destroy(Device $device): JsonResponse
    {
        $this->authorize('delete', $device);

        $before = $device->getAttributes();

        // Soft-deleted, never purged: the access events referencing this door
        // have to keep resolving, and an audit trail with a hole in it is
        // worse than no audit trail.
        $device->delete();

        $this->audit->log('device.deleted', $device, $before);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /devices/{device}/unlock — remote unlock.
     *
     * Queued as a device command and recorded as an access event with
     * `method: remote` and the acting operator, so an out-of-hours door
     * opening is attributable to a person rather than appearing from nowhere.
     */
    public function unlock(Request $request, Device $device): JsonResponse
    {
        $this->authorize('unlock', $device);

        $command = $this->commands->queue(
            $device,
            DeviceCommandType::Unlock,
            ['reason' => $request->string('reason')->limit(120)->toString()],
            $request->user(),
        );

        $this->events->record($device, [
            'method' => AccessMethod::Remote,
            'result' => AccessResult::Granted,
            'reason' => DenialReason::RemoteUnlock,
            'actor_user_id' => $request->user()->id,
            'metadata' => ['command_id' => $command->uuid],
        ]);

        $this->audit->log('device.unlocked', $device, after: ['command_id' => $command->uuid]);

        return response()->json([
            'command' => new DeviceCommandResource($command),
            // The door does not open until the panel collects this on its next
            // poll — up to one health interval away. Saying so here keeps the
            // dashboard honest about what just happened.
            'note' => 'Queued. The device will act on it at its next poll.',
        ], 202);
    }

    /**
     * POST /devices/{device}/backup-codes/rotations — force a new set.
     *
     * The plaintext codes are in this response and in no other. There is no
     * endpoint that shows them again.
     */
    public function rotateBackupCodes(Request $request, Device $device): JsonResponse
    {
        $this->authorize('rotate', BackupCodeSet::class);

        $issued = $this->backupCodes->rotate($device, 'rotated', $request->user());

        $this->audit->log('backup_codes.rotated', $device, after: [
            'set_id' => $issued['set']->uuid,
        ]);

        return response()->json([
            'set' => new BackupCodeSetResource($issued['set']->load('codes')),
            'codes' => $issued['codes'],
            'warning' => 'These codes are shown once. Record them now.',
        ], 201);
    }

    /**
     * POST /devices/{device}/inventory-requests — ask the panel what it holds.
     */
    public function requestInventory(Request $request, Device $device): JsonResponse
    {
        $this->authorize('command', $device);

        $command = $this->commands->queue(
            $device,
            DeviceCommandType::ReportInventory,
            issuedBy: $request->user(),
            ttlSeconds: 3600,
        );

        return response()->json(['command' => new DeviceCommandResource($command)], 202);
    }

    /**
     * GET /devices/{device}/health — the run-length encoded poll history.
     */
    public function health(Request $request, Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $reports = $device->healthReports()
            ->latest('started_at')
            ->limit($request->integer('limit', 100))
            ->get(['state', 'started_at', 'last_seen_at', 'poll_count', 'firmware_version']);

        return response()->json([
            'data' => $reports->map(fn ($report): array => [
                'state' => $report->state,
                'started_at' => $report->started_at->toIso8601String(),
                'last_seen_at' => $report->last_seen_at->toIso8601String(),
                'poll_count' => $report->poll_count,
                'firmware_version' => $report->firmware_version,
            ]),
        ]);
    }
}
