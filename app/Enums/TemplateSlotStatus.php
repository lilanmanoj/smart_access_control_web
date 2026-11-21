<?php

declare(strict_types=1);

namespace App\Enums;

enum TemplateSlotStatus: string
{
    /** Listed to the panel by `GET /fingerprint-templates`. */
    case Active = 'active';

    /**
     * No longer listed, so the panel deletes it at its next sync, but it may
     * still hold the finger. The slot cannot go to anyone else until a sync
     * report shows it empty.
     */
    case Releasing = 'releasing';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
