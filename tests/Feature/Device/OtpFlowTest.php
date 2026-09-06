<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Enums\OtpStatus;
use App\Jobs\DeliverOtp;
use App\Models\AccessEvent;
use App\Models\Member;
use App\Models\OtpRequest;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The OTP flow, under the corrected contract from §7.3.
 *
 * The property being protected: the plaintext code never travels to the
 * device. Under the original design anyone holding a device credential could
 * request a code for any phone number and read it out of the response.
 */
class OtpFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_issues_a_request_id_and_never_the_code(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);
        Member::factory()->for($tenant)->create(['phone' => '+94771234567']);

        $response = $this->postJson('/api/v1/otp-requests', ['phone' => '0771234567'],
            $this->deviceHeaders($device, $credential))->assertOk();

        $response->assertJsonPath('valid', true)
            ->assertJsonStructure(['valid', 'otp_request_id', 'expires_in'])
            // The whole point of the change.
            ->assertJsonMissingPath('otp');

        $this->assertDatabaseCount('otp_requests', 1);
    }

    #[Test]
    public function the_stored_code_is_not_recoverable_from_the_database(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);
        Member::factory()->for($tenant)->create(['phone' => '+94771234567']);

        $this->postJson('/api/v1/otp-requests', ['phone' => '+94771234567'],
            $this->deviceHeaders($device, $credential))->assertOk();

        $stored = OtpRequest::withoutGlobalScopes()->firstOrFail()->code_hash;

        $this->assertSame(64, strlen($stored), 'The code should be stored as a SHA-256 HMAC.');
        $this->assertDoesNotMatchRegularExpression('/^\d{6}$/', $stored);
    }

    /**
     * A national number typed on the keypad has to find a member stored in
     * E.164. That normalisation is the whole reason the lookup works.
     */
    #[Test]
    public function it_normalises_the_typed_number_before_looking_it_up(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);
        Member::factory()->for($tenant)->create(['phone' => '+94771234567']);

        foreach (['0771234567', '+94771234567', '0094771234567', '077 123 4567'] as $typed) {
            $this->postJson('/api/v1/otp-requests', ['phone' => $typed],
                $this->deviceHeaders($device, $credential))
                ->assertOk()
                ->assertJsonPath('valid', true);
        }
    }

    #[Test]
    public function an_unknown_number_is_reported_as_invalid(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->postJson('/api/v1/otp-requests', ['phone' => '+94770000000'],
            $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonPath('valid', false);
    }

    /**
     * A suspended member and an unknown number give the same answer. A door is
     * not a place to learn who exists.
     */
    #[Test]
    public function a_suspended_member_is_indistinguishable_from_an_unknown_number(): void
    {
        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);
        Member::factory()->for($tenant)->suspended()->create(['phone' => '+94771234567']);

        $suspended = $this->postJson('/api/v1/otp-requests', ['phone' => '+94771234567'],
            $this->deviceHeaders($device, $credential))->json();

        $unknown = $this->postJson('/api/v1/otp-requests', ['phone' => '+94770000000'],
            $this->deviceHeaders($device, $credential))->json();

        $this->assertSame($unknown, $suspended);
    }

    #[Test]
    public function a_denied_issuance_is_still_written_to_the_access_log(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->postJson('/api/v1/otp-requests', ['phone' => '+94770000000'],
            $this->deviceHeaders($device, $credential))->assertOk();

        $event = AccessEvent::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('otp', $event->method->value);
        $this->assertSame('denied', $event->result->value);
        $this->assertSame('not_enrolled', $event->reason);
    }

    #[Test]
    public function delivery_is_queued_rather_than_blocking_the_panel(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);
        Member::factory()->for($tenant)->create(['phone' => '+94771234567']);

        $this->postJson('/api/v1/otp-requests', ['phone' => '+94771234567'],
            $this->deviceHeaders($device, $credential))->assertOk();

        Queue::assertPushed(DeliverOtp::class);
    }

    #[Test]
    public function it_verifies_a_correct_code(): void
    {
        [$device, $credential, $request, $code] = $this->issueOtp();

        $this->postJson('/api/v1/otp-verifications', [
            'otp_request_id' => $request->uuid,
            'code' => $code,
        ], $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->assertSame(OtpStatus::Verified, $request->fresh()->status);

        $this->assertDatabaseHas('access_events', [
            'method' => 'otp',
            'result' => 'granted',
            'reason' => 'otp_verified',
        ]);
    }

    #[Test]
    public function it_counts_down_attempts_on_a_wrong_code(): void
    {
        [$device, $credential, $request] = $this->issueOtp();

        $this->postJson('/api/v1/otp-verifications', [
            'otp_request_id' => $request->uuid,
            'code' => '000000',
        ], $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJson(['verified' => false, 'reason' => 'otp_mismatch', 'attempts_left' => 2]);
    }

    #[Test]
    public function it_refuses_a_code_after_the_attempts_run_out(): void
    {
        [$device, $credential, $request, $code] = $this->issueOtp();
        $headers = $this->deviceHeaders($device, $credential);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/otp-verifications', [
                'otp_request_id' => $request->uuid,
                'code' => '000000',
            ], $headers)->assertOk()->assertJsonPath('verified', false);
        }

        // Even the right code is no good once the budget is spent.
        $this->postJson('/api/v1/otp-verifications', [
            'otp_request_id' => $request->uuid,
            'code' => $code,
        ], $headers)->assertOk()->assertJsonPath('verified', false);
    }

    #[Test]
    public function it_refuses_an_expired_code(): void
    {
        [$device, $credential, $request, $code] = $this->issueOtp();

        $this->travel(120)->seconds();

        $this->postJson('/api/v1/otp-verifications', [
            'otp_request_id' => $request->uuid,
            'code' => $code,
        ], $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJson(['verified' => false, 'reason' => 'otp_expired']);
    }

    /**
     * A member suspended between issue and entry must not get in on a code
     * that was valid ninety seconds ago.
     */
    #[Test]
    public function it_refuses_a_code_belonging_to_a_now_suspended_member(): void
    {
        [$device, $credential, $request, $code] = $this->issueOtp();

        $request->member->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/v1/otp-verifications', [
            'otp_request_id' => $request->uuid,
            'code' => $code,
        ], $this->deviceHeaders($device, $credential))
            ->assertOk()
            ->assertJson(['verified' => false, 'reason' => 'member_suspended']);
    }

    #[Test]
    public function a_device_cannot_verify_another_devices_otp_request(): void
    {
        $tenant = Tenant::factory()->create();
        [, , $request, $code] = $this->issueOtp($tenant);
        ['device' => $other, 'credential' => $otherCredential] =
            $this->provisionDevice($tenant, 'other-secret-value-000000000000000000');

        $this->postJson('/api/v1/otp-verifications', [
            'otp_request_id' => $request->uuid,
            'code' => $code,
        ], $this->deviceHeaders($other, $otherCredential, 'other-secret-value-000000000000000000'))
            ->assertOk()
            ->assertJsonPath('verified', false);
    }

    #[Test]
    public function the_legacy_delivery_endpoint_accepts_and_discards(): void
    {
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice();

        $this->postJson('/api/v1/otp-deliveries', [
            'phone' => '+94771234567',
            'otp' => '123456',
        ], $this->deviceHeaders($device, $credential))
            ->assertAccepted()
            ->assertJsonPath('queued', []);
    }

    /**
     * Issue an OTP and recover the plaintext code, which production code never
     * exposes — the job payload is the only place it exists.
     *
     * @return array{0: \App\Models\Device, 1: \App\Models\DeviceCredential, 2: OtpRequest, 3: string}
     */
    private function issueOtp(?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::factory()->create();
        ['device' => $device, 'credential' => $credential] = $this->provisionDevice($tenant);
        Member::factory()->for($tenant)->create(['phone' => '+94771234567']);

        $code = null;
        Queue::fake();

        $this->postJson('/api/v1/otp-requests', ['phone' => '+94771234567'],
            $this->deviceHeaders($device, $credential))->assertOk();

        Queue::assertPushed(DeliverOtp::class, function (DeliverOtp $job) use (&$code): bool {
            $code = (new \ReflectionProperty($job, 'code'))->getValue($job);

            return true;
        });

        return [$device, $credential, OtpRequest::withoutGlobalScopes()->firstOrFail(), (string) $code];
    }
}
