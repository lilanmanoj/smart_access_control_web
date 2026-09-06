<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessMethod;
use App\Enums\AccessResult;
use App\Enums\DenialReason;
use App\Events\AccessEventRecorded;
use App\Models\AccessEvent;
use App\Models\Device;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Writes the audit trail.
 *
 * Three things make this more than an insert:
 *
 *  - **Idempotency.** A panel queues events in flash while offline and resends
 *    the queue on reconnect, sometimes more than once. Replays are silently
 *    deduplicated on (device, idempotency_key).
 *  - **Server-assigned time.** The device has no RTC; its clock reads 1970
 *    until NTP lands. `occurred_at` is stamped here, on receipt, and the
 *    device's uptime only ever orders events within a batch.
 *  - **Duplicate suppression.** The AS608 reports a held finger repeatedly.
 *    Consecutive grants for the same member on the same door collapse into one
 *    row so the log stays readable. Denials are never suppressed — a burst of
 *    those is exactly what an operator needs to see.
 */
class AccessEventRecorder
{
    /**
     * Record one event.
     *
     * Returns null when the event was a replay or a suppressed duplicate; both
     * are successes from the device's point of view, not errors.
     *
     * @param  array{
     *     method: AccessMethod,
     *     result: AccessResult,
     *     reason: DenialReason,
     *     member_id?: int|null,
     *     fingerprint_slot?: int|null,
     *     confidence?: int|null,
     *     otp_request_id?: int|null,
     *     backup_code_id?: int|null,
     *     actor_user_id?: int|null,
     *     idempotency_key?: string|null,
     *     uptime_ms?: int|null,
     *     device_reported_at?: CarbonImmutable|null,
     *     occurred_at?: CarbonImmutable|null,
     *     metadata?: array<string, mixed>|null,
     * }  $attributes
     */
    public function record(Device $device, array $attributes): ?AccessEvent
    {
        $occurredAt = $attributes['occurred_at'] ?? CarbonImmutable::now();

        if ($this->isSuppressedDuplicate($device, $attributes, $occurredAt)) {
            return null;
        }

        $event = new AccessEvent([
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'member_id' => $attributes['member_id'] ?? null,
            'method' => $attributes['method'],
            'result' => $attributes['result'],
            'reason' => $attributes['reason']->value,
            'occurred_at' => $occurredAt,
            'device_reported_at' => $attributes['device_reported_at'] ?? null,
            'uptime_ms' => $attributes['uptime_ms'] ?? null,
            'fingerprint_slot' => $attributes['fingerprint_slot'] ?? null,
            'confidence' => $attributes['confidence'] ?? null,
            'otp_request_id' => $attributes['otp_request_id'] ?? null,
            'backup_code_id' => $attributes['backup_code_id'] ?? null,
            'actor_user_id' => $attributes['actor_user_id'] ?? null,
            'idempotency_key' => $attributes['idempotency_key'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ]);

        try {
            $event->save();
        } catch (QueryException $exception) {
            // A replayed idempotency key trips the unique index. That is the
            // expected outcome of an offline device resending its queue, not a
            // failure — swallow it and report the row as a duplicate.
            if ($this->isDuplicateKeyViolation($exception)) {
                return null;
            }

            throw $exception;
        }

        AccessEventRecorded::dispatch($event);

        return $event;
    }

    /**
     * Record a batch submitted by a device flushing its offline queue.
     *
     * Events are applied in device-uptime order so that, within one batch,
     * the sequence the door actually saw is preserved.
     *
     * @param  list<array<string, mixed>>  $events  Already-validated payloads.
     * @return array{accepted: int, duplicates: int, events: list<AccessEvent>}
     */
    public function recordBatch(Device $device, array $events): array
    {
        usort($events, fn (array $a, array $b): int => ($a['uptime_ms'] ?? 0) <=> ($b['uptime_ms'] ?? 0));

        $latestUptime = $this->latestUptime($events);
        $receivedAt = CarbonImmutable::now();

        $accepted = 0;
        $duplicates = 0;
        $recorded = [];

        foreach ($events as $payload) {
            $attributes = $this->hydrate($device, $payload, $receivedAt, $latestUptime);

            $event = DB::transaction(fn (): ?AccessEvent => $this->record($device, $attributes));

            if ($event === null) {
                $duplicates++;

                continue;
            }

            $accepted++;
            $recorded[] = $event;
        }

        return [
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'events' => $recorded,
        ];
    }

    /**
     * Turn one device payload into recorder attributes, resolving the slot to
     * a member and estimating when the event actually happened.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function hydrate(
        Device $device,
        array $payload,
        CarbonImmutable $receivedAt,
        ?int $latestUptime,
    ): array {
        $slot = isset($payload['fingerprint_slot']) ? (int) $payload['fingerprint_slot'] : null;
        $uptime = isset($payload['uptime_ms']) ? (int) $payload['uptime_ms'] : null;

        return [
            'method' => AccessMethod::from($payload['method']),
            'result' => AccessResult::from($payload['result']),
            'reason' => DenialReason::from($payload['reason']),
            'member_id' => $this->resolveMemberId($device, $payload, $slot),
            'fingerprint_slot' => $slot,
            'confidence' => isset($payload['confidence']) ? (int) $payload['confidence'] : null,
            'idempotency_key' => $payload['idempotency_key'] ?? null,
            'uptime_ms' => $uptime,
            // Server-assigned, always: the device's wall clock is not
            // trustworthy and never becomes the record of when a door opened.
            'occurred_at' => $receivedAt,
            'device_reported_at' => $this->estimateDeviceTime($receivedAt, $uptime, $latestUptime),
            'metadata' => isset($payload['metadata']) && is_array($payload['metadata'])
                ? $payload['metadata']
                : null,
        ];
    }

    /**
     * Attribute an event to a member.
     *
     * A device only knows slot numbers, so the backend does the lookup. An
     * unmatched finger has no member — the denial is still recorded.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveMemberId(Device $device, array $payload, ?int $slot): ?int
    {
        if (isset($payload['member_uuid'])) {
            return Member::query()->where('uuid', $payload['member_uuid'])->value('id');
        }

        if ($slot === null) {
            return null;
        }

        return $device->enrollments()
            ->holdingSlot()
            ->where('fingerprint_slot', $slot)
            ->value('member_id');
    }

    /**
     * Reconstruct roughly when the device saw an event, from how much of its
     * uptime had elapsed by then. Ordering information only — the column is
     * documented as such and nothing decides anything from it.
     */
    private function estimateDeviceTime(
        CarbonImmutable $receivedAt,
        ?int $uptime,
        ?int $latestUptime,
    ): ?CarbonImmutable {
        if ($uptime === null || $latestUptime === null || $latestUptime < $uptime) {
            return null;
        }

        return $receivedAt->subMilliseconds($latestUptime - $uptime);
    }

    /** @param  list<array<string, mixed>>  $events */
    private function latestUptime(array $events): ?int
    {
        $uptimes = array_filter(array_column($events, 'uptime_ms'), fn ($v): bool => $v !== null);

        return $uptimes === [] ? null : max(array_map('intval', $uptimes));
    }

    /**
     * Anti-passback (§9.6). A member holding their finger on the pad produces
     * a burst of identical grants; one row is the useful record of that.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function isSuppressedDuplicate(
        Device $device,
        array $attributes,
        CarbonImmutable $occurredAt,
    ): bool {
        $window = (int) config('access.events.duplicate_suppression_seconds');

        if ($window <= 0) {
            return false;
        }

        if (($attributes['result'] ?? null) !== AccessResult::Granted) {
            return false;
        }

        $memberId = $attributes['member_id'] ?? null;

        if ($memberId === null) {
            return false;
        }

        // Remote unlocks are operator actions: two in a row are two decisions,
        // not one held finger.
        if (($attributes['method'] ?? null) === AccessMethod::Remote) {
            return false;
        }

        return AccessEvent::query()
            ->where('device_id', $device->id)
            ->where('member_id', $memberId)
            ->where('result', AccessResult::Granted->value)
            ->where('method', ($attributes['method'] ?? AccessMethod::Fingerprint)->value)
            // Bounded at both ends. Only the window immediately *before* this
            // event counts as the same held finger; a later row — back-filled
            // history, or a clock that moved — is a different entry.
            ->whereBetween('occurred_at', [$occurredAt->subSeconds($window), $occurredAt])
            ->exists();
    }

    private function isDuplicateKeyViolation(QueryException $exception): bool
    {
        // MySQL 1062 / SQLSTATE 23000 — integrity constraint violation.
        return ($exception->errorInfo[1] ?? null) === 1062
            || $exception->getCode() === '23000';
    }
}
