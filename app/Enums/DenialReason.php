<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Machine-readable outcome codes for access_events.reason.
 *
 * These answer "why were they refused" without parsing prose, and they are the
 * vocabulary the dashboard filters and the webhook payloads use.
 */
enum DenialReason: string
{
    // Granted outcomes
    case Matched = 'matched';
    case RemoteUnlock = 'remote_unlock';
    case OtpVerified = 'otp_verified';
    case CodeAccepted = 'code_accepted';
    case AdminConfirmed = 'admin_confirmed';

    // Denied outcomes
    case NoMatch = 'no_match';
    case NotEnrolled = 'not_enrolled';
    case MemberSuspended = 'member_suspended';
    case OtpExpired = 'otp_expired';
    case OtpMismatch = 'otp_mismatch';
    case CodeInvalid = 'code_invalid';
    case CodeExpired = 'code_expired';
    case NotAdmin = 'not_admin';
    case BackendUnreachable = 'backend_unreachable';
    case RateLimited = 'rate_limited';
    case OutsideSchedule = 'outside_schedule';
    case DeviceSuspended = 'device_suspended';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Fingerprint matched',
            self::RemoteUnlock => 'Unlocked remotely',
            self::OtpVerified => 'OTP verified',
            self::CodeAccepted => 'Backup code accepted',
            self::AdminConfirmed => 'Administrator confirmed',
            self::NoMatch => 'No fingerprint match',
            self::NotEnrolled => 'Slot not enrolled',
            self::MemberSuspended => 'Member suspended',
            self::OtpExpired => 'OTP expired',
            self::OtpMismatch => 'Wrong OTP',
            self::CodeInvalid => 'Invalid backup code',
            self::CodeExpired => 'Backup code superseded',
            self::NotAdmin => 'Not an administrator',
            self::BackendUnreachable => 'Backend unreachable',
            self::RateLimited => 'Rate limited',
            self::OutsideSchedule => 'Outside permitted hours',
            self::DeviceSuspended => 'Device suspended',
            self::Duplicate => 'Duplicate suppressed',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
