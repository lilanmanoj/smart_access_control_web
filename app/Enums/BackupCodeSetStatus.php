<?php

declare(strict_types=1);

namespace App\Enums;

enum BackupCodeSetStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
