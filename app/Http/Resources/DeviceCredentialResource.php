<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never carries the secret. It exists in exactly one response — the one that
 * created it — and nowhere else.
 *
 * @mixin \App\Models\DeviceCredential
 */
class DeviceCredentialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'api_key' => $this->api_key,
            'secret_last4' => $this->secret_last4,
            'label' => $this->label,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_used_ip' => $this->last_used_ip,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'is_usable' => $this->isUsable(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
