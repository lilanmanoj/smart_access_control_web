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
            // Device API 1.4.0: the MQTT doorbell as the panel last reported
            // it on /health. `connected` is null until a panel that knows
            // about the doorbell reports at all.
            'realtime' => [
                'connected' => $this->mqtt_connected,
                'reconnects' => $this->mqtt_reconnects,
                'reported_at' => $this->mqtt_reported_at?->toIso8601String(),
            ],
            'firmware_version' => $this->firmware_version,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_health_at' => $this->last_health_at?->toIso8601String(),
            'template_capacity' => $this->template_capacity,
            'enrolled_count' => $this->enrolled_count,
            'metadata' => $this->metadata,
            // Only present for a cross-tenant view; an operator inside a single
            // tenant already knows whose devices these are.
            'tenant' => $this->whenLoaded('tenant', fn (): array => [
                'id' => $this->tenant->uuid,
                'name' => $this->tenant->name,
            ]),
            'active_backup_code_set' => new BackupCodeSetResource($this->whenLoaded('activeBackupCodeSet')),
            'pending_commands' => DeviceCommandResource::collection($this->whenLoaded('commands')),
            'credentials' => DeviceCredentialResource::collection($this->whenLoaded('credentials')),
            // Template sync (device API 1.2.0): the last set served to this
            // panel, and what the panel said it did with it.
            'template_sync' => [
                'revision' => $this->template_set_revision,
                'total' => $this->template_set_total,
                // More members qualified than the sensor holds; some were cut.
                'truncated' => (bool) $this->template_set_truncated,
                // Waiting for a released slot to be confirmed empty.
                'deferred' => (int) $this->template_set_deferred,
                'served_at' => $this->template_set_served_at?->toIso8601String(),
                'active_slots' => $this->whenCounted('activeTemplateSlots'),
                'releasing_slots' => $this->whenCounted('releasingTemplateSlots'),
                'last_report' => $this->whenLoaded(
                    'latestTemplateSyncReport',
                    fn () => $this->latestTemplateSyncReport === null
                        ? null
                        : new TemplateSyncReportResource($this->latestTemplateSyncReport),
                ),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
