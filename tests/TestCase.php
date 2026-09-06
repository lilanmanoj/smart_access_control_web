<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DeviceContext;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** The plaintext secret used by {@see actingAsDevice}. */
    protected const DEVICE_SECRET = 'test-device-secret-value-000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        // Every request in the real application binds a tenant at the edge.
        // Tests build fixtures before that happens, so the context starts
        // clean and each test binds it explicitly where it matters.
        app(TenantContext::class)->forget();
        app(DeviceContext::class)->forget();
    }

    /**
     * Create a device with a working credential.
     *
     * @return array{device: Device, credential: DeviceCredential, secret: string}
     */
    protected function provisionDevice(?Tenant $tenant = null, string $secret = self::DEVICE_SECRET): array
    {
        $tenant ??= Tenant::factory()->create();
        $device = Device::factory()->for($tenant)->create();

        $credential = DeviceCredential::factory()
            ->for($device)
            ->withSecret($secret)
            ->create(['tenant_id' => $tenant->id]);

        return ['device' => $device, 'credential' => $credential, 'secret' => $secret];
    }

    /**
     * The three headers every device request carries.
     *
     * @return array<string, string>
     */
    protected function deviceHeaders(
        Device $device,
        DeviceCredential $credential,
        string $secret = self::DEVICE_SECRET,
    ): array {
        return [
            'X-Device-Id' => $device->device_id,
            'X-API-Key' => $credential->api_key,
            'X-API-Secret' => $secret,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Sign in an operator with the given role.
     *
     * Roles are seeded rather than faked: the whole point of the permission
     * layer is that policies check real permission names.
     */
    protected function actingAsOperator(string $role = 'tenant_admin', ?Tenant $tenant = null): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()
            ->for($tenant ?? Tenant::factory()->create())
            ->create();

        $user->assignRole($role);

        // Two-factor is enforced for device- and member-managing roles; tests
        // that are not about that flow start past it.
        if ($user->requiresTwoFactor()) {
            $user->forceFill([
                'two_factor_secret' => 'ABCDEFGHIJKLMNOP',
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        $this->actingAs($user);

        return $user;
    }

    /**
     * Sign in a SuperAdmin, in the cross-tenant fleet view.
     *
     * Sanctum only starts a session for a stateful origin, and the tenant
     * middleware reads a SuperAdmin's selected tenant from that session.
     */
    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $user->assignRole('super_admin');
        $user->forceFill([
            'two_factor_secret' => 'ABCDEFGHIJKLMNOP',
            'two_factor_confirmed_at' => now(),
        ])->save();

        config(['sanctum.stateful' => ['localhost']]);
        $this->actingAs($user)->withHeader('Referer', 'http://localhost');

        return $user;
    }

    /** Convenience for the admin API's URL prefix. */
    protected function adminGet(string $path, array $query = []): TestResponse
    {
        return $this->getJson('/api/admin/v1'.$path.($query === [] ? '' : '?'.http_build_query($query)));
    }
}
