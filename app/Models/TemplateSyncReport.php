<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TemplateSyncStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A panel's account of one template sync (`POST /fingerprint-template-syncs`).
 */
#[Fillable([
    'tenant_id', 'device_id', 'revision', 'status', 'abort_reason',
    'failed', 'report', 'drift', 'received_at',
])]
class TemplateSyncReport extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected function casts(): array
    {
        return [
            'status' => TemplateSyncStatus::class,
            'failed' => 'integer',
            'report' => 'array',
            'drift' => 'array',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
