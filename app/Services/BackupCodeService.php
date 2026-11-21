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
use Illuminate\Contracts\Encryption\DecryptException;
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
 * The mitigations are the ones available given that choice — verification
 * runs against keyed hashes, the one plaintext copy (the device's current set,
 * encrypted at rest so the panel can sync it) is deleted the moment the set
 * is superseded or retired, a used code retires its whole set immediately,
 * and repeated failures raise an alert.
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
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Serve the device's current set (device API 1.3.0).
     *
     * The panel calls this on every sync, so it must be idempotent: the same
     * set, codes and `issued_at` on every call until the set is retired (a
     * code was used) or superseded (an operator rotated it, or the age job
     * did). Only when the device has no current set is one minted — `initial`
     * for its first, `after_use` once a used set has been retired.
     *
     * Each call records the collection, so the dashboard can say whether a
     * rotated set has reached the door yet. The first collection of a set is
     * audited; the polls after it are not.
     *
     * @return array{set: BackupCodeSet, codes: list<string>}
     */
    public function currentForDevice(Device $device): array
    {
        return DB::transaction(function () use ($device): array {
            // Serialise concurrent fetches from one device, so two in flight
            // cannot each mint a set.
            DB::table('devices')->where('id', $device->id)->lockForUpdate()->first();

            $set = $device->backupCodeSets()
                ->where('status', BackupCodeSetStatus::Active->value)
                ->latest('issued_at')
                ->first();

            $codes = $set === null ? null : $this->plaintextOf($set);

            // A set issued before plaintext was kept, or one that no longer
            // decrypts (the application key changed), cannot be re-served.
            if ($set === null || $codes === null) {
                ['set' => $set, 'codes' => $codes] = $this->rotate(
                    $device,
                    reason: $this->reasonForNewSet($device),
                    notifyDevice: false,
                );
            }

            $firstCollection = $set->fetched_at === null;

            $set->forceFill([
                'fetched_at' => $set->fetched_at ?? now(),
                'last_fetched_at' => now(),
            ])->save();

            if ($firstCollection) {
                $this->audit->log('backup_codes.collected', $device, after: [
                    'set_id' => $set->uuid,
                    'reason' => $set->reason,
                ]);
            }

            return ['set' => $set, 'codes' => $codes];
        });
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
            $this->supersedeActive($device);

            $count = (int) config('access.backup_codes.per_set', 5);
            $length = (int) config('access.backup_codes.length', 6);
            $codes = NumericCode::generateSet($count, $length);

            $set = new BackupCodeSet([
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'status' => BackupCodeSetStatus::Active,
                'issued_at' => now(),
                'issued_by' => $issuedBy?->id,
                'reason' => $reason,
                // Kept so the panel can collect this set at each sync.
                'codes_encrypted' => $codes,
            ]);

            $set->save();

            foreach ($codes as $code) {
                $set->codes()->create([
                    'code_hash' => $this->hash($code),
                    'last4' => substr($code, -4),
                ]);
            }

            // The panel collects the new set at its next sync. The command is
            // a hint for a panel that acts on it sooner.
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
     * Two kinds of caller, told apart by whether the panel states its own
     * verdict:
     *
     *  - **Cached-set panels** decide locally, send `accepted`, and report the
     *    same attempt again through `POST /access-events`, where it is queued
     *    while offline. That report is the access-log row: it says what
     *    happened at the door, which is the panel's decision. Writing a second
     *    row here would log every attempt twice and halve the brute-force
     *    threshold. A disagreement — the panel running on a set the backend
     *    has superseded — goes to the audit log instead.
     *  - **Everything else** (the online-only verification path, and panels
     *    that do not state a verdict) gets the access-log row from here.
     *
     * Either way a used set is retired without minting a replacement. The
     * next `GET /backup-codes` mints one (`after_use`); nobody has seen its
     * codes, so an operator rotates to get codes to hand out.
     *
     * @param  bool|null  $deviceAccepted  What the panel decided locally, when it told us.
     */
    public function recordAttempt(
        Device $device,
        string $code,
        ?bool $deviceAccepted = null,
    ): BackupCodeVerification {
        $verification = $this->verify($device, $code);

        if ($deviceAccepted !== null) {
            if ($deviceAccepted !== $verification->accepted) {
                $this->audit->log('backup_code.decision_mismatch', $device, after: [
                    'device_accepted' => $deviceAccepted,
                    'backend_reason' => $verification->reason->value,
                ]);
            }

            if ($verification->accepted) {
                $this->supersedeActive($device, $verification->code?->set_id);
            } else {
                // This attempt's own row arrives separately, so count it here.
                $this->checkForBruteForce($device, pending: $deviceAccepted ? 0 : 1);
            }

            return $verification;
        }

        $event = $this->events->record($device, [
            'method' => AccessMethod::BackupCode,
            'result' => $verification->accepted ? AccessResult::Granted : AccessResult::Denied,
            'reason' => $verification->reason,
            'backup_code_id' => $verification->code?->id,
        ]);

        if ($verification->accepted && $verification->code !== null) {
            if ($event !== null) {
                $verification->code->forceFill(['used_event_id' => $event->id])->save();
            }

            // Using any one code retires all five.
            $this->supersedeActive($device, $verification->code->set_id);
        } else {
            $this->checkForBruteForce($device);
        }

        return $verification;
    }

    /**
     * Supersede the device's active set — or only the given one — and delete
     * its plaintext. Used by a rotation and when a code is used.
     */
    private function supersedeActive(Device $device, ?int $setId = null): void
    {
        $device->backupCodeSets()
            ->where('status', BackupCodeSetStatus::Active->value)
            ->when($setId !== null, fn ($query) => $query->whereKey($setId))
            ->update([
                'status' => BackupCodeSetStatus::Superseded->value,
                'superseded_at' => now(),
                'codes_encrypted' => null,
                'updated_at' => now(),
            ]);
    }

    /** @return list<string>|null */
    private function plaintextOf(BackupCodeSet $set): ?array
    {
        try {
            $codes = $set->codes_encrypted;
        } catch (DecryptException) {
            return null;
        }

        return is_array($codes) && $codes !== [] ? array_values($codes) : null;
    }

    /**
     * `after_use` when the device's most recent set was ended by a used code,
     * `initial` otherwise (first contact, or a set issued before plaintext was
     * kept).
     */
    private function reasonForNewSet(Device $device): string
    {
        $latest = $device->backupCodeSets()->latest('issued_at')->latest('id')->first();

        $used = $latest !== null && $latest->codes()->whereNotNull('used_at')->exists();

        return $used ? 'after_use' : 'initial';
    }

    /**
     * Repeated failures against a static secret are the clearest brute-force
     * signal this system has (§9.5). Raise it loudly rather than leaving it in
     * a log nobody reads.
     */
    private function checkForBruteForce(Device $device, int $pending = 0): void
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
            ->count() + $pending;

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
