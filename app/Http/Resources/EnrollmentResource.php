<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Enrollment */
class EnrollmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'fingerprint_slot' => $this->fingerprint_slot,
            'enrolled_at' => $this->enrolled_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'last_reconciled_at' => $this->last_reconciled_at?->toIso8601String(),
            'device' => new DeviceResource($this->whenLoaded('device')),
            'member' => new MemberResource($this->whenLoaded('member')),
        ];
    }
}
