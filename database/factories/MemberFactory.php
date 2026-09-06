<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Member> */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'full_name' => fake()->name(),
            // E.164, and short enough for the panel's 14-character field.
            'phone' => '+9477'.fake()->unique()->numerify('#######'),
            'email' => fake()->unique()->safeEmail(),
            'status' => MemberStatus::Active,
            'is_admin' => false,
        ];
    }

    public function admin(): static
    {
        return $this->state(['is_admin' => true]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => MemberStatus::Suspended]);
    }
}
