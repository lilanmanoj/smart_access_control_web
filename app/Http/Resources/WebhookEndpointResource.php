<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WebhookEndpoint */
class WebhookEndpointResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'url' => $this->url,
            'description' => $this->description,
            'events' => $this->events,
            'is_active' => $this->is_active,
            'last_delivered_at' => $this->last_delivered_at?->toIso8601String(),
            'consecutive_failures' => $this->consecutive_failures,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
