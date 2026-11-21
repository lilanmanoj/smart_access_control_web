<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreBackupCodeAttemptRequest;
use App\Services\BackupCodeService;
use App\Support\DeviceContext;
use Illuminate\Http\JsonResponse;

/**
 * Backup codes — the method that keeps the door working when the network does
 * not.
 *
 * The panel validates the response strictly: exactly five codes, exactly six
 * digits each, or it rejects the set and keeps the one it holds. That
 * validation is the reason the shape here is rigid.
 */
class BackupCodeController extends Controller
{
    public function __construct(
        private readonly BackupCodeService $backupCodes,
    ) {}

    /**
     * GET /backup-codes
     *
     * The device's current set — the same set, codes and `issued_at` on every
     * call until it is retired or superseded (device API 1.3.0). The panel
     * polls this at each sync, which is how an operator's rotation reaches
     * the door. Only an authenticated, active device gets here.
     */
    public function index(DeviceContext $context): JsonResponse
    {
        $device = $context->deviceOrFail();

        // The online-only posture caches nothing on the panel, so there is
        // nothing to hand over: the device verifies through the API instead.
        if ($this->onlineOnly($context)) {
            return response()->json([
                'error' => [
                    'code' => 'online_verification_only',
                    'message' => 'This tenant verifies backup codes server-side. '.
                        'Use POST /backup-code-verifications.',
                ],
            ], 409);
        }

        $issued = $this->backupCodes->currentForDevice($device);

        return response()->json([
            'set_id' => $issued['set']->uuid,
            'codes' => $issued['codes'],
            'issued_at' => $issued['set']->issued_at->toIso8601ZuluString(),
        ]);
    }

    /**
     * POST /backup-codes/attempts
     *
     * Logs one entry attempt, accepted or rejected. Rate-limited per device:
     * repeated failures against a static six-digit secret are the clearest
     * brute-force signal the system has.
     */
    public function attempt(StoreBackupCodeAttemptRequest $request): JsonResponse
    {
        $verification = $this->backupCodes->recordAttempt(
            $request->device(),
            (string) $request->validated('code'),
            $request->has('accepted') ? $request->boolean('accepted') : null,
        );

        return response()->json([
            'logged' => true,
            'accepted' => $verification->accepted,
            'reason' => $verification->reason->value,
        ]);
    }

    /**
     * POST /backup-code-verifications
     *
     * The online-only path: the panel caches nothing and asks the backend.
     * Backup access stops working when the network is down — which, for a
     * *backup* method, may be exactly the wrong trade, so it is opt-in per
     * tenant and off by default.
     */
    public function verify(StoreBackupCodeAttemptRequest $request): JsonResponse
    {
        $verification = $this->backupCodes->recordAttempt(
            $request->device(),
            (string) $request->validated('code'),
        );

        return response()->json([
            'verified' => $verification->accepted,
            'reason' => $verification->reason->value,
        ]);
    }

    private function onlineOnly(DeviceContext $context): bool
    {
        return (bool) $context->deviceOrFail()->tenant?->setting(
            'backup_codes.online_verification_only',
            config('access.backup_codes.online_verification_only')
        );
    }
}
