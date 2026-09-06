<?php

declare(strict_types=1);

namespace App\Services\Sms;

final class SmsResult
{
    private function __construct(
        public readonly bool $delivered,
        public readonly ?string $reference = null,
        public readonly ?string $error = null,
    ) {}

    public static function sent(?string $reference = null): self
    {
        return new self(true, $reference);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->delivered ? 'sent' : 'failed',
            'reference' => $this->reference,
            'error' => $this->error,
            'at' => now()->toIso8601String(),
        ];
    }
}
