<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\TenantContextRequired;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies tenant isolation to a model: reads are scoped to the bound tenant,
 * and writes inherit its id automatically so no caller has to remember.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            // An explicit tenant_id always wins — that is how seeders,
            // factories and queued jobs write on behalf of a tenant they name
            // themselves.
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $context = app(TenantContext::class);

            if ($context->has()) {
                $model->setAttribute('tenant_id', $context->id());

                return;
            }

            // No tenant bound and none supplied. In practice this is a
            // SuperAdmin creating something from the cross-tenant fleet view,
            // where there is genuinely no answer to "whose is this?".
            //
            // Refusing here rather than letting the insert reach MySQL turns an
            // opaque `Field 'tenant_id' doesn't have a default value` into
            // something the operator can act on — and it catches every
            // tenant-owned model at once, rather than each controller having to
            // remember.
            if ($model->tenantIdIsRequired()) {
                throw TenantContextRequired::forModel(static::class);
            }
        });
    }

    /**
     * Whether this model can exist without a tenant.
     *
     * Almost nothing can. {@see \App\Models\AuditLog} is the exception: it has
     * to record cross-tenant SuperAdmin actions — including the tenant switch
     * itself — which by definition belong to no single tenant.
     */
    public function tenantIdIsRequired(): bool
    {
        return true;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
