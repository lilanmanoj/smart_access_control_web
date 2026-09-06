<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\EnrollmentResource;
use App\Models\Device;
use App\Models\Enrollment;
use App\Models\Member;
use App\Services\AuditLogger;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Enrolments are created at the panel — that is where the finger is — so the
 * dashboard only reads and revokes them.
 */
class EnrollmentController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Enrollment::class);

        $query = Enrollment::query()->with(['device', 'member']);

        if ($request->filled('device_id')) {
            $query->where('device_id', Device::query()->where('uuid', $request->string('device_id'))->value('id') ?? 0);
        }

        if ($request->filled('member_id')) {
            $query->where('member_id', Member::query()->where('uuid', $request->string('member_id'))->value('id') ?? 0);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return EnrollmentResource::collection(
            $query->orderByDesc('id')->paginate($request->integer('per_page', 25))
        );
    }

    /**
     * DELETE /enrollments/{enrollment}
     *
     * Revokes and queues a `delete_enrollment` command so the template is
     * erased from the sensor. Without that second half, the member is
     * withdrawn on paper and their finger still opens the door.
     */
    public function destroy(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('revoke', $enrollment);

        $before = $enrollment->getAttributes();
        $slot = $enrollment->fingerprint_slot;

        $this->enrollments->revoke($enrollment, request()->user());

        $this->audit->log('enrollment.revoked', $enrollment, $before, $enrollment->getAttributes());

        return response()->json([
            'enrollment' => new EnrollmentResource($enrollment->fresh(['device', 'member'])),
            'note' => $slot === null
                ? 'Revoked.'
                : 'Revoked. The template erase is queued and takes effect when the device next polls.',
        ]);
    }
}
