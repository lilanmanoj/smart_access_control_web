<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's subscription to access.denied, device.offline and
 * backup_codes.rotated (§9.8).
 */
#[Fillable(['tenant_id', 'url', 'description', 'secret', 'events', 'is_active'])]
#[Hidden(['secret'])]
class WebhookEndpoint extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;

    /** Every event a tenant may subscribe to. */
    public const AVAILABLE_EVENTS = [
        'access.denied',
        'access.granted',
        'device.offline',
        'device.online',
        'backup_codes.rotated',
        'backup_codes.brute_force',
        'enrollment.orphaned',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'secret' => 'encrypted',
            'last_delivered_at' => 'datetime',
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events ?? [], true);
    }
}
