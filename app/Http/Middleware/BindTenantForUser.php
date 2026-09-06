<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated operator's tenant for the rest of the request.
 *
 * This is the dashboard's half of the isolation model — the device API's half
 * is {@see AuthenticateDevice}. Between them, every authenticated request has a
 * tenant bound before a single query runs, which is what lets
 * {@see \App\Models\Scopes\TenantScope} be trusted.
 *
 * A SuperAdmin has no tenant of their own. They see the whole fleet until they
 * explicitly switch into one, and that switch is written to the audit log.
 */
class BindTenantForUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $context = app(TenantContext::class);

        if (! $user->isSuperAdmin()) {
            if ($user->tenant === null) {
                abort(403, 'This account is not attached to a tenant.');
            }

            if (! $user->tenant->isActive()) {
                abort(403, 'This account is not active.');
            }

            $context->set($user->tenant);

            return $next($request);
        }

        // SuperAdmin: honour a selected tenant if one is held in the session.
        $selected = $request->session()->get('impersonated_tenant_id');

        if ($selected !== null) {
            $tenant = Tenant::query()->find($selected);

            if ($tenant !== null) {
                $context->set($tenant);
            }
        }

        return $next($request);
    }
}
