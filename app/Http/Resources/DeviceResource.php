<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Device */
class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'device_id' => $this->device_id,
            'name' => $this->name,
            'location' => $this->location,
            'status' => $this->status->value,
            // `status` is the administrative state; `is_online` is whether the
            // door is currently answering. A suspended device can still be
            // online, and an active one can be dark.
            'is_online' => $this->is_online,
            'firmware_version' => $this->firmware_version,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_health_at' => $this->last_health_at?->toIso8601String(),
            'template_capacity' => $this->template_capacity,
            'enrolled_count' => $this->enrolled_count,
            'metadata' => $this->metadata,
            'active_backup_code_set' => new BackupCodeSetResource($this->whenLoaded('activeBackupCodeSet')),
            'pending_commands' => DeviceCommandResource::collection($this->whenLoaded('commands')),
            'credentials' => DeviceCredentialResource::collection($this->whenLoaded('credentials')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
