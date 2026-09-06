<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata only. The codes themselves are revealed exactly once, at
 * generation, in the response to the call that created them.
 *
 * @mixin \App\Models\BackupCodeSet
 */
class BackupCodeSetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'issued_at' => $this->issued_at->toIso8601String(),
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'fetched_at' => $this->fetched_at?->toIso8601String(),
            'issued_by' => $this->whenLoaded('issuer', fn (): ?string => $this->issuer?->name),
            'device' => $this->whenLoaded('device', fn (): array => [
                'id' => $this->device->uuid,
                'device_id' => $this->device->device_id,
                'name' => $this->device->name,
            ]),
            'codes' => $this->whenLoaded('codes', fn (): array => $this->codes->map(fn ($code): array => [
                'id' => $code->uuid,
                'last4' => $code->last4,
                'used_at' => $code->used_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
