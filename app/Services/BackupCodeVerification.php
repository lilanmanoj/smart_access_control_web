<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DenialReason;
use App\Models\BackupCode;

final class BackupCodeVerification
{
    public function __construct(
        public readonly bool $accepted,
        public readonly ?BackupCode $code,
        public readonly DenialReason $reason,
    ) {}
}
