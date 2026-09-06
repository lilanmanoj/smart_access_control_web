<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccessMethod;
use App\Enums\AccessResult;
use App\Enums\DenialReason;
use App\Models\AccessEvent;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccessEvent> */
class AccessEventFactory extends Factory
{
    protected $model = AccessEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'tenant_id' => fn (array $attributes) => Device::find($attributes['device_id'])?->tenant_id,
            'method' => AccessMethod::Fingerprint,
            'result' => AccessResult::Granted,
            'reason' => DenialReason::Matched->value,
            // Server-assigned in production; the factory mirrors that.
            'occurred_at' => now(),
            'fingerprint_slot' => fake()->numberBetween(0, 199),
            'confidence' => fake()->numberBetween(80, 200),
        ];
    }

    public function denied(DenialReason $reason = DenialReason::NoMatch): static
    {
        return $this->state([
            'result' => AccessResult::Denied,
            'reason' => $reason->value,
        ]);
    }
}
