<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

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
use App\Services\AuditLogger;
use App\Services\BackupCodeService;
use App\Services\DeviceCommandService;
use App\Services\Realtime\DeviceRealtime;
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
        private readonly DeviceRealtime $realtime,
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
            'latestTemplateSyncReport',
        ])->loadCount([
            'templateSlots as active_template_slots_count' => fn ($q) => $q->active(),
            'templateSlots as releasing_template_slots_count' => fn ($q) => $q->releasing(),
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
                // IANA name. The dashboard pre-selects the tenant's.
                'timezone' => ['required', 'string', 'timezone:all'],
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
            // Optional on an edit (Activate sends only `status`), but never
            // clearable once set.
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
            'status' => ['sometimes', Rule::in(DeviceStatus::values())],
            'template_capacity' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $device->update($validated);

        $this->audit->log('device.updated', $device, $before, $device->getAttributes());

        // A deactivated door is refused by the API; shut it out of the MQTT
        // broker as well, which also ends its live session. Reactivating turns
        // the same login back on: a panel that registered earlier in this boot
        // does not register again, and would otherwise wait for its hourly
        // retry before its doorbell worked.
        if ($device->wasChanged('status')) {
            $device->status === DeviceStatus::Active
                ? $this->realtime->restoreCredentials($device)
                : $this->realtime->revokeCredentials($device);
        }

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

        $this->realtime->revokeCredentials($device);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /devices/{device}/unlock — remote unlock.
     *
     * Queued as a device command. The access event — `method: remote`, the
     * acting operator — is written when the panel acknowledges that the door
     * opened (device API 1.4.0), so an unlock that expires or is refused never
     * appears in the log as an opening. The request itself is audited now.
     */
    public function unlock(Request $request, Device $device): JsonResponse
    {
        $this->authorize('unlock', $device);

        // Kept on the command for the dashboard and audit trail; never sent
        // to the panel.
        $reason = $request->string('reason')->limit(120)->toString();

        $command = $this->commands->queue(
            $device,
            DeviceCommandType::Unlock,
            $reason === '' ? [] : ['reason' => $reason],
            $request->user(),
        );

        $this->audit->log('device.unlocked', $device, after: ['command_id' => $command->uuid]);

        return response()->json([
            'command' => new DeviceCommandResource($command),
            'note' => 'Queued. The door opens when the panel collects it: within a few seconds '.
                'when its realtime connection is up, otherwise at its next health poll, about '.
                '30 seconds at most. The request expires at expires_at if the panel never collects it.',
        ], 202);
    }

    /**
     * POST /devices/{device}/backup-codes/rotations — force a new set.
     *
     * The plaintext codes are in this response and in no other dashboard
     * response; no endpoint shows them to an operator again. The door
     * collects the new set at its next sync and keeps accepting the previous
     * set until then.
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
            'warning' => 'These codes are shown once. Record them now. The door '.
                'starts accepting them at its next sync.',
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
