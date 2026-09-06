<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Commands the backend can hand to a panel on its next poll.
 *
 * The device cannot receive pushes, so every backend-to-device operation is
 * one of these.
 */
enum DeviceCommandType: string
{
    /** Erase a fingerprint template from the sensor's flash. */
    case DeleteEnrollment = 'delete_enrollment';

    /** Tell the device to refetch its backup code set. */
    case RefreshBackupCodes = 'refresh_backup_codes';

    /** Open the door for the configured lock time. */
    case Unlock = 'unlock';

    case Reboot = 'reboot';

    /** Push changed device settings (§9.3, remote configuration). */
    case UpdateSettings = 'update_settings';

    /** Ask the device for its occupied-slot inventory (§9.4). */
    case ReportInventory = 'report_inventory';

    /**
     * Commands that must never fire late: they open a door or change who may.
     * These expire aggressively if the device did not collect them.
     */
    public function isTimeSensitive(): bool
    {
        return $this === self::Unlock;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
