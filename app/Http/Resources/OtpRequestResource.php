<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OtpRequest */
class OtpRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            // Masked: an operator needs to recognise the number, not read it
            // out of an audit screen.
            'phone' => PhoneNumber::tryParse($this->phone)?->masked() ?? $this->phone,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'expires_at' => $this->expires_at->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'delivery' => $this->delivery,
            'device' => $this->whenLoaded('device', fn (): array => [
                'id' => $this->device->uuid,
                'name' => $this->device->name,
            ]),
            'member' => $this->whenLoaded('member', fn (): ?array => $this->member === null ? null : [
                'id' => $this->member->uuid,
                'full_name' => $this->member->full_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
