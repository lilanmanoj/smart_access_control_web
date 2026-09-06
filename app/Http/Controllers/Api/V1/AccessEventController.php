<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreAccessEventsRequest;
use App\Services\AccessEventRecorder;
use Illuminate\Http\JsonResponse;

/**
 * POST /access-events — the most important endpoint in the system.
 *
 * Before this existed, a fingerprint match at the main screen opened the door
 * and logged only to the device's serial console; the backend never learned
 * that anyone had walked through. Every access decision now arrives here.
 *
 * Accepts a single event or a batch flushed from the panel's flash queue after
 * it has been offline. Replays are silently deduplicated — an offline device
 * will resend, sometimes more than once, and that is normal operation rather
 * than an error worth telling it about.
 */
class AccessEventController extends Controller
{
    public function __construct(
        private readonly AccessEventRecorder $recorder,
    ) {}

    public function store(StoreAccessEventsRequest $request): JsonResponse
    {
        $result = $this->recorder->recordBatch($request->device(), $request->events());

        return response()->json([
            'accepted' => $result['accepted'],
            'duplicates' => $result['duplicates'],
        ], 202);
    }
}
