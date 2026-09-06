<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\DenialReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AccessEvent */
class AccessEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'method' => $this->method->value,
            'method_label' => $this->method->label(),
            'result' => $this->result->value,
            'reason' => $this->reason,
            // Sent alongside the code so the UI never has to hold its own copy
            // of this vocabulary.
            'reason_label' => DenialReason::tryFrom($this->reason)?->label() ?? $this->reason,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'device_reported_at' => $this->device_reported_at?->toIso8601String(),
            'fingerprint_slot' => $this->fingerprint_slot,
            'confidence' => $this->confidence,
            'metadata' => $this->metadata,
            'device' => $this->whenLoaded('device', fn (): array => [
                'id' => $this->device->uuid,
                'device_id' => $this->device->device_id,
                'name' => $this->device->name,
                'location' => $this->device->location,
            ]),
            'member' => $this->whenLoaded('member', fn (): ?array => $this->member === null ? null : [
                'id' => $this->member->uuid,
                'full_name' => $this->member->full_name,
            ]),
            'actor' => $this->whenLoaded('actor', fn (): ?array => $this->actor === null ? null : [
                'id' => $this->actor->uuid,
                'name' => $this->actor->name,
            ]),
        ];
    }
}
