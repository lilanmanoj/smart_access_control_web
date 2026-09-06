<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekday window. `weekday` is ISO-8601: 1 = Monday .. 7 = Sunday.
 */
#[Fillable(['access_schedule_id', 'weekday', 'starts_at', 'ends_at'])]
class AccessScheduleWindow extends Model
{
    use HasFactory;

    /** @return BelongsTo<AccessSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(AccessSchedule::class, 'access_schedule_id');
    }

    /**
     * MySQL TIME columns come back as plain strings; normalise them so window
     * comparisons are always H:i:s against H:i:s.
     */
    public function startsAtString(): string
    {
        return $this->normaliseTime($this->starts_at);
    }

    public function endsAtString(): string
    {
        return $this->normaliseTime($this->ends_at);
    }

    private function normaliseTime(string $value): string
    {
        return substr($value, 0, 5) === $value ? $value.':00' : $value;
    }
}
