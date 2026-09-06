<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceCredentialResource;
use App\Models\Device;
use App\Models\DeviceCredential;
use App\Services\AuditLogger;
use App\Services\DeviceCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceCredentialController extends Controller
{
    public function __construct(
        private readonly DeviceCredentialService $credentials,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Device $device): AnonymousResourceCollection
    {
        $this->authorize('manageCredentials', $device);

        return DeviceCredentialResource::collection(
            $device->credentials()->latest('id')->get()
        );
    }

    /**
     * Issue a credential. The secret is in this response and nowhere else —
     * the database holds only a digest of it.
     */
    public function store(Request $request, Device $device): JsonResponse
    {
        $this->authorize('manageCredentials', $device);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $issued = $this->credentials->issue($device, $request->user(), $validated['label'] ?? null);

        $this->audit->log('device_credential.issued', $issued['credential'], after: [
            'api_key' => $issued['api_key'],
        ]);

        return response()->json([
            'credential' => new DeviceCredentialResource($issued['credential']),
            // Exactly the three values the panel's settings portal asks for.
            'device_id' => $device->device_id,
            'api_key' => $issued['api_key'],
            'api_secret' => $issued['api_secret'],
            'warning' => 'The secret is shown once. Enter it into the device now.',
        ], 201);
    }

    public function destroy(Device $device, DeviceCredential $credential): JsonResponse
    {
        $this->authorize('manageCredentials', $device);

        abort_if($credential->device_id !== $device->id, 404);

        $this->credentials->revoke($credential);

        $this->audit->log('device_credential.revoked', $credential);

        return response()->json(['ok' => true]);
    }
}
