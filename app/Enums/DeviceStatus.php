<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceStatus: string
{
    /** Adopted but not yet talking to the backend. */
    case Provisioned = 'provisioned';

    case Active = 'active';
    case Suspended = 'suspended';
    case Retired = 'retired';

    /** Only an active device may authenticate. */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
