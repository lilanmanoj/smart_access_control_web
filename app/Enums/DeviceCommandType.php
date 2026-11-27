<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Commands the backend can hand to a panel on its next poll.
 *
 * The device cannot receive pushes over HTTPS, so every backend-to-device
 * operation is one of these. Since device API 1.4.0 an MQTT doorbell tells the
 * panel to fetch them early; the command itself still travels over HTTPS.
 */
enum DeviceCommandType: string
{
    /**
     * Erase a fingerprint template from the sensor's flash.
     *
     * @deprecated Since device API 1.4.0 a revoke queues {@see self::SyncTemplates}
     *             instead. Panels on 1.4.0 handle any that are still queued
     *             with a sync pass rather than erasing the slot directly.
     */
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
     * Run one template-sync pass now (device API 1.4.0).
     *
     * Queued whenever something changes what a panel should hold — a member
     * suspended, approved or deleted, an enrolment made or revoked — so the
     * change reaches the sensor in seconds rather than at the next scheduled
     * sync. Carries no payload: the sync itself decides what to write and
     * delete, under its own safety rules.
     */
    case SyncTemplates = 'sync_templates';

    /**
     * Commands that must never fire late: they open a door. These get the
     * short TTL, are listed first, and are delivered at most once.
     */
    public function isTimeSensitive(): bool
    {
        return $this === self::Unlock;
    }

    /** How long a command of this type stays deliverable when queued. */
    public function defaultTtlSeconds(): int
    {
        return match ($this) {
            self::Unlock => (int) config('access.devices.unlock_ttl_seconds', 60),
            self::SyncTemplates => (int) config('access.devices.sync_templates_ttl_seconds', 1800),
            // A panel that was offline for an hour must still get these.
            self::DeleteEnrollment => 86400,
            self::ReportInventory => 3600,
            default => (int) config('access.devices.command_ttl_seconds', 300),
        };
    }

    /** @return list<string> */
    public static function timeSensitiveValues(): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type->isTimeSensitive()),
        ));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
