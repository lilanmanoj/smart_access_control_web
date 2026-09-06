<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use RuntimeException;

/**
 * The active tenant for the current request, job or command.
 *
 * Bound once at the edge — by device authentication, or by the authenticated
 * operator's own tenant — and read by {@see \App\Models\Scopes\TenantScope} on
 * every tenant-owned query thereafter. Nothing in the application reads
 * tenant_id from user input.
 *
 * Registered as a singleton, so "the current tenant" is unambiguous.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    /**
     * Set while a SuperAdmin is deliberately querying across every tenant.
     * Kept separate from "no tenant bound" so the distinction is auditable.
     */
    private bool $crossTenant = false;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->crossTenant = false;
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->crossTenant = false;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * The tenant id, or a failure. Use where a missing tenant is a bug rather
     * than a legitimate cross-tenant view.
     */
    public function idOrFail(): int
    {
        return $this->id() ?? throw new RuntimeException(
            'No tenant is bound to the current context.'
        );
    }

    public function isCrossTenant(): bool
    {
        return $this->crossTenant;
    }

    /**
     * Run a callback with the tenant scope lifted.
     *
     * Reserved for SuperAdmin fleet views and maintenance commands. Everything
     * else must go through the scoped path.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function crossTenant(callable $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousFlag = $this->crossTenant;

        $this->tenant = null;
        $this->crossTenant = true;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->crossTenant = $previousFlag;
        }
    }

    /**
     * Run a callback bound to a specific tenant, restoring the previous
     * binding afterwards. Used by queued jobs, which start with no context.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function forTenant(Tenant $tenant, callable $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousFlag = $this->crossTenant;

        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->crossTenant = $previousFlag;
        }
    }
}
