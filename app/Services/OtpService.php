<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessMethod;
use App\Enums\AccessResult;
use App\Enums\DenialReason;
use App\Enums\OtpStatus;
use App\Jobs\DeliverOtp;
use App\Models\Device;
use App\Models\Member;
use App\Models\OtpRequest;
use App\Support\NumericCode;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * OTP issuance and verification.
 *
 * The important design decision here is that the plaintext code never reaches
 * the panel. The original contract returned it in the /otp-requests response
 * and let the device compare locally, which meant anyone holding the device's
 * API credentials could request a code for any phone number and read it
 * straight out of the response — and no verification attempt could be
 * rate-limited centrally.
 *
 * Instead: the backend issues the code, delivers it over SMS and e-mail, and
 * the device submits what the user typed to /otp-verifications. The code is
 * stored hashed and is unreadable even from the database.
 *
 * `access.otp.return_code_to_device` re-opens the old behaviour for firmware
 * that has not been updated yet. It is off by default and documented as a
 * weakness wherever it appears.
 */
class OtpService
{
    public function __construct(
        private readonly AccessEventRecorder $events,
        private readonly AccessDecisionService $decisions,
    ) {}

    /**
     * Look a phone number up and issue a code for it.
     *
     * Returns null when the number belongs to nobody this device can admit —
     * the panel shows "Phone number not found" and lets the user retry. The
     * same null is returned for a suspended member as for an unknown number:
     * the door is not a place to learn who exists.
     */
    public function issue(Device $device, string $phone, ?string $requestIp = null): ?OtpIssuance
    {
        $number = PhoneNumber::tryParse($phone);

        if ($number === null) {
            return null;
        }

        $member = $this->findEligibleMember($device, (string) $number);

        if ($member === null) {
            $this->events->record($device, [
                'method' => AccessMethod::Otp,
                'result' => AccessResult::Denied,
                'reason' => DenialReason::NotEnrolled,
                'metadata' => ['phone' => $number->masked()],
            ]);

            return null;
        }

        if ($denial = $this->decisions->denialReasonFor($member, $device)) {
            $this->events->record($device, [
                'method' => AccessMethod::Otp,
                'result' => AccessResult::Denied,
                'reason' => $denial,
                'member_id' => $member->id,
                'metadata' => ['phone' => $number->masked()],
            ]);

            return null;
        }

        $code = NumericCode::generate((int) config('access.otp.length', 6));
        $ttl = (int) config('access.otp.ttl', 90);

        $request = new OtpRequest([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'member_id' => $member->id,
            'phone' => (string) $number,
            'code_hash' => $this->hash($code),
            'status' => OtpStatus::Issued,
            'max_attempts' => (int) config('access.otp.max_attempts', 3),
            'expires_at' => CarbonImmutable::now()->addSeconds($ttl),
            'request_ip' => $requestIp,
            'delivery' => [],
        ]);

        $request->save();

        // Delivery is queued so a slow gateway never stalls the panel: the
        // user is standing at the door waiting for the screen to advance.
        DeliverOtp::dispatch($request->id, $code);

        return new OtpIssuance($request, $code, $ttl);
    }

    /**
     * Verify a typed code.
     *
     * Every outcome — right, wrong, expired, exhausted — is written to the
     * access log, because "someone stood at the door trying codes" is exactly
     * what the log exists to answer.
     */
    public function verify(Device $device, OtpRequest $request, string $code): OtpVerification
    {
        return DB::transaction(function () use ($device, $request, $code): OtpVerification {
            // Re-read under a row lock: two rapid submissions must not both
            // spend the same remaining attempt.
            $request = OtpRequest::query()->lockForUpdate()->find($request->id);

            if ($request === null) {
                return $this->denyVerification($device, null, DenialReason::OtpMismatch, 0);
            }

            if ($request->isExpired()) {
                $request->forceFill(['status' => OtpStatus::Expired])->save();

                return $this->denyVerification($device, $request, DenialReason::OtpExpired, 0);
            }

            if (! $request->isVerifiable()) {
                return $this->denyVerification($device, $request, DenialReason::OtpMismatch, 0);
            }

            $request->increment('attempts');

            if (! hash_equals($request->code_hash, $this->hash($code))) {
                $attemptsLeft = $request->attemptsLeft();

                if ($attemptsLeft <= 0) {
                    $request->forceFill(['status' => OtpStatus::Failed])->save();
                }

                return $this->denyVerification($device, $request, DenialReason::OtpMismatch, $attemptsLeft);
            }

            $member = $request->member;

            // The member's standing can have changed between issue and entry —
            // a suspension in the last ninety seconds still has to hold.
            if ($member !== null && ($denial = $this->decisions->denialReasonFor($member, $device))) {
                $request->forceFill(['status' => OtpStatus::Failed])->save();

                return $this->denyVerification($device, $request, $denial, 0);
            }

            $request->forceFill([
                'status' => OtpStatus::Verified,
                'verified_at' => now(),
            ])->save();

            $event = $this->events->record($device, [
                'method' => AccessMethod::Otp,
                'result' => AccessResult::Granted,
                'reason' => DenialReason::OtpVerified,
                'member_id' => $request->member_id,
                'otp_request_id' => $request->id,
            ]);

            return new OtpVerification(true, $request, null, 0, $event?->uuid);
        });
    }

    /**
     * Find the member a code may be sent to.
     *
     * Whether an OTP requires an existing enrolment on *this* device is a
     * per-tenant decision (open question 4). The default admits any active
     * member of the tenant, which is what the current firmware expects.
     */
    private function findEligibleMember(Device $device, string $phone): ?Member
    {
        $query = Member::query()->where('phone', $phone);

        $requiresEnrollment = $device->tenant?->setting(
            'otp.require_enrollment',
            config('access.otp.require_enrollment')
        );

        if ($requiresEnrollment) {
            $query->whereHas('enrollments', function ($enrollments) use ($device): void {
                $enrollments->where('device_id', $device->id)->active();
            });
        }

        return $query->first();
    }

    private function denyVerification(
        Device $device,
        ?OtpRequest $request,
        DenialReason $reason,
        int $attemptsLeft,
    ): OtpVerification {
        $this->events->record($device, [
            'method' => AccessMethod::Otp,
            'result' => AccessResult::Denied,
            'reason' => $reason,
            'member_id' => $request?->member_id,
            'otp_request_id' => $request?->id,
        ]);

        return new OtpVerification(false, $request, $reason, $attemptsLeft, null);
    }

    /**
     * A six-digit code has only a million possibilities, so the hash is
     * keyed with the application secret: a stolen database alone does not let
     * an attacker enumerate live codes offline.
     */
    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
