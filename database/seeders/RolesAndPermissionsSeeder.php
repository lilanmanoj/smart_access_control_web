<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The permission vocabulary and the roles built from it.
 *
 * Permissions are granular and checked by name in policies. Roles are only
 * bundles of them — no code branches on a role string, so defining a new role
 * never requires touching an authorisation check.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'tenant.manage',

        'device.view', 'device.create', 'device.update', 'device.delete',
        'device.unlock', 'device.command',

        'member.view', 'member.create', 'member.update', 'member.delete',

        'enrollment.view', 'enrollment.revoke',

        'backupcode.view', 'backupcode.rotate',

        'event.view', 'event.export',

        'user.view', 'user.invite', 'user.update', 'user.delete',

        'role.manage', 'audit.view',

        // Beyond the required minimum: scheduled access (§9.7) and outbound
        // webhooks (§9.8) both need a gate of their own.
        'schedule.view', 'schedule.manage',
        'webhook.manage',
    ];

    /** @var array<string, list<string>|string> */
    private const ROLES = [
        // Cross-tenant. Also passes Gate::before, but the grants are listed so
        // the permission matrix in the dashboard shows something meaningful.
        'super_admin' => '*',

        'tenant_admin' => '*except:tenant.manage',

        // Devices, enrolments and backup codes — but not who may log in.
        'device_manager' => [
            'device.view', 'device.create', 'device.update', 'device.delete',
            'device.unlock', 'device.command',
            'member.view', 'member.create', 'member.update', 'member.delete',
            'enrollment.view', 'enrollment.revoke',
            'backupcode.view', 'backupcode.rotate',
            'event.view', 'event.export',
            'schedule.view', 'schedule.manage',
        ],

        // The front desk: watch the doors, let someone in, look things up.
        'operator' => [
            'device.view', 'device.unlock',
            'member.view',
            'enrollment.view',
            'event.view',
            'schedule.view',
        ],

        // Read-only, including the logs. No mutation anywhere.
        'auditor' => [
            'device.view', 'member.view', 'enrollment.view',
            'backupcode.view', 'event.view', 'event.export',
            'user.view', 'audit.view', 'schedule.view',
        ],
    ];

    public function run(): void
    {
        // The permission cache is keyed globally; a stale one makes freshly
        // seeded grants look missing.
        Artisan::call('permission:cache-reset');

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (self::ROLES as $name => $grants) {
            $role = Role::findOrCreate($name, 'web');

            $role->syncPermissions($this->resolveGrants($grants));
        }

        Artisan::call('permission:cache-reset');
    }

    /**
     * @param  list<string>|string  $grants
     * @return list<string>
     */
    private function resolveGrants(array|string $grants): array
    {
        if ($grants === '*') {
            return self::PERMISSIONS;
        }

        if (is_string($grants) && str_starts_with($grants, '*except:')) {
            $excluded = explode(',', substr($grants, strlen('*except:')));

            return array_values(array_diff(self::PERMISSIONS, $excluded));
        }

        return (array) $grants;
    }
}
