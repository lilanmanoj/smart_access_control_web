<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessResult: string
{
    case Granted = 'granted';
    case Denied = 'denied';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
