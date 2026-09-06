<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessScheduleResource;
use App\Models\AccessSchedule;
use App\Models\Device;
use App\Models\Member;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Time-of-day / day-of-week windows (§9.7).
 *
 * A member holding no schedule is unrestricted. Attaching one restricts them
 * to the union of its windows — which means adding a schedule can only ever
 * narrow access, never widen it.
 */
class AccessScheduleController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AccessSchedule::class);

        return AccessScheduleResource::collection(
            AccessSchedule::query()->with('windows')->withCount('members')->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AccessSchedule::class);

        $validated = $this->validateSchedule($request, creating: true);

        $schedule = DB::transaction(function () use ($validated): AccessSchedule {
            $schedule = AccessSchedule::create([
                'name' => $validated['name'],
                'timezone' => $validated['timezone'] ?? config('access.schedules.default_timezone'),
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $this->syncWindows($schedule, $validated['windows']);

            return $schedule;
        });

        $this->audit->log('schedule.created', $schedule, after: $schedule->getAttributes());

        return (new AccessScheduleResource($schedule->load('windows')))->response()->setStatusCode(201);
    }

    public function update(Request $request, AccessSchedule $schedule): AccessScheduleResource
    {
        $this->authorize('update', $schedule);

        $before = $schedule->getAttributes();
        $validated = $this->validateSchedule($request, creating: false);

        DB::transaction(function () use ($schedule, $validated): void {
            $schedule->update(array_intersect_key($validated, array_flip(['name', 'timezone', 'is_active'])));

            if (isset($validated['windows'])) {
                $schedule->windows()->delete();
                $this->syncWindows($schedule, $validated['windows']);
            }
        });

        $this->audit->log('schedule.updated', $schedule, $before, $schedule->getAttributes());

        return new AccessScheduleResource($schedule->load('windows'));
    }

    public function destroy(AccessSchedule $schedule): JsonResponse
    {
        $this->authorize('delete', $schedule);

        $before = $schedule->getAttributes();
        $schedule->delete();

        $this->audit->log('schedule.deleted', $schedule, $before);

        return response()->json(['ok' => true]);
    }

    /**
     * PUT /members/{member}/schedules — replace a member's schedule
     * assignments.
     */
    public function assignToMember(Request $request, Member $member): JsonResponse
    {
        $this->authorize('update', $member);

        $validated = $request->validate([
            'assignments' => ['present', 'array'],
            'assignments.*.schedule_id' => ['required', 'uuid'],
            // Null scopes the schedule to every door in the tenant.
            'assignments.*.device_id' => ['nullable', 'uuid'],
        ]);

        $before = $member->schedules()->get()->pluck('uuid')->all();

        $pivot = [];

        foreach ($validated['assignments'] as $assignment) {
            $scheduleId = AccessSchedule::query()->where('uuid', $assignment['schedule_id'])->value('id');

            if ($scheduleId === null) {
                continue;
            }

            $deviceId = isset($assignment['device_id'])
                ? Device::query()->where('uuid', $assignment['device_id'])->value('id')
                : null;

            $pivot[] = ['access_schedule_id' => $scheduleId, 'device_id' => $deviceId];
        }

        DB::transaction(function () use ($member, $pivot): void {
            $member->schedules()->detach();

            foreach ($pivot as $row) {
                $member->schedules()->attach($row['access_schedule_id'], ['device_id' => $row['device_id']]);
            }
        });

        $this->audit->log('member.schedules_changed', $member, ['schedules' => $before], [
            'schedules' => $member->schedules()->get()->pluck('uuid')->all(),
        ]);

        return response()->json([
            'data' => AccessScheduleResource::collection($member->schedules()->with('windows')->get()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSchedule(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'is_active' => ['sometimes', 'boolean'],
            'windows' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:50'],
            // ISO-8601 weekday: 1 = Monday .. 7 = Sunday.
            'windows.*.weekday' => ['required', 'integer', 'min:1', 'max:7'],
            'windows.*.starts_at' => ['required', 'date_format:H:i'],
            'windows.*.ends_at' => ['required', 'date_format:H:i', 'after:windows.*.starts_at'],
        ]);
    }

    /**
     * @param  list<array{weekday: int, starts_at: string, ends_at: string}>  $windows
     */
    private function syncWindows(AccessSchedule $schedule, array $windows): void
    {
        foreach ($windows as $window) {
            $schedule->windows()->create([
                'weekday' => $window['weekday'],
                'starts_at' => $window['starts_at'].':00',
                'ends_at' => $window['ends_at'].':00',
            ]);
        }
    }
}
