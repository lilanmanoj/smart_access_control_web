<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccessMethod;
use App\Enums\AccessResult;
use App\Enums\DenialReason;
use App\Enums\DeviceStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MemberStatus;
use App\Models\AccessEvent;
use App\Models\Device;
use App\Models\Enrollment;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BackupCodeService;
use App\Services\DeviceCredentialService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A demo tenant with two doors, some members and a fortnight of plausible
 * access history — enough for the dashboard, the filters and the charts to
 * show something real on a fresh install.
 *
 * Also prints working device credentials, because the first thing anyone does
 * after `docker compose up` is try a device call.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'demo'],
            [
                'name' => 'Demo Facility',
                'status' => 'active',
                'settings' => [
                    'backup_codes' => ['online_verification_only' => false],
                    'otp' => ['require_enrollment' => false],
                ],
            ]
        );

        // Everything below is created inside the tenant, so the global scope
        // fills tenant_id and the seeder never has to pass it by hand.
        app(TenantContext::class)->forTenant($tenant, function () use ($tenant): void {
            $this->seedUsers($tenant);

            $frontDoor = $this->seedDevice('SMA_4821', 'Front Entrance', 'Reception lobby');
            $serverRoom = $this->seedDevice('SMA_5093', 'Server Room', 'Second floor, east wing');

            $members = $this->seedMembers();

            $this->seedEnrollments($frontDoor, $members);
            $this->seedHistory([$frontDoor, $serverRoom], $members);
        });
    }

    private function seedUsers(Tenant $tenant): void
    {
        $password = (string) env('DEMO_ADMIN_PASSWORD', 'password');

        $superAdmin = User::query()->firstOrCreate(
            ['email' => (string) env('DEMO_ADMIN_EMAIL', 'admin@example.com')],
            [
                // A SuperAdmin belongs to no tenant; that is what lets them
                // cross between them.
                'tenant_id' => null,
                'name' => 'Platform Administrator',
                'password' => Hash::make($password),
                'status' => 'active',
            ]
        );
        $superAdmin->syncRoles(['super_admin']);

        $operators = [
            ['tenant.admin@example.com', 'Dilani Perera', 'tenant_admin'],
            ['device.manager@example.com', 'Kasun Silva', 'device_manager'],
            ['operator@example.com', 'Front Desk', 'operator'],
            ['auditor@example.com', 'Compliance Review', 'auditor'],
        ];

        foreach ($operators as [$email, $name, $role]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'tenant_id' => $tenant->id,
                    'name' => $name,
                    'password' => Hash::make($password),
                    'status' => 'active',
                ]
            );

            $user->syncRoles([$role]);
        }
    }

    private function seedDevice(string $deviceId, string $name, string $location): Device
    {
        $device = Device::query()->firstOrCreate(
            ['device_id' => $deviceId],
            [
                'name' => $name,
                'location' => $location,
                'status' => DeviceStatus::Active,
                'firmware_version' => '1.4.2',
                'template_capacity' => 200,
                'last_seen_at' => now(),
                'last_health_at' => now(),
                'is_online' => true,
            ]
        );

        if ($device->credentials()->doesntExist()) {
            $issued = app(DeviceCredentialService::class)->issue($device, label: 'Seeded credential');

            // Printed once, here, because there is nowhere else to read it
            // from afterwards.
            $this->command?->newLine();
            $this->command?->info("Device credentials for {$deviceId} ({$name}):");
            $this->command?->line("  X-Device-Id:  {$deviceId}");
            $this->command?->line("  X-API-Key:    {$issued['api_key']}");
            $this->command?->line("  X-API-Secret: {$issued['api_secret']}");
        }

        if ($device->backupCodeSets()->doesntExist()) {
            app(BackupCodeService::class)->rotate($device, 'initial', notifyDevice: false);
        }

        return $device;
    }

    /** @return list<Member> */
    private function seedMembers(): array
    {
        $people = [
            ['Jane Doe', '+94771234567', 'jane@example.com', true],
            ['Nuwan Fernando', '+94712345678', 'nuwan@example.com', false],
            ['Ayesha Karim', '+94763456789', 'ayesha@example.com', false],
            ['Ravi Wickrama', '+94774567890', null, false],
            ['Suspended Contractor', '+94775678901', null, false],
        ];

        $members = [];

        foreach ($people as $index => [$name, $phone, $email, $isAdmin]) {
            $members[] = Member::query()->firstOrCreate(
                ['phone' => $phone],
                [
                    'full_name' => $name,
                    'email' => $email,
                    'is_admin' => $isAdmin,
                    'status' => $index === 4 ? MemberStatus::Suspended : MemberStatus::Active,
                ]
            );
        }

        return $members;
    }

    /** @param  list<Member>  $members */
    private function seedEnrollments(Device $device, array $members): void
    {
        foreach ($members as $slot => $member) {
            Enrollment::query()->firstOrCreate(
                ['device_id' => $device->id, 'member_id' => $member->id],
                [
                    'fingerprint_slot' => $slot,
                    'status' => EnrollmentStatus::Active,
                    'enrolled_at' => now()->subDays(30 - $slot),
                ]
            );
        }

        $device->forceFill(['enrolled_count' => count($members)])->saveQuietly();
    }

    /**
     * A fortnight of history. Written directly rather than through the
     * recorder: the recorder's duplicate suppression and broadcasting are
     * correct for live traffic and unhelpful for backfill.
     *
     * @param  list<Device>  $devices
     * @param  list<Member>  $members
     */
    private function seedHistory(array $devices, array $members): void
    {
        if (AccessEvent::query()->exists()) {
            return;
        }

        $rows = [];
        $now = CarbonImmutable::now();

        for ($day = 13; $day >= 0; $day--) {
            // Weekends are quieter, which makes the trend chart look like a
            // real building rather than a random walk.
            $isWeekend = in_array($now->subDays($day)->isoWeekday(), [6, 7], true);
            $entries = $isWeekend ? random_int(4, 12) : random_int(25, 60);

            // Active members and the suspended one are drawn from separately:
            // a grant attributed to a suspended member would be the system
            // contradicting itself on its own front page.
            $activeMembers = array_values(array_filter(
                $members,
                fn (Member $member): bool => $member->status === MemberStatus::Active,
            ));
            $suspended = array_values(array_filter(
                $members,
                fn (Member $member): bool => $member->status === MemberStatus::Suspended,
            ));

            for ($i = 0; $i < $entries; $i++) {
                $device = $devices[array_rand($devices)];

                $at = $now->subDays($day)
                    ->setTime(random_int(7, 19), random_int(0, 59), random_int(0, 59));

                // Never seed into the future: a door cannot have been opened
                // at 6pm when it is currently 2am, and forward-dated rows
                // confuse both the trend chart and duplicate suppression.
                if ($at->isFuture()) {
                    $at = $now->subMinutes(random_int(1, 600));
                }

                // Roughly one attempt in eight is refused.
                $denied = random_int(1, 8) === 1;

                [$method, $reason] = $denied
                    ? $this->deniedOutcome()
                    : $this->grantedOutcome();

                $member = match (true) {
                    // An unmatched finger belongs to nobody.
                    $reason === DenialReason::NoMatch => null,
                    // Backup codes are device-scoped: they open the door and
                    // identify no one. That is the trade for a method that
                    // keeps working offline, and the log has to show it.
                    $method === AccessMethod::BackupCode => null,
                    // Only a suspended member can be refused for being one.
                    $reason === DenialReason::MemberSuspended => $suspended[0] ?? null,
                    default => $activeMembers[array_rand($activeMembers)],
                };

                $rows[] = [
                    'uuid' => (string) Str::uuid7(),
                    'tenant_id' => $device->tenant_id,
                    'device_id' => $device->id,
                    'member_id' => $member?->id,
                    'method' => $method->value,
                    'result' => $denied ? AccessResult::Denied->value : AccessResult::Granted->value,
                    'reason' => $reason->value,
                    'occurred_at' => $at,
                    'fingerprint_slot' => $method === AccessMethod::Fingerprint ? random_int(0, 4) : null,
                    'confidence' => $method === AccessMethod::Fingerprint ? random_int(80, 200) : null,
                    'idempotency_key' => $device->device_id.'-seed-'.$day.'-'.$i,
                    'created_at' => $at,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            AccessEvent::query()->insert($chunk);
        }

        $this->command?->info('Seeded '.count($rows).' access events across 14 days.');
    }

    /** @return array{AccessMethod, DenialReason} */
    private function grantedOutcome(): array
    {
        return match (random_int(1, 10)) {
            1, 2 => [AccessMethod::Otp, DenialReason::OtpVerified],
            3 => [AccessMethod::BackupCode, DenialReason::CodeAccepted],
            4 => [AccessMethod::AdminAuth, DenialReason::AdminConfirmed],
            default => [AccessMethod::Fingerprint, DenialReason::Matched],
        };
    }

    /** @return array{AccessMethod, DenialReason} */
    private function deniedOutcome(): array
    {
        return match (random_int(1, 6)) {
            1 => [AccessMethod::Otp, DenialReason::OtpExpired],
            2 => [AccessMethod::Otp, DenialReason::OtpMismatch],
            3 => [AccessMethod::BackupCode, DenialReason::CodeInvalid],
            4 => [AccessMethod::AdminAuth, DenialReason::NotAdmin],
            5 => [AccessMethod::Fingerprint, DenialReason::MemberSuspended],
            default => [AccessMethod::Fingerprint, DenialReason::NoMatch],
        };
    }
}
