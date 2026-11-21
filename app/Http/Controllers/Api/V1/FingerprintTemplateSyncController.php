<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreTemplateSyncReportRequest;
use App\Services\TemplateSyncService;
use Illuminate\Http\JsonResponse;

/**
 * POST /fingerprint-template-syncs — what a template sync actually did.
 *
 * Every report is stored; the panel does not retry a failed one. A report
 * that ran to the end carries `slots_present`, the sensor's real occupancy,
 * which is the only thing that frees a released slot for reuse.
 */
class FingerprintTemplateSyncController extends Controller
{
    public function __construct(
        private readonly TemplateSyncService $sync,
    ) {}

    public function store(StoreTemplateSyncReportRequest $request): JsonResponse
    {
        $this->sync->accept($request->device(), $request->validated());

        return response()->json(['accepted' => true], 201);
    }
}
