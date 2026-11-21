<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Operator invitations: the e-mailed link, choosing a password, signing in.
 *
 * Accepting is unauthenticated, so the tests that matter are the refusals —
 * it must complete an invitation and nothing else.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-enough-password';

    #[Test]
    public function an_invitee_sets_a_password_from_the_link_and_can_then_sign_in(): void
    {
        $token = $this->invite('sam@example.com');

        $this->accept('sam@example.com', $token)->assertOk()->assertJsonPath('ok', true);

        $user = User::query()->where('email', 'sam@example.com')->sole();
        $this->assertSame('active', $user->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.invitation_accepted', 'auditable_id' => $user->id]);

        // Sign-in is session based, so it needs Sanctum's stateful origin.
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Referer', 'http://localhost')
            ->postJson('/api/admin/v1/login', ['email' => 'sam@example.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.email', 'sam@example.com');
    }

    #[Test]
    public function the_link_works_only_once(): void
    {
        $token = $this->invite('sam@example.com');

        $this->accept('sam@example.com', $token)->assertOk();
        $this->accept('sam@example.com', $token)->assertUnprocessable()
            ->assertJsonPath('error.code', 'invitation_invalid');
    }

    #[Test]
    public function a_wrong_token_or_address_is_refused_with_one_answer(): void
    {
        $token = $this->invite('sam@example.com');

        $this->accept('sam@example.com', 'not-the-token')->assertUnprocessable()
            ->assertJsonPath('error.code', 'invitation_invalid');
        $this->accept('nobody@example.com', $token)->assertUnprocessable()
            ->assertJsonPath('error.code', 'invitation_invalid');

        $this->assertSame('invited', User::query()->where('email', 'sam@example.com')->sole()->status);
    }

    #[Test]
    public function it_cannot_be_used_to_reset_an_active_accounts_password(): void
    {
        $tenant = Tenant::factory()->create();
        $active = User::factory()->for($tenant)->create(['email' => 'alex@example.com', 'status' => 'active']);
        $token = app('auth.password')->broker('invitations')->createToken($active);

        $this->accept('alex@example.com', $token)->assertUnprocessable()
            ->assertJsonPath('error.code', 'invitation_invalid');
    }

    #[Test]
    public function a_short_or_unconfirmed_password_is_a_field_error(): void
    {
        $token = $this->invite('sam@example.com');

        $this->accept('sam@example.com', $token, 'short')->assertUnprocessable();
        $this->postJson('/api/admin/v1/invitation-acceptances', [
            'email' => 'sam@example.com',
            'token' => $token,
            'password' => self::PASSWORD,
            'password_confirmation' => 'something-else-entirely',
        ])->assertUnprocessable();

        $this->assertSame('invited', User::query()->where('email', 'sam@example.com')->sole()->status);
    }

    #[Test]
    public function resending_replaces_the_link(): void
    {
        $tenant = Tenant::factory()->create();
        $first = $this->invite('sam@example.com', $tenant);

        $this->actingAsOperator('tenant_admin', $tenant);
        $invitee = User::query()->where('email', 'sam@example.com')->sole();

        Notification::fake();
        $this->postJson("/api/admin/v1/users/{$invitee->uuid}/invitations")->assertAccepted();

        $second = null;
        Notification::assertSentTo($invitee, UserInvitationNotification::class, function ($n) use (&$second): bool {
            $second = $this->tokenFrom($n);

            return true;
        });

        $this->app['auth']->forgetGuards();
        $this->accept('sam@example.com', $first)->assertUnprocessable();
        $this->accept('sam@example.com', (string) $second)->assertOk();
    }

    #[Test]
    public function there_is_nothing_to_resend_to_an_account_that_is_set_up(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);
        $active = User::factory()->for($tenant)->create(['status' => 'active']);

        $this->postJson("/api/admin/v1/users/{$active->uuid}/invitations")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'not_invited');
    }

    #[Test]
    public function the_email_links_to_the_accept_page_and_states_the_expiry(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);
        Notification::fake();

        $this->postJson('/api/admin/v1/users', [
            'name' => 'Sam Sample',
            'email' => 'sam@example.com',
            'roles' => ['operator'],
        ])->assertCreated();

        $invitee = User::query()->where('email', 'sam@example.com')->sole();

        Notification::assertSentTo($invitee, UserInvitationNotification::class, function ($n) use ($invitee): bool {
            $mail = $n->toMail($invitee);

            return str_contains((string) $mail->actionUrl, '/accept-invitation?token=')
                && in_array('This link expires in 3 days.', $mail->outroLines, true);
        });
    }

    // ---------------------------------------------------------------------

    /** Invite through the API, as an operator would. Returns the token. */
    private function invite(string $email, ?Tenant $tenant = null): string
    {
        $this->actingAsOperator('tenant_admin', $tenant ?? Tenant::factory()->create());
        Notification::fake();

        $this->postJson('/api/admin/v1/users', [
            'name' => 'Sam Sample',
            'email' => $email,
            'roles' => ['operator'],
        ])->assertCreated();

        $invitee = User::query()->where('email', $email)->sole();
        $token = null;

        Notification::assertSentTo($invitee, UserInvitationNotification::class, function ($n) use (&$token): bool {
            $token = $this->tokenFrom($n);

            return true;
        });

        // The invitee is somebody else, arriving without a session.
        $this->app['auth']->forgetGuards();

        return (string) $token;
    }

    private function accept(string $email, string $token, string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson('/api/admin/v1/invitation-acceptances', [
            'email' => $email,
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    private function tokenFrom(UserInvitationNotification $notification): string
    {
        $url = (string) $notification->toMail(new User(['email' => 'x@example.com', 'name' => 'x']))->actionUrl;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['token'] ?? '');
    }
}
