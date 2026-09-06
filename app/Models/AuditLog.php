<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Dashboard mutations, with before/after state.
 *
 * Deliberately not merged with access_events: one answers "what did an
 * operator change", the other "what happened at the door".
 */
#[Fillable([
    'tenant_id', 'user_id', 'action', 'auditable_type', 'auditable_id',
    'before', 'after', 'ip', 'user_agent',
])]
class AuditLog extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /**
     * The one tenant-owned model that may have no tenant.
     *
     * A SuperAdmin's cross-tenant actions — signing in, switching tenants,
     * clearing the switch — belong to no single tenant, and those are exactly
     * the actions the audit trail most needs to record. Refusing to write them
     * would lose the entries that matter most.
     */
    public function tenantIdIsRequired(): bool
    {
        return false;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
