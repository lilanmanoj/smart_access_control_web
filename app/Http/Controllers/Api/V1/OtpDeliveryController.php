<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreOtpDeliveryRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /otp-deliveries — legacy fan-out hook.
 *
 * Under the original contract the device held the plaintext code and asked the
 * backend to e-mail it. Under the current one the backend issues, delivers and
 * verifies the code itself, so there is nothing left for this call to do.
 *
 * It is kept, and kept fire-and-forget, because shipped firmware still calls
 * it and ignores the response. Accepting and discarding is better than a 404
 * the panel would log as a backend failure.
 */
class OtpDeliveryController extends Controller
{
    public function store(StoreOtpDeliveryRequest $request): JsonResponse
    {
        return response()->json([
            'queued' => [],
            'note' => 'Delivery is handled server-side when the code is issued.',
        ], 202);
    }
}
