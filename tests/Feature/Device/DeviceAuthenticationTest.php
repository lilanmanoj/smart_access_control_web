<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The device credential is the single gate in front of the whole device API,
 * so its failure modes get their own suite.
 *
 * The rule under test throughout: every failure looks the same from outside.
 * Which of the three header values was wrong is not something a caller gets to
 * learn by probing.
 */
class DeviceAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_accepts_a_valid_credential(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    #[Test]
    public function it_rejects_a_request_with_no_headers(): void
    {
        $this->getJson('/api/v1/health')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthorized');
    }

    #[Test]
    public function it_rejects_an_unknown_api_key(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $headers = $this->deviceHeaders($device, $credential);
        $headers['X-API-Key'] = 'sak_not_a_real_key';

        $this->getJson('/api/v1/health', $headers)->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_a_wrong_secret(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $headers = $this->deviceHeaders($device, $credential);
        $headers['X-API-Secret'] = 'wrong';

        $this->getJson('/api/v1/health', $headers)->assertUnauthorized();
    }

    /**
     * The device id is asserted by the device. Without this check, one panel's
     * credential could be used to write another panel's history.
     */
    #[Test]
    public function it_rejects_a_device_id_that_does_not_match_the_credential(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $headers = $this->deviceHeaders($device, $credential);
        $headers['X-Device-Id'] = 'SMA_0000';

        $this->getJson('/api/v1/health', $headers)->assertUnauthorized();
    }

    /**
     * Even with a valid credential of its own, one device cannot claim to be
     * another — including a real device in the same tenant.
     */
    #[Test]
    public function it_rejects_another_devices_identity_with_a_valid_credential(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $mine, 'credential' => $credential] = $this->provisionDevice($tenant);
        $neighbour = Device::factory()->for($tenant)->create();

        $headers = $this->deviceHeaders($mine, $credential);
        $headers['X-Device-Id'] = $neighbour->device_id;

        $this->getJson('/api/v1/health', $headers)->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_a_revoked_credential(): void
    {
        ['device' => $device] = $this->provisionDevice();

        $revoked = DeviceCredential::factory()
            ->for($device)
            ->withSecret('another-secret')
            ->revoked()
            ->create(['tenant_id' => $device->tenant_id]);

        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $revoked, 'another-secret'))
            ->assertUnauthorized();
    }

    #[Test]
    public function it_rejects_an_expired_credential(): void
    {
        ['device' => $device] = $this->provisionDevice();

        $expired = DeviceCredential::factory()
            ->for($device)
            ->withSecret('another-secret')
            ->expired()
            ->create(['tenant_id' => $device->tenant_id]);

        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $expired, 'another-secret'))
            ->assertUnauthorized();
    }

    /**
     * A suspended device gets a 403, not a 401 — the credential is fine, the
     * device is not permitted. That distinction matters to whoever is holding
     * a screwdriver in front of the panel.
     */
    #[Test]
    public function it_forbids_a_suspended_device(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();
        $device->forceFill(['status' => DeviceStatus::Suspended])->save();

        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'device_suspended');
    }

    #[Test]
    public function it_forbids_a_device_belonging_to_a_suspended_tenant(): void
    {
        $tenant = Tenant::factory()->suspended()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);

        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tenant_suspended');
    }

    #[Test]
    public function every_credential_failure_returns_the_same_body(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $badKey = $this->deviceHeaders($device, $credential);
        $badKey['X-API-Key'] = 'sak_nope';

        $badSecret = $this->deviceHeaders($device, $credential);
        $badSecret['X-API-Secret'] = 'nope';

        $badDevice = $this->deviceHeaders($device, $credential);
        $badDevice['X-Device-Id'] = 'SMA_9999';

        $bodies = collect([$badKey, $badSecret, $badDevice])
            ->map(fn (array $headers) => $this->getJson('/api/v1/health', $headers)->json())
            ->unique();

        // One distinct body across all three: nothing distinguishes which
        // value was wrong.
        $this->assertCount(1, $bodies);
    }

    /**
     * HMAC request signing is documented as phase-2 and has no implementation.
     * Enabling it must fail loudly rather than leave an operator believing
     * requests are signed.
     */
    #[Test]
    public function enabling_unimplemented_request_signing_refuses_every_request(): void
    {
        config(['access.security.require_request_signature' => true]);

        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'signature_required_but_unimplemented');
    }

    #[Test]
    public function it_records_credential_usage(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->assertNull($credential->last_used_at);

        $this->getJson('/api/v1/health', $this->deviceHeaders($device, $credential))->assertOk();

        $this->assertNotNull($credential->fresh()->last_used_at);
    }
}
