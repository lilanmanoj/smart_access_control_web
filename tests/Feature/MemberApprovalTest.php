<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\Member;
use App\Models\Tenant;
use Database\Factories\FingerprintTemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Member approval (manual §9.2).
 *
 * The property being protected: when a tenant requires approval, someone who
 * enrolled themselves at a panel gets nothing the server decides — no OTP, no
 * Admin Settings, no fingerprint at the other doors — until an operator says
 * so. Their finger keeps working at the panel they enrolled at, which the
 * manual promises and the sensor decides on its own anyway.
 */
class MemberApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Device $a;

    private DeviceCredential $aCredential;

    private Device $b;

    private DeviceCredential $bCredential;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->tenant = Tenant::factory()->create();
        ['device' => $this->a, 'credential' => $this->aCredential] = $this->provisionDevice($this->tenant);
        ['device' => $this->b, 'credential' => $this->bCredential] = $this->provisionDevice($this->tenant);
    }

    #[Test]
    public function without_the_requirement_an_enrolled_member_is_admitted_at_once(): void
    {
        $member = $this->enrolAtA(0, '0771234567');

        $this->assertNotNull(Member::query()->where('uuid', $member)->sole()->approved_at);

        $this->requestOtp('0771234567')->assertJsonPath('valid', true);
    }

    #[Test]
    public function a_member_waiting_for_approval_is_refused_an_otp_and_the_refusal_is_logged(): void
    {
        $this->requireApproval();
        $this->enrolAtA(0, '0771234567');

        $this->requestOtp('0771234567')->assertOk()->assertJsonPath('valid', false);

        $this->assertSame(
            'pending_approval',
            AccessEvent::withoutGlobalScopes()->latest('id')->firstOrFail()->getRawOriginal('reason'),
        );
    }

    #[Test]
    public function a_member_waiting_for_approval_cannot_open_admin_settings(): void
    {
        $this->requireApproval();
        $member = $this->enrolAtA(0, '0771234567');
        Member::query()->where('uuid', $member)->update(['is_admin' => true]);

        $this->postJson('/api/v1/authentications', ['enrollment_id' => 0],
            $this->deviceHeaders($this->a, $this->aCredential))
            ->assertOk()
            ->assertJsonPath('is_admin', false);
    }

    #[Test]
    public function approving_admits_the_member_and_is_audited(): void
    {
        $this->requireApproval();
        $member = $this->enrolAtA(0, '0771234567');

        $this->actingAsOperator('tenant_admin', $this->tenant);

        $this->adminGet("/members/{$member}")
            ->assertOk()
            ->assertJsonPath('data.awaiting_approval', true)
            ->assertJsonPath('data.approved_at', null);

        $this->adminGet('/members', ['awaiting_approval' => 1])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson("/api/admin/v1/members/{$member}/approval")
            ->assertOk()
            ->assertJsonPath('data.awaiting_approval', false);

        // Idempotent, and audited once.
        $this->postJson("/api/admin/v1/members/{$member}/approval")->assertOk();
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'member.approved')->count());

        $this->adminGet('/members', ['awaiting_approval' => 1])->assertJsonCount(0, 'data');

        $this->requestOtp('0771234567')->assertJsonPath('valid', true);
    }

    #[Test]
    public function an_operator_without_member_update_cannot_approve(): void
    {
        $this->requireApproval();
        $member = $this->enrolAtA(0, '0771234567');

        $this->actingAsOperator('auditor', $this->tenant);

        $this->postJson("/api/admin/v1/members/{$member}/approval")->assertForbidden();
        $this->assertNull(Member::query()->where('uuid', $member)->sole()->approved_at);
    }

    #[Test]
    public function the_fingerprint_stays_at_its_own_door_until_approved_then_reaches_the_others(): void
    {
        $this->requireApproval();
        $member = $this->enrolAtA(4, '0771234567', FingerprintTemplateFactory::apiPayload('approval'));

        // Its own panel keeps it: the finger already opens that door.
        $this->assertContains($member, array_column($this->sync($this->a)->json('templates'), 'member_id'));
        // Nobody else gets it yet.
        $this->assertNotContains($member, array_column($this->sync($this->b)->json('templates'), 'member_id'));

        $this->actingAsOperator('tenant_admin', $this->tenant);
        $this->postJson("/api/admin/v1/members/{$member}/approval")->assertOk();

        $this->assertContains($member, array_column($this->sync($this->b)->json('templates'), 'member_id'));
    }

    #[Test]
    public function a_member_added_from_the_dashboard_is_approved_by_whoever_added_them(): void
    {
        $this->requireApproval();
        $operator = $this->actingAsOperator('tenant_admin', $this->tenant);

        $this->postJson('/api/admin/v1/members', ['full_name' => 'Alex Example', 'phone' => '0771234567'])
            ->assertCreated()
            ->assertJsonPath('data.awaiting_approval', false);

        $this->assertSame($operator->id, Member::query()->where('phone', '+94771234567')->sole()->approved_by);
    }

    #[Test]
    public function switching_the_requirement_off_admits_everyone_waiting(): void
    {
        $this->requireApproval();
        $this->enrolAtA(0, '0771234567');
        $this->requestOtp('0771234567')->assertJsonPath('valid', false);

        $this->tenant->forceFill(['settings' => ['members' => ['require_approval' => false]]])->save();

        $this->requestOtp('0771234567')->assertJsonPath('valid', true);
    }

    #[Test]
    public function the_environment_default_applies_when_the_tenant_has_no_override(): void
    {
        config(['access.members.require_approval' => true]);

        $this->enrolAtA(0, '0771234567');

        $this->requestOtp('0771234567')->assertJsonPath('valid', false);
    }

    // ---------------------------------------------------------------------

    private function requireApproval(): void
    {
        $this->tenant->forceFill(['settings' => ['members' => ['require_approval' => true]]])->save();
    }

    /**
     * Both enrolment screens at panel A. Returns the member's uuid.
     *
     * @param  array<string, mixed>|null  $template
     */
    private function enrolAtA(int $slot, string $phone, ?array $template = null): string
    {
        $body = ['device_id' => $this->a->device_id, 'fingerprint_slot' => $slot];

        if ($template !== null) {
            $body['fingerprint_template'] = $template;
        }

        $member = (string) $this->postJson('/api/v1/enrollments', $body,
            $this->deviceHeaders($this->a, $this->aCredential))
            ->assertCreated()
            ->json('member_id');

        $this->patchJson("/api/v1/enrollments/{$member}", ['phone' => $phone],
            $this->deviceHeaders($this->a, $this->aCredential))
            ->assertOk();

        return $member;
    }

    private function requestOtp(string $phone): TestResponse
    {
        // Each device request binds its own tenant; a dashboard session from
        // an earlier step must not leak into it.
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/otp-requests', ['phone' => $phone],
            $this->deviceHeaders($this->a, $this->aCredential))->assertOk();
    }

    private function sync(Device $device): TestResponse
    {
        $credential = $device->is($this->a) ? $this->aCredential : $this->bCredential;

        return $this->getJson('/api/v1/fingerprint-templates?'.http_build_query([
            'device_id' => $device->device_id,
            'capacity' => 200,
            'template_format' => 'as608',
        ]), $this->deviceHeaders($device, $credential))->assertOk();
    }
}
