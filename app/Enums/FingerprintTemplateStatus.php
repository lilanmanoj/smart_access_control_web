<?php

declare(strict_types=1);

namespace App\Enums;

enum FingerprintTemplateStatus: string
{
    /** The copy of what the sensor holds in that slot right now. */
    case Active = 'active';

    /**
     * The panel reused the slot, which it only does once the old template is
     * gone from its sensor. Kept until the slot erase is acknowledged.
     */
    case Superseded = 'superseded';

    /** Erased from the sensor, or its member deleted. The bytes are gone. */
    case Deleted = 'deleted';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
