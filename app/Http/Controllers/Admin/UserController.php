<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Admin\Concerns\ResolvesTargetTenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use ResolvesTargetTenant;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenants,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with(['roles', 'tenant'])
            // Users are not covered by the tenant global scope — a SuperAdmin
            // has no tenant — so the scoping is explicit here.
            ->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->where('tenant_id', $request->user()->tenant_id))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return UserResource::collection($users);
    }

    /**
     * Invite an operator. No password is set here: the invitation carries a
     * reset link, so a password never travels through this API.
     *
     * The invitee joins the tenant the request is bound to: a tenant admin's
     * own, or the one a SuperAdmin has switched into. An operator holding
     * `tenant.manage` may name another with `tenant_id`, and must when no
     * tenant is bound. A SuperAdmin invitee has no tenant at all.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('invite', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            // Only honoured for an operator holding `tenant.manage`; see
            // ResolvesTargetTenant.
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $this->assertAssignableRoles($request, $validated['roles']);

        $tenant = $this->tenantFor(
            $validated['roles'],
            $this->resolveTargetTenant($request) ?? $this->tenants->tenant(),
            explicit: $request->filled('tenant_id'),
        );

        $user = DB::transaction(function () use ($validated, $tenant): User {
            $user = User::create([
                'tenant_id' => $tenant?->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                // Replaced by the invitee via the reset link; never usable as-is.
                'password' => Str::random(64),
                'status' => 'invited',
            ]);

            $user->syncRoles($validated['roles']);

            return $user;
        });

        $this->sendInvitation($user, $request->user());

        $this->audit->log('user.invited', $user, after: [
            'email' => $user->email,
            'roles' => $validated['roles'],
            'tenant' => $tenant?->slug,
        ]);

        return (new UserResource($user->load(['roles', 'tenant'])))->response()->setStatusCode(201);
    }

    /**
     * POST /users/{user}/invitations — send a fresh invitation link.
     *
     * For an invitee whose link expired or went astray. Issuing a new token
     * replaces the old one, so only the newest link works.
     */
    public function resendInvitation(Request $request, User $user): JsonResponse
    {
        $this->authorize('invite', User::class);
        $this->authorize('update', $user);

        if ($user->status !== 'invited') {
            throw new ApiException(
                'not_invited',
                'This account has already been set up, so there is no invitation to resend.',
                422
            );
        }

        $this->sendInvitation($user, $request->user());

        $this->audit->log('user.invitation_resent', $user, after: ['email' => $user->email]);

        return response()->json(['ok' => true], 202);
    }

    /**
     * PATCH /users/{user} — edit an operator.
     *
     * Name and e-mail need `user.update`, roles need `role.manage`, and moving
     * the user to another tenant needs `tenant.manage`. Nobody changes their
     * own role, status or tenant: that is how an account escalates itself, or
     * locks the last administrator out.
     */
    public function update(Request $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:180', Rule::unique('users', 'email')->ignore($user->id)],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $currentRoles = $user->getRoleNames()->sort()->values()->all();
        $roles = isset($validated['roles']) ? collect($validated['roles'])->unique()->sort()->values()->all() : $currentRoles;
        $rolesChange = $roles !== $currentRoles;
        $statusChange = isset($validated['status']) && $validated['status'] !== $user->status;
        $tenantGiven = array_key_exists('tenant_id', $validated);

        // Checked first, so the answer is the same whatever else is wrong
        // with the request. A SuperAdmin passes every policy via
        // Gate::before, so this cannot live only in the policy.
        if ($request->user()->is($user) && ($rolesChange || $statusChange || $tenantGiven)) {
            throw ApiException::forbidden(
                'cannot_change_own_access',
                'You cannot change your own role, status or tenant. Ask another administrator.'
            );
        }

        $target = null;
        if ($tenantGiven) {
            $this->authorize('assignTenant', $user);
            $target = $this->resolveTargetTenant($request);
        }

        $tenant = $this->tenantFor(
            $roles,
            $tenantGiven ? $target : $user->tenant,
            explicit: $tenantGiven && $target !== null,
        );
        $tenantChange = $tenant?->id !== ($user->tenant_id === null ? null : (int) $user->tenant_id);

        if ($rolesChange) {
            $this->authorize('manageRoles', $user);
            $this->assertAssignableRoles($request, $roles);
        }

        // An invitee becomes active by accepting, not by being edited; making
        // them active here would leave a link that no longer works.
        if ($statusChange && $user->status === 'invited') {
            throw new ApiException(
                'still_invited',
                'This user has not accepted their invitation yet. Delete the user to withdraw it.',
                422
            );
        }

        $emailChange = isset($validated['email']) && $validated['email'] !== $user->email;
        $before = $user->getAttributes();
        $fromTenant = $user->tenant;

        DB::transaction(function () use ($user, $validated, $roles, $rolesChange, $tenant, $emailChange): void {
            // The invitation token is filed under the old address, so it is
            // dropped before the address changes rather than left to expire.
            if ($emailChange && $user->status === 'invited') {
                Password::broker('invitations')->deleteToken($user);
            }

            if ($rolesChange) {
                $user->syncRoles($roles);
            }

            $user->fill(array_intersect_key($validated, array_flip(['name', 'email', 'status'])));
            $user->tenant_id = $tenant?->id;
            $user->save();
        });

        if ($emailChange && $user->status === 'invited') {
            $this->sendInvitation($user, $request->user());
        }

        $this->audit->log('user.updated', $user, $before, $user->getAttributes());

        if ($tenantChange) {
            $this->audit->log('user.tenant_assigned', $user, ['tenant' => $fromTenant?->slug], ['tenant' => $tenant?->slug]);
        }

        return new UserResource($user->load(['roles', 'tenant']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $before = $user->getAttributes();
        $user->delete();

        $this->audit->log('user.deleted', $user, $before);

        return response()->json(['ok' => true]);
    }

    /**
     * The tenant a user with these roles ends up in.
     *
     * A SuperAdmin works across every tenant and has none of their own; anyone
     * else must have one, or every request they make is refused at the
     * tenant middleware.
     *
     * @param  list<string>  $roles
     */
    private function tenantFor(array $roles, ?Tenant $tenant, bool $explicit): ?Tenant
    {
        if (in_array('super_admin', $roles, true)) {
            if ($explicit) {
                throw ValidationException::withMessages([
                    'tenant_id' => 'A super administrator works across every tenant, so has no tenant of their own.',
                ]);
            }

            return null;
        }

        if ($tenant === null) {
            throw ValidationException::withMessages([
                'tenant_id' => 'Choose the tenant this user belongs to.',
            ]);
        }

        return $tenant;
    }

    /**
     * The link carries a token, never a password: the invitee chooses their
     * own on the accept-invitation page.
     */
    private function sendInvitation(User $user, User $invitedBy): void
    {
        $token = Password::broker('invitations')->createToken($user);

        $user->notify(new UserInvitationNotification($token, $invitedBy->name));
    }

    /**
     * Only a SuperAdmin may hand out `super_admin`. Otherwise a tenant_admin
     * could promote themselves out of their own tenant.
     *
     * @param  list<string>  $roles
     */
    private function assertAssignableRoles(Request $request, array $roles): void
    {
        if (in_array('super_admin', $roles, true) && ! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a super administrator may grant the super_admin role.');
        }
    }

    /** GET /roles — the assignable roles and what each can do. */
    public function roles(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $roles = Role::query()
            ->with('permissions')
            ->when(! $request->user()->isSuperAdmin(), fn ($q) => $q->where('name', '!=', 'super_admin'))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $roles->map(fn (Role $role): array => [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values(),
            ]),
        ]);
    }
}
