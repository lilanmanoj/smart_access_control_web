<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use App\Enums\AccessMethod;
use App\Enums\AccessResult;
use App\Enums\DenialReason;
use Illuminate\Validation\Rule;

/**
 * A single event or a batch flushed from the panel's offline queue.
 */
class StoreAccessEventsRequest extends DeviceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxBatch = (int) config('access.events.max_batch_size', 100);

        return array_merge($this->deviceRules(), [
            'events' => ['required', 'array', 'min:1', "max:{$maxBatch}"],

            'events.*.method' => ['required', Rule::in(AccessMethod::values())],
            'events.*.result' => ['required', Rule::in(AccessResult::values())],
            'events.*.reason' => ['required', Rule::in(DenialReason::values())],

            // Device-generated and unique per device. Optional, because a
            // device that has not implemented the offline queue yet still has
            // events worth recording — it just cannot be deduplicated.
            'events.*.idempotency_key' => ['nullable', 'string', 'max:128'],

            'events.*.fingerprint_slot' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'events.*.confidence' => ['nullable', 'integer', 'min:0', 'max:65535'],

            // Ordering within the batch only; never a wall clock.
            'events.*.uptime_ms' => ['nullable', 'integer', 'min:0'],

            'events.*.member_uuid' => ['nullable', 'uuid'],

            // Bounded so a device cannot use the audit trail as free storage.
            'events.*.metadata' => ['nullable', 'array', 'max:20'],
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'events.max' => 'A batch may carry at most :max events.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return array_values($this->validated('events'));
    }
}
