<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
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

    /**
     * The settings a tenant may override, each falling back to the platform
     * default in `config/access.php` when it is not set.
     */
    private const SETTING_KEYS = [
        'backup_codes.online_verification_only',
        'otp.require_enrollment',
        'members.require_approval',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Tenant::class);

        $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        $tenants = $this->tenants->crossTenant(
            fn () => Tenant::query()
                ->withCount(['devices', 'members', 'users'])
                ->when($search !== '', fn ($query) => $query->where(
                    fn ($inner) => $inner
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%')
                ))
                ->when($status !== null && $status !== '', fn ($query) => $query->where('status', $status))
                ->orderBy('name')
                ->paginate($request->integer('per_page', 25))
                ->withQueryString()
        );

        return TenantResource::collection($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Tenant::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:60', 'alpha_dash'],
            ...$this->settingRules(),
        ]);

        // Validated after derivation, so a slug made from the name collides
        // with a 422 on `slug` rather than with the unique index.
        $slug = Str::slug((string) ($validated['slug'] ?? '') ?: $validated['name']);

        Validator::make(['slug' => $slug], [
            'slug' => ['required', 'string', 'max:60', Rule::unique('tenants', 'slug')],
        ], [
            'slug.required' => 'Enter a slug: letters, numbers and dashes.',
            'slug.unique' => 'Another tenant already uses this slug.',
        ])->validate();

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'slug' => $slug,
            'status' => 'active',
            'settings' => $this->mergeSettings([], $validated['settings'] ?? []),
        ]);

        $this->audit->log('tenant.created', $tenant, after: $tenant->getAttributes());

        return (new TenantResource($tenant->loadCount(['devices', 'members', 'users'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /tenants/{tenant} — rename, activate or deactivate, and change
     * the tenant's setting overrides.
     *
     * The slug is fixed once created. Settings are merged, not replaced: a key
     * that is left out keeps its value, and `null` clears the override so the
     * platform default applies again.
     */
    public function update(Request $request, Tenant $tenant): TenantResource
    {
        $this->authorize('update', $tenant);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            ...$this->settingRules(),
        ]);

        // Deactivating a tenant signs out everyone in it, so an operator who
        // belongs to it would lock themselves out mid-request.
        if (($validated['status'] ?? null) === 'suspended'
            && (int) $request->user()->tenant_id === (int) $tenant->id) {
            throw new ApiException(
                'cannot_suspend_own_tenant',
                'You cannot deactivate the tenant your own account belongs to.',
                422
            );
        }

        $before = $tenant->getAttributes();

        $changes = Arr::only($validated, ['name', 'status']);

        if (array_key_exists('settings', $validated)) {
            $changes['settings'] = $this->mergeSettings($tenant->settings ?? [], $validated['settings'] ?? []);
        }

        $tenant->update($changes);

        $this->audit->log('tenant.updated', $tenant, $before, $tenant->getAttributes());

        return new TenantResource($tenant->loadCount(['devices', 'members', 'users']));
    }

    /** @return array<string, list<mixed>> */
    private function settingRules(): array
    {
        $rules = ['settings' => ['sometimes', 'nullable', 'array:backup_codes,otp,members']];

        foreach (self::SETTING_KEYS as $key) {
            [$group] = explode('.', $key);
            $rules["settings.{$group}"] = ['sometimes', 'nullable', 'array'];
            $rules["settings.{$key}"] = ['sometimes', 'nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * Apply the submitted overrides on top of the stored ones. Only the known
     * keys are read, so nothing else a client sends reaches the column.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private function mergeSettings(array $current, array $submitted): array
    {
        foreach (self::SETTING_KEYS as $key) {
            if (! Arr::has($submitted, $key)) {
                continue;
            }

            $value = Arr::get($submitted, $key);

            if ($value === null) {
                Arr::forget($current, $key);
            } else {
                Arr::set($current, $key, (bool) $value);
            }
        }

        // An emptied group is dropped rather than stored as `[]`.
        return array_filter($current, fn ($group): bool => ! is_array($group) || $group !== []);
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
