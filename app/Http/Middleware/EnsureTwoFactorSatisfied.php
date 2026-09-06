<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the second factor for roles that can manage devices or members.
 *
 * Anyone who can open a door, or change who may, carries a second factor. A
 * user in one of those roles who has not enrolled yet is admitted only to the
 * enrolment endpoints — they can finish setting it up, and nothing else.
 */
class EnsureTwoFactorSatisfied
{
    /**
     * Routes reachable while a required second factor is still outstanding.
     */
    private const EXEMPT_ROUTES = [
        'admin.me',
        'admin.logout',
        'admin.two-factor.enroll',
        'admin.two-factor.confirm',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->requiresTwoFactor()) {
            return $next($request);
        }

        if ($user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        return response()->json([
            'error' => [
                'code' => 'two_factor_setup_required',
                'message' => 'Your role requires two-factor authentication. Finish setting it up to continue.',
            ],
        ], 403);
    }
}
