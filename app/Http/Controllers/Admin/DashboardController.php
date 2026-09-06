<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AccessResult;
use App\Enums\DenialReason;
use App\Enums\DeviceStatus;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccessEventResource;
use App\Models\AccessEvent;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Enrollment;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /dashboard/summary — the landing page.
 *
 * Every query here is bounded by a date range. An operator opening the
 * dashboard must not trigger a scan of the entire event history, which on a
 * busy site is the one table that grows without limit.
 */
class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AccessEvent::class);

        $days = min(90, max(7, $request->integer('days', 14)));
        $since = CarbonImmutable::now()->subDays($days)->startOfDay();
        $todayStart = CarbonImmutable::now()->startOfDay();

        return response()->json([
            'devices' => $this->deviceCounts(),
            'members' => $this->memberCounts(),
            'today' => $this->windowCounts($todayStart),
            'trend' => $this->trend($since, $days),
            'top_denial_reasons' => $this->topDenialReasons($since),
            'attention' => $this->needsAttention(),
            'recent_events' => AccessEventResource::collection(
                AccessEvent::query()
                    ->with(['device', 'member'])
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
            ),
        ]);
    }

    /** @return array<string, int> */
    private function deviceCounts(): array
    {
        $rows = Device::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(is_online = 1) AS online')
            ->selectRaw('SUM(status = ?) AS active', [DeviceStatus::Active->value])
            ->selectRaw('SUM(status = ?) AS suspended', [DeviceStatus::Suspended->value])
            ->first();

        $total = (int) ($rows->total ?? 0);
        $online = (int) ($rows->online ?? 0);

        return [
            'total' => $total,
            'online' => $online,
            'offline' => $total - $online,
            'active' => (int) ($rows->active ?? 0),
            'suspended' => (int) ($rows->suspended ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function memberCounts(): array
    {
        return [
            'total' => Member::query()->count(),
            'active' => Member::query()->active()->count(),
            'admins' => Member::query()->where('is_admin', true)->count(),
        ];
    }

    /** @return array<string, int|float> */
    private function windowCounts(CarbonImmutable $since): array
    {
        $row = AccessEvent::query()
            ->where('occurred_at', '>=', $since)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(result = ?) AS granted', [AccessResult::Granted->value])
            ->selectRaw('SUM(result = ?) AS denied', [AccessResult::Denied->value])
            ->first();

        $total = (int) ($row->total ?? 0);
        $denied = (int) ($row->denied ?? 0);

        return [
            'total' => $total,
            'granted' => (int) ($row->granted ?? 0),
            'denied' => $denied,
            // The number an operator actually watches during an incident.
            'denied_rate' => $total === 0 ? 0.0 : round($denied / $total * 100, 1),
        ];
    }

    /**
     * Daily granted/denied counts, with empty days filled in so the chart has
     * no gaps to misread as missing data.
     *
     * @return list<array{date: string, granted: int, denied: int}>
     */
    private function trend(CarbonImmutable $since, int $days): array
    {
        $rows = AccessEvent::query()
            ->where('occurred_at', '>=', $since)
            ->selectRaw('DATE(occurred_at) AS day')
            ->selectRaw('SUM(result = ?) AS granted', [AccessResult::Granted->value])
            ->selectRaw('SUM(result = ?) AS denied', [AccessResult::Denied->value])
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row): string => (string) $row->day);

        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = CarbonImmutable::now()->subDays($i)->toDateString();
            $row = $rows->get($date);

            $series[] = [
                'date' => $date,
                'granted' => (int) ($row->granted ?? 0),
                'denied' => (int) ($row->denied ?? 0),
            ];
        }

        return $series;
    }

    /** @return list<array{reason: string, label: string, count: int}> */
    private function topDenialReasons(CarbonImmutable $since): array
    {
        return AccessEvent::query()
            ->denied()
            ->where('occurred_at', '>=', $since)
            ->selectRaw('reason, COUNT(*) AS total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'reason' => $row->reason,
                // The label travels with the code so the dashboard never has to
                // keep its own copy of this vocabulary — which is how "otp"
                // ends up rendered as "Otp".
                'label' => DenialReason::tryFrom($row->reason)?->label() ?? $row->reason,
                'count' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * The things that need a person: doors that have gone dark, slots the
     * device and the backend disagree about, and revocations that have not
     * reached the sensor yet.
     *
     * @return array<string, int>
     */
    private function needsAttention(): array
    {
        return [
            'devices_offline' => Device::query()
                ->where('status', DeviceStatus::Active->value)
                ->where('is_online', false)
                ->count(),
            'orphaned_enrollments' => Enrollment::query()
                ->where('status', EnrollmentStatus::Orphaned->value)
                ->count(),
            'pending_commands' => DeviceCommand::query()
                ->whereIn('status', ['pending', 'sent'])
                ->count(),
        ];
    }
}
