<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * POST /invitation-acceptances — an invited operator chooses a password.
 *
 * The invitation e-mail links to the SPA's /accept-invitation page, which
 * posts the token from the link here. Unauthenticated by necessity, so it is
 * throttled like sign-in, and it only ever completes an *invitation*: an
 * account that is already set up is refused, which keeps this from doubling
 * as a password-reset endpoint nobody designed.
 *
 * Nothing signs the invitee in. They sign in normally afterwards, which is
 * also where a role that requires a second factor sets one up.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12)],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        // One answer for an unknown address, a finished account and a bad or
        // expired token: the page cannot be used to learn who has an account.
        if ($user === null || $user->status !== 'invited') {
            throw $this->invalid();
        }

        $result = Password::broker('invitations')->reset(
            [
                'email' => $validated['email'],
                'token' => $validated['token'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    // Hashed by the model's cast.
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'status' => 'active',
                ])->save();
            },
        );

        if ($result !== Password::PASSWORD_RESET) {
            throw $this->invalid();
        }

        $this->audit->log('user.invitation_accepted', $user, after: ['email' => $user->email]);

        return response()->json(['ok' => true]);
    }

    private function invalid(): ApiException
    {
        return new ApiException(
            'invitation_invalid',
            'This invitation link is invalid or has expired. Ask an administrator to send a new one.',
            422
        );
    }
}
