<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles')
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
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('invite', User::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        $this->assertAssignableRoles($request, $validated['roles']);

        $user = User::create([
            'tenant_id' => $request->user()->isSuperAdmin()
                ? $request->input('tenant_id')
                : $request->user()->tenant_id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            // Replaced by the invitee via the reset link; never usable as-is.
            'password' => Str::random(64),
            'status' => 'invited',
        ]);

        $user->syncRoles($validated['roles']);

        $this->sendInvitation($user, $request->user());

        $this->audit->log('user.invited', $user, after: [
            'email' => $user->email,
            'roles' => $validated['roles'],
        ]);

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
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

    public function update(Request $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $before = $user->getAttributes();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        if (isset($validated['roles'])) {
            $this->authorize('manageRoles', $user);
            $this->assertAssignableRoles($request, $validated['roles']);
            $user->syncRoles($validated['roles']);
        }

        $user->update(array_diff_key($validated, ['roles' => null]));

        $this->audit->log('user.updated', $user, $before, $user->getAttributes());

        return new UserResource($user->load('roles'));
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
     * Only a SuperAdmin may hand out `super_admin`. Otherwise a tenant_admin
     * could promote themselves out of their own tenant.
     *
     * @param  list<string>  $roles
     */
    /**
     * The link carries a token, never a password: the invitee chooses their
     * own on the accept-invitation page.
     */
    private function sendInvitation(User $user, User $invitedBy): void
    {
        $token = Password::broker('invitations')->createToken($user);

        $user->notify(new UserInvitationNotification($token, $invitedBy->name));
    }

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
