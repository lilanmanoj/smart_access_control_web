<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AccessSchedule */
class AccessScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'windows' => $this->whenLoaded('windows', fn (): array => $this->windows->map(fn ($window): array => [
                'id' => $window->id,
                'weekday' => $window->weekday,
                'starts_at' => $window->startsAtString(),
                'ends_at' => $window->endsAtString(),
            ])->all()),
            'members_count' => $this->whenCounted('members'),
            'device_id' => $this->whenPivotLoaded('access_schedule_member', fn () => $this->pivot->device_id),
        ];
    }
}
