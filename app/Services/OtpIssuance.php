<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OtpRequest;

/**
 * The result of issuing an OTP.
 *
 * The plaintext code is carried here so the delivery job can send it, and so a
 * legacy-firmware deployment can opt into returning it to the device. It is
 * never persisted and never logged.
 */
final class OtpIssuance
{
    public function __construct(
        public readonly OtpRequest $request,
        public readonly string $code,
        public readonly int $expiresIn,
    ) {}
}
