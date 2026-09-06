<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the bound tenant.
 *
 * This is the mechanism the whole isolation model rests on. It is applied
 * automatically by {@see \App\Models\Concerns\BelongsToTenant}; removing it
 * requires the explicit, auditable
 * {@see \App\Support\TenantContext::crossTenant()} path.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->has()) {
            // No tenant bound: either a SuperAdmin fleet view or a console
            // command. Both are deliberate, and both are reached through
            // TenantContext::crossTenant(), never by accident — device and
            // operator requests always bind a tenant at the edge.
            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $context->id()
        );
    }
}
