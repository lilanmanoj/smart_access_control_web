<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TemplateSyncReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A panel's report of one template sync, for the dashboard. Counts, errors
 * and drift only — a report never carries template bytes.
 *
 * @mixin TemplateSyncReport
 */
class TemplateSyncReportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $report = $this->report ?? [];

        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'abort_reason' => $this->abort_reason,
            'revision' => $this->revision,
            'received_at' => $this->received_at?->toIso8601String(),
            'written' => $report['written'] ?? null,
            'unchanged' => $report['unchanged'] ?? null,
            'deleted' => $report['deleted'] ?? null,
            'retained' => $report['retained'] ?? null,
            'missing' => $report['missing'] ?? null,
            'failed' => $this->failed,
            'duration_ms' => $report['duration_ms'] ?? null,
            'errors' => $report['errors'] ?? [],
            // Null when the report could not be compared: aborted, or no
            // `slots_present`.
            'drift' => $this->drift,
        ];
    }
}
