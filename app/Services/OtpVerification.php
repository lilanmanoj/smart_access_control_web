<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DenialReason;
use App\Models\OtpRequest;

final class OtpVerification
{
    public function __construct(
        public readonly bool $verified,
        public readonly ?OtpRequest $request,
        public readonly ?DenialReason $reason,
        public readonly int $attemptsLeft,
        public readonly ?string $eventUuid,
    ) {}
}
