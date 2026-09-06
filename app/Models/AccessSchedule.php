<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of weekly time windows (§9.7).
 *
 * A member holding no schedule is unrestricted; holding one or more restricts
 * them to the union of those windows.
 */
#[Fillable(['tenant_id', 'name', 'timezone', 'is_active'])]
class AccessSchedule extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<AccessScheduleWindow, $this> */
    public function windows(): HasMany
    {
        return $this->hasMany(AccessScheduleWindow::class);
    }

    /** @return BelongsToMany<Member, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'access_schedule_member')
            ->withPivot('device_id')
            ->withTimestamps();
    }

    /**
     * Whether the given instant falls inside any of this schedule's windows,
     * evaluated in the schedule's own timezone — a door in Colombo should not
     * open on UTC office hours.
     */
    public function admits(CarbonInterface $at): bool
    {
        $local = $at->copy()->setTimezone($this->timezone);
        $weekday = (int) $local->isoWeekday();
        $time = $local->format('H:i:s');

        foreach ($this->windows as $window) {
            if ($window->weekday !== $weekday) {
                continue;
            }

            if ($time >= $window->startsAtString() && $time <= $window->endsAtString()) {
                return true;
            }
        }

        return false;
    }
}
