<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AccessMethod;
use App\Enums\AccessResult;
use App\Enums\DenialReason;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Models\Device;
use App\Models\Member;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The access log.
 *
 * Cursor-paginated rather than offset-paginated: a busy door produces a lot of
 * rows, and `LIMIT 25 OFFSET 200000` makes the database walk all 200,000. A
 * cursor rides the (tenant_id, occurred_at) index instead and stays flat.
 */
class AccessEventController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AccessEvent::class);

        $events = $this->filtered($request)
            ->with(['device', 'member', 'actor'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate($request->integer('per_page', 50))
            ->withQueryString();

        return AccessEventResource::collection($events);
    }

    /**
     * GET /access-events/export?format=csv|xlsx
     *
     * Streamed row by row: an export is allowed to be large, but it is not
     * allowed to hold a year of door history in memory while it renders.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', AccessEvent::class);

        $format = $request->string('format', 'csv')->toString();
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422, 'Unsupported export format.');

        $filename = 'access-events-'.now()->format('Ymd-His').'.'.$format;
        $query = $this->filtered($request)->with(['device', 'member', 'actor']);

        $this->audit->log('event.exported', null, after: [
            'format' => $format,
            'filters' => $request->query(),
        ]);

        return response()->streamDownload(function () use ($query, $format): void {
            $writer = $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
            $writer->openToFile('php://output');

            $writer->addRow(Row::fromValues([
                'Occurred at (UTC)', 'Device', 'Device ID', 'Location', 'Member',
                'Method', 'Result', 'Reason', 'Slot', 'Confidence', 'Operator',
            ]));

            $query->orderBy('occurred_at')->chunkById(500, function ($chunk) use ($writer): void {
                foreach ($chunk as $event) {
                    $writer->addRow(Row::fromValues([
                        $event->occurred_at->toDateTimeString(),
                        $event->device?->name,
                        $event->device?->device_id,
                        $event->device?->location,
                        $event->member?->full_name,
                        $event->method->label(),
                        $event->result->value,
                        DenialReason::tryFrom($event->reason)?->label() ?? $event->reason,
                        $event->fingerprint_slot,
                        $event->confidence,
                        $event->actor?->name,
                    ]));
                }
            });

            $writer->close();
        }, $filename, [
            'Content-Type' => $format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv',
        ]);
    }

    /**
     * @return Builder<AccessEvent>
     */
    private function filtered(Request $request): Builder
    {
        $request->validate([
            'device_id' => ['sometimes', 'uuid'],
            'member_id' => ['sometimes', 'uuid'],
            'method' => ['sometimes', Rule::in(AccessMethod::values())],
            'result' => ['sometimes', Rule::in(AccessResult::values())],
            'reason' => ['sometimes', Rule::in(DenialReason::values())],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        return AccessEvent::query()
            ->when($request->filled('device_id'), function (Builder $q) use ($request): void {
                $q->where('device_id', Device::query()->where('uuid', $request->string('device_id'))->value('id') ?? 0);
            })
            ->when($request->filled('member_id'), function (Builder $q) use ($request): void {
                $q->where('member_id', Member::query()->where('uuid', $request->string('member_id'))->value('id') ?? 0);
            })
            ->when($request->filled('method'), fn (Builder $q) => $q->where('method', $request->string('method')))
            ->when($request->filled('result'), fn (Builder $q) => $q->where('result', $request->string('result')))
            ->when($request->filled('reason'), fn (Builder $q) => $q->where('reason', $request->string('reason')))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('occurred_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('occurred_at', '<=', $request->date('to')));
    }
}
