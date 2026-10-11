<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FingerprintTemplateError;

/**
 * The verdict on the `fingerprint_template` a panel sent with an enrolment.
 *
 * Either there was none, it was discarded for a reason the panel is told, or
 * it passed and carries the decoded bytes to be stored.
 */
final readonly class FingerprintTemplateIntake
{
    private ?string $bytes;

    private function __construct(
        public ?FingerprintTemplateError $error,
        public ?string $format = null,
        public ?string $sha256 = null,
        #[\SensitiveParameter] ?string $bytes = null,
    ) {
        $this->bytes = $bytes;
    }

    public static function absent(): self
    {
        return new self(null);
    }

    public static function rejected(FingerprintTemplateError $error): self
    {
        return new self($error);
    }

    public static function accepted(string $format, string $sha256, #[\SensitiveParameter] string $bytes): self
    {
        return new self(null, $format, $sha256, $bytes);
    }

    public function isAccepted(): bool
    {
        return $this->bytes !== null;
    }

    public function bytes(): string
    {
        return $this->bytes ?? throw new \LogicException('No template was accepted.');
    }

    /**
     * The fields this adds to the `POST /enrollments` response.
     *
     * @return array{template_status: string, template_error?: string}
     */
    public function toResponse(): array
    {
        return match (true) {
            $this->isAccepted() => ['template_status' => 'stored'],
            $this->error !== null => ['template_status' => 'rejected', 'template_error' => $this->error->value],
            default => ['template_status' => 'absent'],
        };
    }

    /**
     * Keeps the bytes out of dump(), dd() and anything else that introspects.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'error' => $this->error,
            'format' => $this->format,
            'sha256' => $this->sha256,
            'bytes' => $this->bytes === null ? null : '[redacted]',
        ];
    }
}
