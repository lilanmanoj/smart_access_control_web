<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FingerprintTemplateStatus;
use App\Enums\TemplateSyncStatus;
use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\FingerprintTemplate;
use App\Models\TemplateSyncReport;
use App\Support\TemplateSet;
use App\Support\TemplateSetEntry;
use Illuminate\Support\Facades\DB;

/**
 * Template sync (device API 1.2.0): serving the set a panel should hold, and
 * taking its report of what it actually did.
 *
 * This is the only code that ever puts template bytes into a response.
 */
class TemplateSyncService
{
    public function __construct(
        private readonly TemplateSlotService $slots,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * One page of the panel's template set.
     *
     * The set and its revision are recomputed on every page, inside one
     * transaction with the device locked, so a page is always a slice of the
     * set its revision names. If the set changes between two pages the panel
     * sees a different revision and aborts without deleting anything — which
     * is the only safe outcome.
     *
     * @param  array{
     *     capacity: int,
     *     template_format: string,
     *     offset: int,
     *     limit: int,
     *     since_revision: ?string,
     *     enrolled_count: ?int,
     *     firmware_version: ?string,
     * }  $request
     * @return array<string, mixed>
     */
    public function page(Device $device, array $request): array
    {
        return DB::transaction(function () use ($device, $request): array {
            $this->recordPanelState($device, $request);

            $set = $this->slots->desiredSet($device, $request['capacity'], $request['template_format']);
            $unchanged = $request['since_revision'] !== null
                && hash_equals($set->revision, $request['since_revision']);

            $this->recordServed($device, $set, firstPage: ! $unchanged && $request['offset'] === 0);

            return [
                'revision' => $set->revision,
                'unchanged' => $unchanged,
                'total' => $set->total(),
                'offset' => $request['offset'],
                'limit' => $request['limit'],
                'truncated' => $set->truncated,
                'templates' => $unchanged
                    ? []
                    : $this->render($device, $set->page($request['offset'], $request['limit'])),
                'server_time' => now()->toIso8601ZuluString(),
            ];
        });
    }

    /**
     * Store a sync report and, if it ran to the end, act on its
     * `slots_present`: free confirmed-empty releasing slots, confirm the
     * active ones the panel holds, and record drift.
     *
     * @param  array<string, mixed>  $report  The validated body.
     */
    public function accept(Device $device, array $report): TemplateSyncReport
    {
        return DB::transaction(function () use ($device, $report): TemplateSyncReport {
            $status = TemplateSyncStatus::from($report['status']);
            $drift = null;

            if ($status->ranToEnd() && isset($report['slots_present'])) {
                $present = array_values(array_unique(array_map('intval', $report['slots_present'])));
                $drift = $this->slots->applyReport($device, $present);
            }

            return TemplateSyncReport::create([
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'revision' => $report['revision'] ?? null,
                'status' => $status,
                'abort_reason' => $status === TemplateSyncStatus::Aborted ? ($report['abort_reason'] ?? null) : null,
                'failed' => (int) $report['failed'],
                'report' => $report,
                'drift' => $drift === null ? null : [
                    'missing_slots' => $drift['missing_slots'],
                    'unknown_slots' => $drift['unknown_slots'],
                ],
                'received_at' => now(),
            ]);
        });
    }

    /**
     * The panel tells us its sensor's size and fill on every sync; keep them.
     *
     * @param  array<string, mixed>  $request
     */
    private function recordPanelState(Device $device, array $request): void
    {
        $device->forceFill(array_filter([
            'template_capacity' => $request['capacity'],
            'enrolled_count' => $request['enrolled_count'],
            'firmware_version' => $request['firmware_version'],
            'last_seen_at' => now(),
        ], fn ($value): bool => $value !== null))->save();
    }

    /**
     * What the dashboard shows about the last set served, and one audit entry
     * per sync that served templates — on its first page, not on every page.
     */
    private function recordServed(Device $device, TemplateSet $set, bool $firstPage): void
    {
        $device->forceFill([
            'template_set_revision' => $set->revision,
            'template_set_total' => $set->total(),
            'template_set_truncated' => $set->truncated,
            'template_set_deferred' => $set->deferred,
            ...($firstPage ? ['template_set_served_at' => now()] : []),
        ])->save();

        if ($firstPage) {
            $this->audit->log('fingerprint_template.sync_served', $device, null, [
                'revision' => $set->revision,
                'template_format' => $set->format,
                'capacity' => $set->capacity,
                'total' => $set->total(),
                'with_template' => $set->withTemplates(),
                'truncated' => $set->truncated,
                'deferred' => $set->deferred,
            ]);
        }
    }

    /**
     * The page's entries, with each template exactly as it was uploaded.
     * Only these templates are decrypted, and only for this response.
     *
     * @param  list<TemplateSetEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function render(Device $device, array $entries): array
    {
        $ids = array_values(array_filter(array_map(fn (TemplateSetEntry $e): ?int => $e->templateId, $entries)));

        $stored = $ids === [] ? collect() : FingerprintTemplate::query()
            ->where('tenant_id', $device->tenant_id)
            ->where('status', FingerprintTemplateStatus::Active->value)
            ->whereKey($ids)
            ->get(['id', 'format', 'size', 'sha256', 'data_encrypted'])
            ->keyBy('id');

        return array_map(function (TemplateSetEntry $entry) use ($stored): array {
            if ($entry->templateId === null) {
                return ['slot' => $entry->slot, 'member_id' => $entry->memberUuid, 'template' => null];
            }

            $template = $stored->get($entry->templateId);
            $bytes = $template?->data_encrypted;

            if (! is_string($bytes)) {
                // Erased between computing the set and reading its bytes.
                // Never substitute `null` — that would tell the panel to keep
                // whatever it has — so fail, and the panel retries.
                throw new ApiException('template_set_changed', 'The template set changed; retry the sync.', 503);
            }

            return [
                'slot' => $entry->slot,
                'member_id' => $entry->memberUuid,
                'template' => [
                    'format' => $template->format,
                    'encoding' => 'base64',
                    'size' => (int) $template->size,
                    'sha256' => $template->sha256,
                    'data' => base64_encode($bytes),
                ],
            ];
        }, $entries);
    }
}
