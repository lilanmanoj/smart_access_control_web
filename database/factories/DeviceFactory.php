<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // The shape the firmware generates on first boot.
            'device_id' => 'SMA_'.fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->streetName().' Door',
            'location' => fake()->secondaryAddress(),
            'status' => DeviceStatus::Active,
            'firmware_version' => '1.4.2',
            'template_capacity' => 200,
            'enrolled_count' => 0,
            'is_online' => true,
            'last_health_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => DeviceStatus::Suspended]);
    }

    public function offline(): static
    {
        return $this->state([
            'is_online' => false,
            'last_health_at' => now()->subHours(2),
        ]);
    }
}
