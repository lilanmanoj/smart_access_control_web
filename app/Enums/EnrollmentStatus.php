<?php

declare(strict_types=1);

namespace App\Enums;

enum EnrollmentStatus: string
{
    /** Created from a stored template, awaiting a phone number. */
    case Pending = 'pending';

    case Active = 'active';
    case Revoked = 'revoked';

    /**
     * The backend believes a slot is in use but the device disagrees — a
     * failed rollback, a sensor swap, a manual erase. Surfaced for an operator
     * to resolve rather than guessed at.
     */
    case Orphaned = 'orphaned';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
