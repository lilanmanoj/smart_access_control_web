<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a member came to hold a slot on a panel (device API 1.2.0).
 */
enum TemplateSlotOrigin: string
{
    /**
     * The member enrolled on this panel, into this slot. The panel chose the
     * slot, so it is authoritative; the backend may hold no bytes for it.
     */
    case EnrolledHere = 'enrolled_here';

    /** The backend placed the member here so the sync writes their template. */
    case Pushed = 'pushed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
