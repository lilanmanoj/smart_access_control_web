<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreEnrollmentRequest;
use App\Http\Requests\Device\UpdateEnrollmentRequest;
use App\Models\Member;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;

/**
 * Enrolment from the panel.
 *
 * Two steps, because that is how the device's screens work: the template is
 * stored first and the phone number is captured on the next screen.
 *
 * Note the field naming — the firmware currently calls the returned id
 * `user_id`; here and everywhere else it is `member_id`, because `user` means
 * a dashboard operator (firmware change 4).
 */
class EnrollmentController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
    ) {}

    /**
     * POST /enrollments — a template has just been written to a sensor slot.
     */
    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        $slot = $request->validated('fingerprint_slot');

        $enrollment = $this->enrollments->beginFromSlot(
            $request->device(),
            $slot === null ? null : (int) $slot,
        );

        return response()->json([
            'member_id' => $enrollment->member->uuid,
            'enrollment_id' => $enrollment->uuid,
            'status' => $enrollment->status->value,
        ], 201);
    }

    /**
     * PATCH /enrollments/{member} — attach the phone number, activating it.
     */
    public function update(UpdateEnrollmentRequest $request, Member $member): JsonResponse
    {
        $slot = $request->validated('fingerprint_slot');

        $enrollment = $this->enrollments->attachPhone(
            $request->device(),
            $member,
            (string) $request->validated('phone'),
            $slot === null ? null : (int) $slot,
        );

        return response()->json([
            'member_id' => $member->uuid,
            'enrollment_id' => $enrollment->uuid,
            'status' => $enrollment->status->value,
        ]);
    }
}
