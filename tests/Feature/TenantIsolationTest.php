<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\TenantContextRequired;
use App\Models\AccessEvent;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The single most important security test in the system.
 *
 * One database holds every tenant's doors, members and access history. The only
 * thing keeping them apart is the global scope bound at the edge of each
 * request. If it can be bypassed — through a route binding, a device
 * credential, or a query that forgets — one customer reads another's door log.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_bound_tenant_sees_only_its_own_rows(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        Member::factory()->for($alpha)->count(3)->create();
        Member::factory()->for($beta)->count(5)->create();

        app(TenantContext::class)->set($alpha);
        $this->assertSame(3, Member::query()->count());

        app(TenantContext::class)->set($beta);
        $this->assertSame(5, Member::query()->count());
    }

    #[Test]
    public function a_foreign_row_cannot_be_found_by_id(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        $theirMember = Member::factory()->for($beta)->create();

        app(TenantContext::class)->set($alpha);

        $this->assertNull(Member::query()->find($theirMember->id));
        $this->assertNull(Member::query()->where('uuid', $theirMember->uuid)->first());
    }

    #[Test]
    public function writes_inherit_the_bound_tenant_without_being_told(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantContext::class)->set($tenant);

        $member = Member::create(['full_name' => 'Inherited']);

        $this->assertSame($tenant->id, $member->tenant_id);
    }

    /**
     * The device API's half of the boundary. A panel's credential resolves its
     * tenant, and no device request may read or write across tenants.
     */
    #[Test]
    public function a_device_cannot_read_another_tenants_member_through_the_api(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($alpha);
        $theirMember = Member::factory()->for($beta)->create();

        // The enrolment PATCH resolves a member by route binding; a foreign
        // uuid must 404 rather than resolve.
        $this->patchJson(
            "/api/v1/enrollments/{$theirMember->uuid}",
            ['phone' => '+94771234567'],
            $this->deviceHeaders($device, $credential),
        )->assertNotFound();
    }

    /**
     * A phone number known to one tenant is not known to another. Without this
     * the OTP flow becomes a cross-tenant lookup.
     */
    #[Test]
    public function an_otp_is_not_issued_for_another_tenants_phone_number(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($alpha);

        $theirMember = Member::factory()->for($beta)->create(['phone' => '+94771234567']);

        $this->postJson(
            '/api/v1/otp-requests',
            ['phone' => '+94771234567'],
            $this->deviceHeaders($device, $credential),
        )
            ->assertOk()
            // Not found, from this device's point of view — which is correct:
            // that person is not a member of this tenant.
            ->assertJsonPath('valid', false);

        $this->assertDatabaseMissing('otp_requests', ['member_id' => $theirMember->id]);
    }

    /**
     * A fingerprint slot number is device-local, so slot 7 exists on every
     * panel. Admin authentication must not resolve it against someone else's
     * enrolment.
     */
    #[Test]
    public function admin_authentication_does_not_match_another_tenants_enrollment(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        ['device' => $myDevice, 'credential' => $credential] = $this->provisionDevice($alpha);

        $theirAdmin = Member::factory()->for($beta)->admin()->create();
        $theirDevice = Device::factory()->for($beta)->create();

        Enrollment::factory()->create([
            'tenant_id' => $beta->id,
            'device_id' => $theirDevice->id,
            'member_id' => $theirAdmin->id,
            'fingerprint_slot' => 7,
        ]);

        $this->postJson(
            '/api/v1/authentications',
            ['enrollment_id' => 7],
            $this->deviceHeaders($myDevice, $credential),
        )
            ->assertOk()
            ->assertJsonPath('is_admin', false);
    }

    #[Test]
    public function an_operator_cannot_list_another_tenants_devices(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        Device::factory()->for($alpha)->count(2)->create();
        Device::factory()->for($beta)->count(4)->create();

        $this->actingAsOperator('tenant_admin', $alpha);

        $this->adminGet('/devices')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function an_operator_cannot_open_another_tenants_device(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        $theirDevice = Device::factory()->for($beta)->create();

        $this->actingAsOperator('tenant_admin', $alpha);

        $this->adminGet("/devices/{$theirDevice->uuid}")->assertNotFound();
    }

    #[Test]
    public function an_operator_cannot_read_another_tenants_access_events(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        $theirDevice = Device::factory()->for($beta)->create();
        AccessEvent::factory()->count(6)->create([
            'tenant_id' => $beta->id,
            'device_id' => $theirDevice->id,
        ]);

        $this->actingAsOperator('tenant_admin', $alpha);

        $this->adminGet('/access-events')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * A SuperAdmin in the cross-tenant fleet view has no tenant bound, so a
     * tenant-owned row has nowhere to go. That has to be an answer they can
     * act on, not `Field 'tenant_id' doesn't have a default value` from MySQL.
     */
    #[Test]
    public function creating_a_tenant_owned_row_with_no_tenant_selected_is_refused_clearly(): void
    {
        app(TenantContext::class)->forget();

        try {
            Device::create([
                'device_id' => 'SMA_DEMO_01',
                'name' => 'Demo Device 01',
                'location' => 'Laboratory',
            ]);

            $this->fail('Creating a device with no tenant bound should have been refused.');
        } catch (TenantContextRequired $exception) {
            $this->assertSame('tenant_not_selected', $exception->errorCode);
            $this->assertSame(409, $exception->status);
            $this->assertStringContainsString('device', $exception->getMessage());
        }

        $this->assertDatabaseMissing('devices', ['device_id' => 'SMA_DEMO_01']);
    }

    /**
     * The same write succeeds the moment a tenant is chosen — which is what the
     * refusal above tells the operator to do.
     */
    #[Test]
    public function the_same_write_succeeds_once_a_tenant_is_selected(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantContext::class)->set($tenant);

        $device = Device::create([
            'device_id' => 'SMA_DEMO_01',
            'name' => 'Demo Device 01',
            'location' => 'Laboratory',
        ]);

        $this->assertSame($tenant->id, $device->tenant_id);
    }

    /**
     * An explicit tenant_id is always honoured — that is how seeders, factories
     * and queued jobs write on behalf of a tenant they name themselves.
     */
    #[Test]
    public function an_explicit_tenant_id_is_honoured_with_no_context_bound(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantContext::class)->forget();

        $member = Member::create([
            'tenant_id' => $tenant->id,
            'full_name' => 'Named explicitly',
        ]);

        $this->assertSame($tenant->id, $member->tenant_id);
    }

    /**
     * The audit log is the deliberate exception: a SuperAdmin's cross-tenant
     * actions belong to no tenant, and those are the entries the trail most
     * needs.
     */
    #[Test]
    public function the_audit_log_may_be_written_with_no_tenant(): void
    {
        app(TenantContext::class)->forget();

        $log = AuditLog::create(['action' => 'tenant.switch_cleared']);

        $this->assertNull($log->tenant_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.switch_cleared']);
    }

    /**
     * Crossing the boundary is possible, but only through the explicit path —
     * and that path is what the SuperAdmin tenant switch is audited on.
     */
    #[Test]
    public function the_cross_tenant_escape_hatch_is_explicit(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();

        Member::factory()->for($alpha)->count(2)->create();
        Member::factory()->for($beta)->count(3)->create();

        $context = app(TenantContext::class);
        $context->set($alpha);

        $this->assertSame(2, Member::query()->count());
        $this->assertSame(5, $context->crossTenant(fn (): int => Member::query()->count()));

        // The binding is restored afterwards; a cross-tenant read does not
        // leave the request unscoped.
        $this->assertSame(2, Member::query()->count());
        $this->assertSame($alpha->id, $context->id());
    }
}
