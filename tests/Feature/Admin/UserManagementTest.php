<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inviting operators into a tenant, editing them, and moving them between
 * tenants. A tenant admin works only inside their own tenant; reaching across
 * takes `tenant.manage`.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
    }

    // ---------------------------------------------------------------------
    // Inviting
    // ---------------------------------------------------------------------

    #[Test]
    public function a_tenant_admins_invitee_joins_the_admins_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);

        $this->invite()->assertCreated()->assertJsonPath('data.tenant.id', $tenant->uuid);

        $this->assertSame($tenant->id, $this->invitee()->tenant_id);
    }

    #[Test]
    public function a_tenant_admin_cannot_invite_into_another_tenant(): void
    {
        $this->actingAsOperator('tenant_admin');
        $other = Tenant::factory()->create();

        $this->invite(['tenant_id' => $other->uuid])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tenant_selection_forbidden');

        $this->assertDatabaseMissing('users', ['email' => 'sam@example.com']);
    }

    #[Test]
    public function a_super_admin_in_fleet_view_must_name_the_tenant(): void
    {
        $this->actingAsSuperAdmin();

        $this->invite()->assertUnprocessable()->assertJsonValidationErrors('tenant_id', 'error.fields');

        $this->assertDatabaseMissing('users', ['email' => 'sam@example.com']);
    }

    #[Test]
    public function a_super_admin_invites_into_the_tenant_they_name(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->create();

        $this->invite(['tenant_id' => $tenant->uuid])
            ->assertCreated()
            ->assertJsonPath('data.tenant.id', $tenant->uuid);

        $this->assertSame($tenant->id, $this->invitee()->tenant_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.invited']);
    }

    #[Test]
    public function a_super_admin_switched_into_a_tenant_invites_into_it_by_default(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->create();

        $this->withSession(['impersonated_tenant_id' => $tenant->id])
            ->invite()
            ->assertCreated();

        $this->assertSame($tenant->id, $this->invitee()->tenant_id);
    }

    #[Test]
    public function an_unknown_or_suspended_tenant_is_refused(): void
    {
        $this->actingAsSuperAdmin();
        $suspended = Tenant::factory()->create(['status' => 'suspended']);

        $this->invite(['tenant_id' => '00000000-0000-4000-8000-000000000000'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'unknown_tenant');

        $this->invite(['tenant_id' => $suspended->uuid])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'tenant_suspended');
    }

    #[Test]
    public function a_super_admin_invitee_has_no_tenant(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->create();

        $this->invite(['roles' => ['super_admin'], 'tenant_id' => $tenant->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id', 'error.fields');

        $this->withSession(['impersonated_tenant_id' => $tenant->id])
            ->invite(['roles' => ['super_admin']])
            ->assertCreated();

        $this->assertNull($this->invitee()->tenant_id);
    }

    // ---------------------------------------------------------------------
    // Editing inside a tenant
    // ---------------------------------------------------------------------

    #[Test]
    public function a_tenant_admin_edits_a_user_in_their_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);
        $subject = $this->operatorIn($tenant);

        $this->patchJson("/api/admin/v1/users/{$subject->uuid}", [
            'name' => 'Renamed',
            'email' => 'renamed@example.com',
            'roles' => ['auditor'],
            'status' => 'suspended',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.email', 'renamed@example.com')
            ->assertJsonPath('data.roles', ['auditor'])
            ->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'auditable_id' => $subject->id]);
    }

    #[Test]
    public function a_tenant_admin_cannot_edit_another_tenants_user(): void
    {
        $this->actingAsOperator('tenant_admin');
        $foreign = $this->operatorIn(Tenant::factory()->create());

        $this->patchJson("/api/admin/v1/users/{$foreign->uuid}", ['name' => 'Taken over'])->assertForbidden();
        $this->deleteJson("/api/admin/v1/users/{$foreign->uuid}")->assertForbidden();

        $this->assertNotSame('Taken over', $foreign->fresh()->name);
    }

    #[Test]
    public function a_tenant_admin_cannot_edit_or_delete_a_super_admin(): void
    {
        $this->actingAsOperator('tenant_admin');
        $super = User::factory()->create(['tenant_id' => null, 'email' => 'root@example.com']);
        $super->assignRole('super_admin');

        // A null tenant is not "nobody's row": changing this address would
        // let a password reset take the account over.
        $this->patchJson("/api/admin/v1/users/{$super->uuid}", ['email' => 'mine@example.com'])->assertForbidden();
        $this->deleteJson("/api/admin/v1/users/{$super->uuid}")->assertForbidden();

        $this->assertSame('root@example.com', $super->fresh()->email);
    }

    #[Test]
    public function a_tenant_admin_cannot_move_a_user_to_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);
        $subject = $this->operatorIn($tenant);
        $other = Tenant::factory()->create();

        $this->patchJson("/api/admin/v1/users/{$subject->uuid}", ['tenant_id' => $other->uuid])->assertForbidden();

        $this->assertSame($tenant->id, $subject->fresh()->tenant_id);
    }

    #[Test]
    public function a_tenant_admin_edits_their_own_name_but_not_their_own_status(): void
    {
        $admin = $this->actingAsOperator('tenant_admin');

        $this->patchJson("/api/admin/v1/users/{$admin->uuid}", ['name' => 'Still allowed'])->assertOk();
        $this->patchJson("/api/admin/v1/users/{$admin->uuid}", ['status' => 'suspended'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cannot_change_own_access');

        $this->assertSame('active', $admin->fresh()->status);
    }

    #[Test]
    public function a_super_admin_cannot_demote_themselves_or_take_a_tenant(): void
    {
        // Gate::before passes a SuperAdmin through every policy, so the
        // controller has to refuse this itself.
        $super = $this->actingAsSuperAdmin();

        $this->patchJson("/api/admin/v1/users/{$super->uuid}", ['roles' => ['auditor']])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cannot_change_own_access');
        $this->patchJson("/api/admin/v1/users/{$super->uuid}", ['tenant_id' => Tenant::factory()->create()->uuid])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cannot_change_own_access');

        $this->assertTrue($super->fresh()->isSuperAdmin());
        $this->assertNull($super->fresh()->tenant_id);
    }

    #[Test]
    public function an_invitee_is_activated_by_accepting_not_by_editing(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);
        $invitee = $this->operatorIn($tenant, ['status' => 'invited']);

        $this->patchJson("/api/admin/v1/users/{$invitee->uuid}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'still_invited');
    }

    #[Test]
    public function correcting_an_invitees_address_sends_the_invitation_to_the_new_one(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOperator('tenant_admin', $tenant);
        $invitee = $this->operatorIn($tenant, ['status' => 'invited', 'email' => 'typo@example.con']);

        $this->patchJson("/api/admin/v1/users/{$invitee->uuid}", ['email' => 'sam@example.com'])->assertOk();

        Notification::assertSentTo($invitee->fresh(), UserInvitationNotification::class);
        $this->assertSame('sam@example.com', $invitee->fresh()->email);
    }

    // ---------------------------------------------------------------------
    // Assigning tenants
    // ---------------------------------------------------------------------

    #[Test]
    public function a_super_admin_moves_a_user_to_another_tenant(): void
    {
        $from = Tenant::factory()->create();
        $to = Tenant::factory()->create();
        $subject = $this->operatorIn($from);
        $this->actingAsSuperAdmin();

        $this->patchJson("/api/admin/v1/users/{$subject->uuid}", ['tenant_id' => $to->uuid])
            ->assertOk()
            ->assertJsonPath('data.tenant.id', $to->uuid);

        $this->assertSame($to->id, $subject->fresh()->tenant_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.tenant_assigned', 'auditable_id' => $subject->id]);
    }

    #[Test]
    public function a_super_admin_repairs_a_user_with_no_tenant(): void
    {
        $orphan = User::factory()->create(['tenant_id' => null]);
        $orphan->assignRole('operator');
        $tenant = Tenant::factory()->create();
        $this->actingAsSuperAdmin();

        // Any edit has to settle the tenant, since the account cannot work without one.
        $this->patchJson("/api/admin/v1/users/{$orphan->uuid}", ['name' => 'Renamed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id', 'error.fields');

        $this->patchJson("/api/admin/v1/users/{$orphan->uuid}", ['tenant_id' => $tenant->uuid])->assertOk();

        $this->assertSame($tenant->id, $orphan->fresh()->tenant_id);
    }

    #[Test]
    public function promoting_to_super_admin_detaches_the_tenant_and_demoting_needs_one(): void
    {
        $tenant = Tenant::factory()->create();
        $subject = $this->operatorIn($tenant);
        $this->actingAsSuperAdmin();

        $this->patchJson("/api/admin/v1/users/{$subject->uuid}", ['roles' => ['super_admin']])->assertOk();
        $this->assertNull($subject->fresh()->tenant_id);

        $this->patchJson("/api/admin/v1/users/{$subject->uuid}", ['roles' => ['operator']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id', 'error.fields');

        $this->patchJson("/api/admin/v1/users/{$subject->uuid}", ['roles' => ['operator'], 'tenant_id' => $tenant->uuid])
            ->assertOk();
        $this->assertSame($tenant->id, $subject->fresh()->tenant_id);
        $this->assertFalse($subject->fresh()->isSuperAdmin());
    }

    #[Test]
    public function the_list_shows_each_users_tenant_to_a_super_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $this->operatorIn($tenant, ['name' => 'Aaron']);
        $this->actingAsSuperAdmin();

        $this->adminGet('/users')->assertOk()->assertJsonPath('data.0.tenant.id', $tenant->uuid);
    }

    // ---------------------------------------------------------------------

    private function invite(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/admin/v1/users', [
            'name' => 'Sam Sample',
            'email' => 'sam@example.com',
            'roles' => ['operator'],
            ...$overrides,
        ]);
    }

    private function invitee(): User
    {
        return User::query()->where('email', 'sam@example.com')->sole();
    }

    private function operatorIn(Tenant $tenant, array $attributes = []): User
    {
        $user = User::factory()->for($tenant)->create(['status' => 'active', ...$attributes]);
        $user->assignRole('operator');

        return $user;
    }
}
