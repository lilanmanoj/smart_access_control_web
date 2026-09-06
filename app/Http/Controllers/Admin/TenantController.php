<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Tenant management, and the SuperAdmin tenant switch.
 *
 * The switch is the one mechanism in the system that deliberately crosses the
 * isolation boundary, so it is written to the audit log every time.
 */
class TenantController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenants,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Tenant::class);

        $tenants = $this->tenants->crossTenant(
            fn () => Tenant::query()
                ->withCount(['devices', 'members', 'users'])
                ->orderBy('name')
                ->paginate($request->integer('per_page', 25))
        );

        return TenantResource::collection($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Tenant::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:60', 'alpha_dash', Rule::unique('tenants', 'slug')],
            'settings' => ['nullable', 'array'],
        ]);

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'status' => 'active',
            'settings' => $validated['settings'] ?? [],
        ]);

        $this->audit->log('tenant.created', $tenant, after: $tenant->getAttributes());

        return (new TenantResource($tenant))->response()->setStatusCode(201);
    }

    public function update(Request $request, Tenant $tenant): TenantResource
    {
        $this->authorize('update', $tenant);

        $before = $tenant->getAttributes();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'settings' => ['sometimes', 'array'],
            // The two deployment-posture switches the requirements leave to
            // the tenant rather than to the product.
            'settings.backup_codes.online_verification_only' => ['sometimes', 'boolean'],
            'settings.otp.require_enrollment' => ['sometimes', 'boolean'],
        ]);

        $tenant->update($validated);

        $this->audit->log('tenant.updated', $tenant, $before, $tenant->getAttributes());

        return new TenantResource($tenant);
    }

    /**
     * POST /tenants/{tenant}/switch — bind a SuperAdmin to one tenant.
     */
    public function switchTo(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('switchTo', $tenant);

        $request->session()->put('impersonated_tenant_id', $tenant->id);

        $this->audit->log('tenant.switched', $tenant, after: [
            'tenant' => $tenant->slug,
        ]);

        return response()->json(['tenant' => new TenantResource($tenant)]);
    }

    /** DELETE /tenant-switch — return to the cross-tenant fleet view. */
    public function clearSwitch(Request $request): JsonResponse
    {
        $request->session()->forget('impersonated_tenant_id');

        $this->audit->log('tenant.switch_cleared');

        return response()->json(['ok' => true]);
    }
}
