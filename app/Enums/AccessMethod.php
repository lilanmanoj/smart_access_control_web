<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an access attempt was made.
 *
 * `admin_auth` is not a way through the door: it is the fingerprint check that
 * gates the device's own configuration portal. It is logged here anyway,
 * because "who opened the settings screen" belongs in the same trail.
 */
enum AccessMethod: string
{
    case Fingerprint = 'fingerprint';
    case Otp = 'otp';
    case BackupCode = 'backup_code';
    case AdminAuth = 'admin_auth';
    case Remote = 'remote';

    public function label(): string
    {
        return match ($this) {
            self::Fingerprint => 'Fingerprint',
            self::Otp => 'OTP',
            self::BackupCode => 'Backup code',
            self::AdminAuth => 'Admin authentication',
            self::Remote => 'Remote unlock',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
