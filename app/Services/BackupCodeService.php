<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessMethod;
use App\Enums\AccessResult;
use App\Enums\BackupCodeSetStatus;
use App\Enums\DenialReason;
use App\Enums\DeviceCommandType;
use App\Events\BackupCodesRotated;
use App\Events\BackupCodeBruteForceSuspected;
use App\Models\BackupCode;
use App\Models\BackupCodeSet;
use App\Models\Device;
use App\Models\User;
use App\Support\NumericCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Backup code issuance, verification and rotation.
 *
 * These are the codes that keep the door working when the network is down, so
 * the default posture deliberately accepts that the panel caches five
 * plaintext codes in RAM. That trade is the whole point of the feature: a
 * backup method that stops working when the backend is unreachable is not a
 * backup method.
 *
 * The mitigations are the ones available given that choice — the backend
 * stores only hashes, a used code retires its whole set immediately, sets
 * expire on age, and repeated failures raise an alert.
 *
 * A tenant that would rather lose offline operation can set
 * `access.backup_codes.online_verification_only`, after which the panel caches
 * nothing and verifies through the API.
 */
class BackupCodeService
{
    public function __construct(
        private readonly AccessEventRecorder $events,
        private readonly DeviceCommandService $commands,
    ) {}

    /**
     * Serve a device's set for caching.
     *
     * Because only hashes are stored, a set cannot be handed out twice — there
     * is no plaintext left to hand out. So every fetch mints a fresh set and
     * retires the previous one. That follows from the storage decision rather
     * than working around it, and it is a better posture besides: a panel
     * reboot rotates the codes.
     *
     * The consequence to know about: if this response is lost in transit, the
     * panel keeps caching codes the backend has already superseded. Those
     * attempts come back as `code_expired` rather than `code_invalid`, which
     * tells an operator to make the device refetch instead of hunting an
     * intruder.
     *
     * @return array{set: BackupCodeSet, codes: list<string>}
     */
    public function issueForDevice(Device $device): array
    {
        $issued = $this->rotate($device, reason: 'initial', notifyDevice: false);

        $issued['set']->forceFill(['fetched_at' => now()])->save();

        return $issued;
    }

    /**
     * Retire the current set and issue a fresh one.
     *
     * @param  bool  $notifyDevice  Queue a refresh command. False when the
     *                              rotation was caused by the device's own
     *                              fetch, which needs no telling.
     * @return array{set: BackupCodeSet, codes: list<string>}
     */
    public function rotate(
        Device $device,
        string $reason = 'rotated',
        ?User $issuedBy = null,
        bool $notifyDevice = true,
    ): array {
        return DB::transaction(function () use ($device, $reason, $issuedBy, $notifyDevice): array {
            $device->backupCodeSets()
                ->where('status', BackupCodeSetStatus::Active->value)
                ->update([
                    'status' => BackupCodeSetStatus::Superseded->value,
                    'superseded_at' => now(),
                    'updated_at' => now(),
                ]);

            $set = new BackupCodeSet([
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'status' => BackupCodeSetStatus::Active,
                'issued_at' => now(),
                'issued_by' => $issuedBy?->id,
                'reason' => $reason,
            ]);

            $set->save();

            $count = (int) config('access.backup_codes.per_set', 5);
            $length = (int) config('access.backup_codes.length', 6);
            $codes = NumericCode::generateSet($count, $length);

            foreach ($codes as $code) {
                $set->codes()->create([
                    'code_hash' => $this->hash($code),
                    'last4' => substr($code, -4),
                ]);
            }

            // The panel refetches after using a code, but a queued command
            // covers the cases it would not notice: an operator-forced
            // rotation, or an age-based one.
            if ($notifyDevice) {
                $this->commands->queue($device, DeviceCommandType::RefreshBackupCodes, issuedBy: $issuedBy);
            }

            BackupCodesRotated::dispatch($set->fresh(['device']), $reason);

            return ['set' => $set, 'codes' => $codes];
        });
    }

    /**
     * Verify a code against the device's live set and, if it matches, spend it.
     *
     * Used by both the online-only verification endpoint and the attempt log:
     * the device's own accept/reject decision is recorded, but the backend
     * reaches its own conclusion rather than taking the device's word for it.
     */
    public function verify(Device $device, string $code): BackupCodeVerification
    {
        return DB::transaction(function () use ($device, $code): BackupCodeVerification {
            $set = $device->backupCodeSets()
                ->where('status', BackupCodeSetStatus::Active->value)
                ->lockForUpdate()
                ->latest('issued_at')
                ->first();

            if ($set === null) {
                return new BackupCodeVerification(false, null, DenialReason::CodeInvalid);
            }

            $hash = $this->hash($code);

            $match = $set->codes()
                ->whereNull('used_at')
                ->get()
                ->first(fn (BackupCode $candidate): bool => hash_equals($candidate->code_hash, $hash));

            if ($match === null) {
                // Distinguish "wrong code" from "a code from the set we just
                // replaced": the second means the panel is running on a stale
                // cache and should refetch, not that someone is guessing.
                $reason = $this->matchesSupersededSet($device, $hash)
                    ? DenialReason::CodeExpired
                    : DenialReason::CodeInvalid;

                return new BackupCodeVerification(false, null, $reason);
            }

            $match->forceFill(['used_at' => now()])->save();

            return new BackupCodeVerification(true, $match, DenialReason::CodeAccepted);
        });
    }

    /**
     * Record one entry attempt and, when it succeeds, retire the set.
     *
     * @param  bool|null  $deviceAccepted  What the panel decided locally, when it told us.
     */
    public function recordAttempt(
        Device $device,
        string $code,
        ?bool $deviceAccepted = null,
    ): BackupCodeVerification {
        $verification = $this->verify($device, $code);

        $event = $this->events->record($device, [
            'method' => AccessMethod::BackupCode,
            'result' => $verification->accepted ? AccessResult::Granted : AccessResult::Denied,
            'reason' => $verification->reason,
            'backup_code_id' => $verification->code?->id,
            'metadata' => array_filter([
                'device_accepted' => $deviceAccepted,
                // A disagreement means the panel is running on a stale cache,
                // which is worth surfacing rather than quietly reconciling.
                'decision_mismatch' => $deviceAccepted !== null
                    && $deviceAccepted !== $verification->accepted,
            ], fn ($value): bool => $value !== null),
        ]);

        if ($verification->accepted && $verification->code !== null) {
            if ($event !== null) {
                $verification->code->forceFill(['used_event_id' => $event->id])->save();
            }

            // Using any one code retires all five.
            $this->rotate($device, reason: 'used');
        } else {
            $this->checkForBruteForce($device);
        }

        return $verification;
    }

    /**
     * Repeated failures against a static secret are the clearest brute-force
     * signal this system has (§9.5). Raise it loudly rather than leaving it in
     * a log nobody reads.
     */
    private function checkForBruteForce(Device $device): void
    {
        $threshold = (int) config('access.backup_codes.alert_threshold', 5);
        $window = (int) config('access.backup_codes.alert_window_minutes', 10);

        if ($threshold <= 0) {
            return;
        }

        $failures = $device->accessEvents()
            ->where('method', AccessMethod::BackupCode->value)
            ->where('result', AccessResult::Denied->value)
            ->where('occurred_at', '>=', CarbonImmutable::now()->subMinutes($window))
            ->count();

        if ($failures >= $threshold) {
            BackupCodeBruteForceSuspected::dispatch($device, $failures, $window);
        }
    }

    private function matchesSupersededSet(Device $device, string $hash): bool
    {
        return BackupCode::query()
            ->whereHas('set', function ($set) use ($device): void {
                $set->where('device_id', $device->id)
                    ->where('status', BackupCodeSetStatus::Superseded->value);
            })
            ->where('code_hash', $hash)
            ->exists();
    }

    /**
     * Keyed with the application secret for the same reason as the OTP hash:
     * a six-digit code has a million possibilities, so an unkeyed digest of it
     * is a lookup table away from plaintext.
     */
    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
