<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FingerprintTemplateStatus;
use App\Models\Enrollment;
use App\Models\FingerprintTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic AS608 templates.
 *
 * The bytes are a SHA-256 chain over a seed, not a fingerprint, and no sensor
 * would match anything against them. They have the real size and a correct
 * hash, so they pass the same validation a panel's upload does.
 *
 * @extends Factory<FingerprintTemplate>
 */
class FingerprintTemplateFactory extends Factory
{
    protected $model = FingerprintTemplate::class;

    /** A genuine AS608 template is always this long. */
    public const AS608_SIZE = 512;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $bytes = self::syntheticBytes(fake()->uuid());

        return [
            'enrollment_id' => Enrollment::factory(),
            'tenant_id' => fn (array $attributes) => Enrollment::find($attributes['enrollment_id'])?->tenant_id,
            'member_id' => fn (array $attributes) => Enrollment::find($attributes['enrollment_id'])?->member_id,
            'device_id' => fn (array $attributes) => Enrollment::find($attributes['enrollment_id'])?->device_id,
            'fingerprint_slot' => fn (array $attributes) => Enrollment::find($attributes['enrollment_id'])?->fingerprint_slot,
            'format' => 'as608',
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'data_encrypted' => $bytes,
            'status' => FingerprintTemplateStatus::Active,
        ];
    }

    /** Belonging to an existing enrolment, in its slot. */
    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state([
            'enrollment_id' => $enrollment->id,
            'tenant_id' => $enrollment->tenant_id,
            'member_id' => $enrollment->member_id,
            'device_id' => $enrollment->device_id,
            'fingerprint_slot' => $enrollment->fingerprint_slot,
        ]);
    }

    /** The same seed always gives the same bytes, so reseeding is stable. */
    public function seeded(string $seed): static
    {
        $bytes = self::syntheticBytes($seed);

        return $this->state([
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'data_encrypted' => $bytes,
        ]);
    }

    public function superseded(): static
    {
        return $this->state(['status' => FingerprintTemplateStatus::Superseded]);
    }

    public function deleted(): static
    {
        return $this->state([
            'status' => FingerprintTemplateStatus::Deleted,
            'data_encrypted' => null,
        ]);
    }

    /**
     * Deterministic, obviously-not-biometric template bytes.
     */
    public static function syntheticBytes(string $seed, int $size = self::AS608_SIZE): string
    {
        $bytes = '';

        for ($block = 0; strlen($bytes) < $size; $block++) {
            $bytes .= hash('sha256', "synthetic-as608:{$seed}:{$block}", true);
        }

        return substr($bytes, 0, $size);
    }

    /**
     * A `fingerprint_template` object exactly as a panel sends it on
     * `POST /enrollments` — for tests and for trying the API by hand.
     *
     * @return array{format: string, encoding: string, size: int, sha256: string, data: string}
     */
    public static function apiPayload(string $seed = 'example'): array
    {
        $bytes = self::syntheticBytes($seed);

        return [
            'format' => 'as608',
            'encoding' => 'base64',
            'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'data' => base64_encode($bytes),
        ];
    }
}
