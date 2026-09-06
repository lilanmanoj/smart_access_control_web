<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A run of consecutive health polls in the same state.
 *
 * A row per poll would be ~2,880 per device per day for no extra information;
 * the recorder extends the open run instead and only opens a new row when the
 * state changes or the hour rolls over.
 */
#[Fillable([
    'tenant_id', 'device_id', 'state', 'started_at', 'last_seen_at',
    'poll_count', 'firmware_version', 'uptime_ms', 'enrolled_count', 'metadata',
])]
class DeviceHealthReport extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
