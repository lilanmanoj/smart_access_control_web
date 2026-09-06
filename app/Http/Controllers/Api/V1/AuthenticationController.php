<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccessMethod;
use App\Enums\AccessResult;
use App\Enums\DenialReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreAuthenticationRequest;
use App\Services\AccessDecisionService;
use App\Services\AccessEventRecorder;
use Illuminate\Http\JsonResponse;

/**
 * POST /authentications — the Admin Settings gate.
 *
 * The firmware grants only on `"is_admin": true`. Anything else — a non-200, a
 * missing field, a transport failure — denies, and the flow is refused
 * outright when the backend is unreachable. That is the correct direction for
 * this endpoint to fail in, and nothing here should undermine it.
 */
class AuthenticationController extends Controller
{
    public function __construct(
        private readonly AccessEventRecorder $events,
        private readonly AccessDecisionService $decisions,
    ) {}

    public function store(
        StoreAuthenticationRequest $request,
    ): JsonResponse {
        $device = $request->device();

        // The firmware calls this field `enrollment_id`, but it carries the
        // device-local fingerprint slot.
        $slot = (int) $request->validated('enrollment_id');

        $enrollment = $device->enrollments()
            ->active()
            ->where('fingerprint_slot', $slot)
            ->with('member')
            ->first();

        $member = $enrollment?->member;

        $reason = match (true) {
            $member === null => DenialReason::NotEnrolled,
            ! $member->is_admin => DenialReason::NotAdmin,
            default => $this->decisions->denialReasonFor($member, $device) ?? DenialReason::AdminConfirmed,
        };

        $granted = $reason === DenialReason::AdminConfirmed;

        // Logged either way: "who opened the settings screen, and who tried"
        // belongs in the same trail as the door itself.
        $this->events->record($device, [
            'method' => AccessMethod::AdminAuth,
            'result' => $granted ? AccessResult::Granted : AccessResult::Denied,
            'reason' => $reason,
            'member_id' => $member?->id,
            'fingerprint_slot' => $slot,
        ]);

        if (! $granted) {
            return response()->json(['is_admin' => false]);
        }

        return response()->json([
            'is_admin' => true,
            'member' => [
                'member_id' => $member->uuid,
                'full_name' => $member->full_name,
            ],
        ]);
    }
}
