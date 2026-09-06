<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Session authentication for the dashboard SPA (Sanctum, cookie-based).
 *
 * Sign-in is two-legged for anyone carrying a second factor: password first,
 * then a TOTP challenge. The session is not authenticated in between — a
 * correct password alone gets you a pending id in the session and nothing
 * else.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $this->ensureNotThrottled($request);

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => ['This account is not active.'],
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        if ($user->hasTwoFactorEnabled()) {
            // Held in the session, not returned: the client learns only that a
            // challenge is needed, never which account is halfway through one.
            $request->session()->put('two_factor:pending_id', $user->id);
            $request->session()->put('two_factor:remember', (bool) ($credentials['remember'] ?? false));

            return response()->json(['two_factor_required' => true]);
        }

        $this->completeLogin($request, $user, (bool) ($credentials['remember'] ?? false));

        return response()->json([
            'two_factor_required' => false,
            'user' => new UserResource($user->load(['roles', 'tenant'])),
        ]);
    }

    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $pendingId = $request->session()->get('two_factor:pending_id');

        if ($pendingId === null) {
            throw ValidationException::withMessages([
                'code' => ['That sign-in has expired. Start again.'],
            ]);
        }

        $this->ensureNotThrottled($request);

        $user = User::query()->find($pendingId);

        if ($user === null || ! $this->twoFactor->verify($user, $validated['code'])) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'code' => ['That code is not valid.'],
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        $remember = (bool) $request->session()->get('two_factor:remember', false);
        $request->session()->forget(['two_factor:pending_id', 'two_factor:remember']);

        $this->completeLogin($request, $user, $remember);

        return response()->json([
            'user' => new UserResource($user->load(['roles', 'tenant'])),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        // The UUID, not the primary key: the dashboard addresses tenants by
        // the same identifier the rest of the API exposes.
        $impersonated = $user->isSuperAdmin()
            ? app(TenantContext::class)->tenant()?->uuid
            : null;

        return response()->json([
            'user' => new UserResource($user->load(['roles', 'tenant'])),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
            // Only a SuperAdmin sees this, and only they can act on it.
            'impersonated_tenant_id' => $impersonated,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->log('auth.logout');

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /**
     * Begin TOTP enrolment. Returns the secret, an otpauth:// URL and an
     * inline SVG QR code.
     */
    public function enrollTwoFactor(Request $request): JsonResponse
    {
        return response()->json($this->twoFactor->beginEnrollment($request->user()));
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $recovery = $this->twoFactor->confirmEnrollment($request->user(), $validated['code']);

        if ($recovery === null) {
            throw ValidationException::withMessages([
                'code' => ['That code is not valid. Check your device clock and try again.'],
            ]);
        }

        $this->audit->log('user.two_factor_enabled', $request->user());

        // Shown once. There is no endpoint that returns them again.
        return response()->json(['recovery_codes' => $recovery]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->requiresTwoFactor()) {
            return response()->json([
                'error' => [
                    'code' => 'two_factor_required',
                    'message' => 'Your role requires two-factor authentication; it cannot be turned off.',
                ],
            ], 403);
        }

        $this->twoFactor->disable($user);
        $this->audit->log('user.two_factor_disabled', $user);

        return response()->json(['ok' => true]);
    }

    private function completeLogin(Request $request, User $user, bool $remember): void
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        $this->audit->log('auth.login', $user);
    }

    private function ensureNotThrottled(Request $request): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            throw ValidationException::withMessages([
                'email' => ['Too many attempts. Try again in '.
                    RateLimiter::availableIn($this->throttleKey($request)).' seconds.'],
            ]);
        }
    }

    private function throttleKey(Request $request): string
    {
        return 'login:'.strtolower((string) $request->input('email')).'|'.$request->ip();
    }
}
