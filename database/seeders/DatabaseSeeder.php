<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Roles first: the demo users are assigned roles that must exist.
            RolesAndPermissionsSeeder::class,
            DemoTenantSeeder::class,
        ]);
    }
}
