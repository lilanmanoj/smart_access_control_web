<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceCredential;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceCredential>
 *
 * Tests need the plaintext secret, which production code deliberately never
 * keeps. `withSecret()` sets a known one.
 */
class DeviceCredentialFactory extends Factory
{
    protected $model = DeviceCredential::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $secret = Str::random(48);

        return [
            'device_id' => Device::factory(),
            'tenant_id' => fn (array $attributes) => Device::find($attributes['device_id'])?->tenant_id,
            'api_key' => 'sak_'.Str::random(32),
            'api_secret_hash' => hash('sha256', $secret),
            'secret_last4' => substr($secret, -4),
            'label' => 'Test credential',
        ];
    }

    public function withSecret(string $secret): static
    {
        return $this->state([
            'api_secret_hash' => hash('sha256', $secret),
            'secret_last4' => substr($secret, -4),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
