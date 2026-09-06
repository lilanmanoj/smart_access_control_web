<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EnrollmentStatus;
use App\Models\Device;
use App\Models\Enrollment;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Enrollment> */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'member_id' => Member::factory(),
            'tenant_id' => fn (array $attributes) => Device::find($attributes['device_id'])?->tenant_id,
            'fingerprint_slot' => fake()->unique()->numberBetween(0, 199),
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => EnrollmentStatus::Pending]);
    }

    public function revoked(): static
    {
        return $this->state([
            'status' => EnrollmentStatus::Revoked,
            'fingerprint_slot' => null,
            'revoked_at' => now(),
        ]);
    }
}
