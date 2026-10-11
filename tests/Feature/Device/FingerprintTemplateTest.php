<?php

declare(strict_types=1);

namespace Tests\Feature\Device;

use App\Enums\DeviceCommandType;
use App\Enums\EnrollmentStatus;
use App\Enums\FingerprintTemplateStatus;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\MemberResource;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceCredential;
use App\Models\Enrollment;
use App\Models\FingerprintTemplate;
use App\Models\Member;
use App\Services\EnrollmentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Templates sent with an enrolment (device API 1.1.0).
 *
 * The rule every test here circles back to: a bad template never costs a
 * member their enrolment, and a good one is never handed back out.
 */
class FingerprintTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** 512 synthetic bytes — not a real fingerprint — from the contract's example. */
    private const FIXTURE_DATA = 'cHtQC745+ZkdHZBKal34jgOajekuHEcWCmm56bjUgm5VAhJOIEoHQ5PWSCYtZ/nwzKPeN8nmCZlmwCLahBjhflWxEXKQbAhkouKGCfRZiaIXhJjRzlhX+HY0qZ14kUyuXfF6Klwqp2Qm+OAUGQfoeux0raospT7DFXDuS8N5oDvEG7uJTukic/csxeneGKDqBFym3dvp7x13ViqXv+XMCdioswF8p5BWcLHCxZ3AFrXzfvyln2eB51acgW9S0zO4rlYV7I0FDMFQegRvFH9MRRRwQVpnBSBpetkvflKb0eaUY//hE/0HjpO9MNtJAecP/5COM+1lvrIRwmNnlIY05Bsssy/XDTrohQs3ND8n3pp95pcoKpKVbHs7DKwelupdjn4t/QtCRinNmST0Dzi4yvrHjVTA8Bt++NKvQlHKHnCHZq64smZavRKueNYbHvu/D7ggpK1HlmPCkRg24l7EM6EkRSDoLvMoU2jeiO+wRqNt2b1L0wHaPYL+cj81rwnCBxIlLz139rlMhjYoPY3NHyoBPYxjszer/f8YtpcMvDw/ocaTjTGKWwzun1d12X6RTXB0faCCtic2YMZNmX7bFHKzZU+tnw8DGK4Ha7ChU5diQdu/99/YYMHf4KFplKm2KVicXkgRf2qhTxlS2u56hl9BQQqftv7rvs+CaE7aAWY=';

    private const FIXTURE_SHA256 = '1c7fb1b3a4be3a63e8e9a81e871ff1a1e40c237f773a905bb5f5cd91e604a87f';

    private Device $device;

    private DeviceCredential $credential;

    protected function setUp(): void
    {
        parent::setUp();

        ['device' => $this->device, 'credential' => $this->credential] = $this->provisionDevice();
    }

    // ---------------------------------------------------------------------
    // Storing (spec §7: 1, 2, 12)
    // ---------------------------------------------------------------------

    #[Test]
    public function it_stores_a_valid_template_encrypted(): void
    {
        $this->enrol(7, $this->template())
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('template_status', 'stored')
            ->assertJsonMissingPath('template_error');

        $template = FingerprintTemplate::query()->sole();

        $this->assertSame(FingerprintTemplateStatus::Active, $template->status);
        $this->assertSame($this->device->id, $template->device_id);
        $this->assertSame(7, $template->fingerprint_slot);
        $this->assertSame('as608', $template->format);
        $this->assertSame(512, $template->size);
        $this->assertSame(self::FIXTURE_SHA256, $template->sha256);
        $this->assertSame(base64_decode(self::FIXTURE_DATA), $template->data_encrypted);

        // Ciphertext at rest: neither the bytes nor their base64 are in the row.
        $raw = (string) DB::table('fingerprint_templates')->value('data_encrypted');
        $this->assertStringNotContainsString(self::FIXTURE_DATA, $raw);
        $this->assertStringNotContainsString(base64_decode(self::FIXTURE_DATA), $raw);
    }

    #[Test]
    public function an_enrollment_without_a_template_reports_it_absent(): void
    {
        $this->enrol(7)
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('template_status', 'absent');

        $this->assertDatabaseCount('fingerprint_templates', 0);
    }

    #[Test]
    public function a_phone_only_enrollment_reports_the_template_absent(): void
    {
        $this->enrol(null)
            ->assertCreated()
            ->assertJsonPath('template_status', 'absent');
    }

    /** Newer firmware may send more; nothing in the body is strict. */
    #[Test]
    public function unknown_properties_are_ignored(): void
    {
        $this->postJson('/api/v1/enrollments', [
            'device_id' => $this->device->device_id,
            'fingerprint_slot' => 7,
            'firmware_extra' => 'whatever',
            'fingerprint_template' => $this->template(['quality' => 87, 'captured_with' => 'v2']),
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('template_status', 'stored');
    }

    // ---------------------------------------------------------------------
    // Rejection (spec §7: 3–8)
    // ---------------------------------------------------------------------

    /** @return array<string, array{0: int|null, 1: array<string, mixed>, 2: string}> */
    public static function rejections(): array
    {
        $sha = self::FIXTURE_SHA256;
        $flipped = ($sha[0] === '1' ? '2' : '1').substr($sha, 1);

        return [
            'no slot' => [null, [], 'slot_missing'],
            'unknown format' => [7, ['format' => 'r503'], 'unsupported_format'],
            'missing format' => [7, ['format' => null], 'unsupported_format'],
            'unknown encoding' => [7, ['encoding' => 'hex'], 'invalid_encoding'],
            'not base64' => [7, ['data' => 'not*base64!'], 'invalid_encoding'],
            'missing padding' => [7, ['data' => rtrim(self::FIXTURE_DATA, '=')], 'invalid_encoding'],
            'embedded whitespace' => [7, ['data' => substr(self::FIXTURE_DATA, 0, 8)."\n".substr(self::FIXTURE_DATA, 8)], 'invalid_encoding'],
            'missing data' => [7, ['data' => null], 'invalid_encoding'],
            'size one short' => [7, ['size' => 511], 'size_mismatch'],
            'size over the limit' => [7, ['size' => 1025], 'size_mismatch'],
            'size as a string' => [7, ['size' => '512'], 'size_mismatch'],
            'one hex digit changed' => [7, ['sha256' => $flipped], 'sha256_mismatch'],
            'uppercase hash' => [7, ['sha256' => strtoupper($sha)], 'sha256_mismatch'],
            'missing hash' => [7, ['sha256' => null], 'sha256_mismatch'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('rejections')]
    public function a_bad_template_is_rejected_but_the_enrollment_is_kept(?int $slot, array $override, string $error): void
    {
        $response = $this->enrol($slot, $this->template($override))
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('template_status', 'rejected')
            ->assertJsonPath('template_error', $error);

        $this->assertDatabaseHas('members', ['uuid' => $response->json('member_id')]);
        $this->assertDatabaseHas('enrollments', [
            'uuid' => $response->json('enrollment_id'),
            'fingerprint_slot' => $slot,
            'status' => EnrollmentStatus::Pending->value,
        ]);
        $this->assertDatabaseCount('fingerprint_templates', 0);
    }

    #[Test]
    public function a_template_that_is_not_an_object_is_rejected(): void
    {
        $this->postJson('/api/v1/enrollments', [
            'fingerprint_slot' => 7,
            'fingerprint_template' => 'garbage',
        ], $this->headers())
            ->assertCreated()
            ->assertJsonPath('template_status', 'rejected')
            ->assertJsonPath('template_error', 'unsupported_format');
    }

    /** The existing fields keep their 422; only the template is lenient. */
    #[Test]
    public function a_bad_slot_is_still_a_422(): void
    {
        $this->enrol(-1, $this->template())->assertStatus(422);

        $this->assertDatabaseCount('enrollments', 0);
    }

    #[Test]
    public function a_rejection_is_logged_without_the_data(): void
    {
        Log::spy();

        $this->enrol(7, $this->template(['size' => 511]))->assertCreated();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $context === [
                    'device_id' => $this->device->device_id,
                    'fingerprint_slot' => 7,
                    'template_error' => 'size_mismatch',
                ];
            })
            ->once();
    }

    // ---------------------------------------------------------------------
    // Lifecycle (spec §7: 9, 10)
    // ---------------------------------------------------------------------

    #[Test]
    public function a_second_template_for_the_slot_supersedes_the_first(): void
    {
        $this->enrol(7, $this->template())->assertJsonPath('template_status', 'stored');
        $first = FingerprintTemplate::query()->sole();

        [$bytes, $sha] = $this->otherTemplate();
        $this->enrol(7, $this->template(['data' => base64_encode($bytes), 'sha256' => $sha]))
            ->assertJsonPath('template_status', 'stored');

        $this->assertSame(FingerprintTemplateStatus::Superseded, $first->fresh()->status);

        $current = FingerprintTemplate::query()->active()->sole();
        $this->assertSame($sha, $current->sha256);
        $this->assertNotSame($first->id, $current->id);
    }

    /** The sensor overwrote the slot, so our copy of the old one is stale. */
    #[Test]
    public function re_enrolling_a_slot_without_a_template_still_supersedes(): void
    {
        $this->enrol(7, $this->template());
        $this->enrol(7)->assertJsonPath('template_status', 'absent');

        $this->assertSame(0, FingerprintTemplate::query()->active()->count());
        $this->assertSame(FingerprintTemplateStatus::Superseded, FingerprintTemplate::query()->sole()->status);
    }

    #[Test]
    public function the_database_refuses_two_active_templates_for_one_slot(): void
    {
        $this->enrol(7, $this->template());
        $existing = FingerprintTemplate::query()->sole();

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        FingerprintTemplate::create([
            ...$existing->only(['tenant_id', 'member_id', 'enrollment_id', 'device_id', 'fingerprint_slot', 'format', 'size', 'sha256']),
            'data_encrypted' => 'x',
            'status' => FingerprintTemplateStatus::Active,
        ]);
    }

    #[Test]
    public function an_acknowledged_slot_erase_deletes_the_template(): void
    {
        $command = $this->enrolAndRevoke(7);

        $this->acknowledge($command, 'acked');

        $template = FingerprintTemplate::query()->sole();
        $this->assertSame(FingerprintTemplateStatus::Deleted, $template->status);
        $this->assertNull($template->data_encrypted);
        $this->assertNull(DB::table('fingerprint_templates')->value('data_encrypted'));
    }

    #[Test]
    public function a_failed_slot_erase_keeps_the_template(): void
    {
        $command = $this->enrolAndRevoke(7);

        $this->acknowledge($command, 'failed');

        $this->assertSame(FingerprintTemplateStatus::Active, FingerprintTemplate::query()->sole()->status);
    }

    /**
     * The panel can erase the slot, enrol someone new into it and post that
     * enrolment before the acknowledgement reaches us. The acknowledgement is
     * about the old template only.
     */
    #[Test]
    public function a_late_acknowledgement_does_not_erase_the_slots_new_template(): void
    {
        $command = $this->enrolAndRevoke(7);
        $old = FingerprintTemplate::query()->sole();

        [$bytes, $sha] = $this->otherTemplate();
        $this->enrol(7, $this->template(['data' => base64_encode($bytes), 'sha256' => $sha]));

        $this->acknowledge($command, 'acked');

        $this->assertSame(FingerprintTemplateStatus::Deleted, $old->fresh()->status);
        $this->assertNull($old->fresh()->data_encrypted);

        $new = FingerprintTemplate::query()->active()->sole();
        $this->assertSame($sha, $new->sha256);
        $this->assertSame($bytes, $new->data_encrypted);
    }

    #[Test]
    public function deleting_a_member_erases_their_templates(): void
    {
        $memberId = $this->enrol(7, $this->template())->json('member_id');

        Member::query()->where('uuid', $memberId)->sole()->delete();

        $template = FingerprintTemplate::query()->sole();
        $this->assertSame(FingerprintTemplateStatus::Deleted, $template->status);
        $this->assertNull($template->data_encrypted);
    }

    #[Test]
    public function hard_deleting_an_enrollment_cascades_to_its_templates(): void
    {
        $enrollmentId = $this->enrol(7, $this->template())->json('enrollment_id');

        DB::table('enrollments')->where('uuid', $enrollmentId)->delete();

        $this->assertDatabaseCount('fingerprint_templates', 0);
    }

    // ---------------------------------------------------------------------
    // Audit
    // ---------------------------------------------------------------------

    #[Test]
    public function storing_superseding_and_deleting_are_audited_with_the_device_as_actor(): void
    {
        $this->enrol(7, $this->template());

        [$bytes, $sha] = $this->otherTemplate();
        $this->enrol(7, $this->template(['data' => base64_encode($bytes), 'sha256' => $sha]));

        // The erase covers the slot's superseded copy as well as the revoked one.
        $command = $this->revokeSlot(7);
        $this->acknowledge($command, 'acked');

        $actions = AuditLog::query()->where('action', 'like', 'fingerprint_template.%')->orderBy('id')->get();

        $this->assertSame([
            'fingerprint_template.stored',
            'fingerprint_template.superseded',
            'fingerprint_template.stored',
            'fingerprint_template.deleted',
            'fingerprint_template.deleted',
        ], $actions->pluck('action')->all());

        foreach ($actions as $entry) {
            $this->assertSame($this->device->id, $entry->device_id);
            $this->assertNull($entry->user_id);
            $this->assertSame($this->device->tenant_id, $entry->tenant_id);
            $this->assertArrayNotHasKey('data_encrypted', $entry->after ?? []);
        }

        $this->assertStringNotContainsString(
            self::FIXTURE_DATA,
            (string) DB::table('audit_logs')->get()->toJson(),
        );
    }

    // ---------------------------------------------------------------------
    // Never handed back out (spec §7: 11)
    // ---------------------------------------------------------------------

    #[Test]
    public function serializers_never_expose_the_template(): void
    {
        $memberId = $this->enrol(7, $this->template())->json('member_id');
        app(TenantContext::class)->set($this->device->tenant);

        $template = FingerprintTemplate::query()->sole();
        $member = Member::query()->where('uuid', $memberId)->sole()
            ->load(['enrollments.device', 'fingerprintTemplates']);
        $enrollment = Enrollment::query()->sole()->load(['device', 'member']);

        $serialised = [
            json_encode($template->toArray()),
            $template->toJson(),
            $member->toJson(),
            $enrollment->toJson(),
            (string) (new MemberResource($member))->response()->getContent(),
            (string) (new EnrollmentResource($enrollment))->response()->getContent(),
        ];

        foreach ($serialised as $json) {
            $this->assertStringNotContainsString('data_encrypted', $json);
            $this->assertStringNotContainsString(self::FIXTURE_DATA, $json);
            $this->assertStringNotContainsString(
                (string) DB::table('fingerprint_templates')->value('data_encrypted'),
                $json,
            );
        }
    }

    #[Test]
    public function the_dashboard_api_never_exposes_the_template(): void
    {
        $memberId = $this->enrol(7, $this->template())->json('member_id');
        $ciphertext = (string) DB::table('fingerprint_templates')->value('data_encrypted');

        $this->actingAsOperator('tenant_admin', $this->device->tenant);

        foreach (["/members/{$memberId}", '/members', '/enrollments', '/audit-logs'] as $path) {
            $body = $this->adminGet($path)->assertOk()->getContent();

            $this->assertStringNotContainsString(self::FIXTURE_DATA, $body, $path);
            $this->assertStringNotContainsString($ciphertext, $body, $path);
        }
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $template
     */
    private function enrol(?int $slot, ?array $template = null): \Illuminate\Testing\TestResponse
    {
        $body = ['device_id' => $this->device->device_id, 'fingerprint_slot' => $slot];

        if ($template !== null) {
            $body['fingerprint_template'] = $template;
        }

        return $this->postJson('/api/v1/enrollments', $body, $this->headers());
    }

    /**
     * The contract's fixture, with fields overridden; a null override removes
     * the field.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function template(array $override = []): array
    {
        $template = array_merge([
            'format' => 'as608',
            'encoding' => 'base64',
            'size' => 512,
            'sha256' => self::FIXTURE_SHA256,
            'data' => self::FIXTURE_DATA,
        ], $override);

        return array_filter($template, fn ($value): bool => $value !== null);
    }

    /** @return array{0: string, 1: string} A second valid 512-byte template and its hash. */
    private function otherTemplate(): array
    {
        $bytes = str_repeat("\xA5\x5A", 256);

        return [$bytes, hash('sha256', $bytes)];
    }

    private function enrolAndRevoke(int $slot): DeviceCommand
    {
        $this->enrol($slot, $this->template())->assertJsonPath('template_status', 'stored');

        return $this->revokeSlot($slot);
    }

    /** Revoke whoever holds the slot, as the dashboard would, and return the erase command. */
    private function revokeSlot(int $slot): DeviceCommand
    {
        app(TenantContext::class)->set($this->device->tenant);

        $enrollment = Enrollment::query()
            ->where('device_id', $this->device->id)
            ->holdingSlot()
            ->where('fingerprint_slot', $slot)
            ->sole();

        app(EnrollmentService::class)->revoke($enrollment);

        app(TenantContext::class)->forget();

        return DeviceCommand::query()
            ->where('type', DeviceCommandType::DeleteEnrollment->value)
            ->latest('id')
            ->firstOrFail();
    }

    private function acknowledge(DeviceCommand $command, string $status): void
    {
        $this->postJson("/api/v1/device-commands/{$command->uuid}/acknowledgements",
            ['status' => $status], $this->headers())
            ->assertOk();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->deviceHeaders($this->device, $this->credential);
    }
}
