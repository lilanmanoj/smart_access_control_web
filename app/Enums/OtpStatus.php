<?php

declare(strict_types=1);

namespace App\Enums;

enum OtpStatus: string
{
    case Issued = 'issued';
    case Delivered = 'delivered';
    case Verified = 'verified';
    case Expired = 'expired';
    case Failed = 'failed';

    public function isOpen(): bool
    {
        return $this === self::Issued || $this === self::Delivered;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
