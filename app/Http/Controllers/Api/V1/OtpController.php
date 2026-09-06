<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreOtpRequestRequest;
use App\Http\Requests\Device\StoreOtpVerificationRequest;
use App\Models\OtpRequest;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;

/**
 * OTP issuance and verification.
 *
 * The contract changed from the original firmware expectation and the change
 * is the point: `POST /otp-requests` no longer returns the code. Anyone
 * holding a device's API credentials could otherwise request a code for any
 * phone number and read it straight out of the response, and no verification
 * attempt could be counted centrally.
 *
 * Now the backend delivers the code and the panel submits what the user typed
 * to `POST /otp-verifications`. `access.otp.return_code_to_device` re-opens the
 * old shape for firmware that has not been updated — off by default.
 */
class OtpController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
    ) {}

    /**
     * POST /otp-requests
     *
     * `valid: false` means the number is not known to this device's tenant.
     * The panel shows "Phone number not found" and lets the user retry.
     */
    public function store(StoreOtpRequestRequest $request): JsonResponse
    {
        $issuance = $this->otp->issue(
            $request->device(),
            (string) $request->validated('phone'),
            $request->ip(),
        );

        if ($issuance === null) {
            // Deliberately the same answer for an unknown number and a
            // suspended member: a door is not a place to enumerate people.
            return response()->json(['valid' => false]);
        }

        $response = [
            'valid' => true,
            'otp_request_id' => $issuance->request->uuid,
            'expires_in' => $issuance->expiresIn,
        ];

        if (config('access.otp.return_code_to_device')) {
            // Legacy compatibility only. This hands the plaintext code to
            // anyone holding the device credential; see §7.3.
            $response['otp'] = $issuance->code;
        }

        return response()->json($response);
    }

    /**
     * POST /otp-verifications
     *
     * Always 200: "wrong code" is an answer, not a transport failure, and the
     * panel branches on the `verified` field.
     */
    public function verify(StoreOtpVerificationRequest $request): JsonResponse
    {
        $device = $request->device();

        $otpRequest = OtpRequest::query()
            ->where('uuid', $request->validated('otp_request_id'))
            ->where('device_id', $device->id)
            ->first();

        if ($otpRequest === null) {
            return response()->json([
                'verified' => false,
                'reason' => 'otp_mismatch',
                'attempts_left' => 0,
            ]);
        }

        $verification = $this->otp->verify(
            $device,
            $otpRequest,
            (string) $request->validated('code'),
        );

        if (! $verification->verified) {
            return response()->json([
                'verified' => false,
                'reason' => $verification->reason?->value ?? 'otp_mismatch',
                'attempts_left' => $verification->attemptsLeft,
            ]);
        }

        return response()->json([
            'verified' => true,
            'member_id' => $verification->request?->member?->uuid,
            'event_id' => $verification->eventUuid,
        ]);
    }
}
