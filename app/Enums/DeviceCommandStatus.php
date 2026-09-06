<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceCommandStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Acked = 'acked';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Acked, self::Failed, self::Expired], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
