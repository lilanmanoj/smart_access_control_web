<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DeviceCommand */
class DeviceCommandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'payload' => $this->payload,
            'result' => $this->result,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'acked_at' => $this->acked_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'issued_by' => $this->whenLoaded('issuer', fn (): ?string => $this->issuer?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
