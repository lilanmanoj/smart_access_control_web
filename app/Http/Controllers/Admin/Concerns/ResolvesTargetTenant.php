<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use App\Exceptions\ApiException;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Lets an operator who manages tenants say which one a new record belongs to.
 *
 * Every ordinary operator has exactly one tenant, bound at the edge of the
 * request, and never sees this: sending `tenant_id` gets them a 403. It exists
 * for the SuperAdmin working from the cross-tenant fleet view, where there is
 * otherwise no answer to "whose is this?".
 *
 * The gate is the `tenant.manage` **permission**, not the `super_admin` role
 * string. A role is only a bundle of permissions here, and code that branches
 * on a role name stops being true the moment someone defines a new one.
 */
trait ResolvesTargetTenant
{
    /**
     * The tenant a new record should be created in.
     *
     * Null means "use whatever the request already has bound", which is the
     * normal path — and if nothing is bound, {@see \App\Models\Concerns\BelongsToTenant}
     * refuses the write with an actionable error rather than reaching MySQL.
     */
    protected function resolveTargetTenant(Request $request): ?Tenant
    {
        $requested = $request->input('tenant_id');

        if ($requested === null || $requested === '') {
            return null;
        }

        if ($request->user()?->hasPermissionTo('tenant.manage') !== true) {
            throw ApiException::forbidden(
                'tenant_selection_forbidden',
                'You may only create records in your own tenant.'
            );
        }

        // Looked up across tenants deliberately: the whole point is to reach
        // one the caller is not currently bound to. The permission check above
        // is what makes that safe.
        $tenant = app(TenantContext::class)->crossTenant(
            fn (): ?Tenant => Tenant::query()->where('uuid', $requested)->first()
        );

        if ($tenant === null) {
            throw new ApiException('unknown_tenant', 'That tenant does not exist.', 422);
        }

        if (! $tenant->isActive()) {
            throw new ApiException(
                'tenant_suspended',
                'That tenant is suspended, so nothing can be added to it.',
                422
            );
        }

        return $tenant;
    }

    /**
     * Run a create inside the chosen tenant, so the uniqueness rules, the
     * global scope and the audit entry all agree about which tenant this is.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withinTargetTenant(?Tenant $tenant, callable $callback): mixed
    {
        if ($tenant === null) {
            return $callback();
        }

        return app(TenantContext::class)->forTenant($tenant, $callback);
    }
}
