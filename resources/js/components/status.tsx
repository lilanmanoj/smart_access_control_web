import { Badge } from './ui';
import type { AccessResult, Device, DeviceStatus, EnrollmentStatus, MemberStatus } from '@/types';

/**
 * Status rendering, in one place.
 *
 * Every badge here carries three signals at once — a colour, a shape and a
 * word. During an incident an operator scans this screen fast, and a red dot
 * that means nothing to someone with a red/green deficiency is a safety
 * problem, not a styling preference.
 */

export function ResultBadge({ result, reason }: { result: AccessResult; reason?: string }) {
    return result === 'granted' ? (
        <Badge tone="ok" glyph="✓">
            Granted
        </Badge>
    ) : (
        <Badge tone="danger" glyph="✕">
            {reason ?? 'Denied'}
        </Badge>
    );
}

/**
 * A device has two independent states and conflating them hides real
 * problems: `status` is what an administrator decided, `is_online` is whether
 * the panel is answering right now. A suspended device can still be online,
 * and an active one can be dark.
 */
export function DeviceStateBadge({ device }: { device: Device }) {
    if (device.status !== 'active') {
        return <AdministrativeStateBadge status={device.status} />;
    }

    return device.is_online ? (
        <Badge tone="ok" glyph="●">
            Online
        </Badge>
    ) : (
        <Badge tone="danger" glyph="○">
            Offline
        </Badge>
    );
}

export function AdministrativeStateBadge({ status }: { status: DeviceStatus }) {
    switch (status) {
        case 'active':
            return (
                <Badge tone="ok" glyph="●">
                    Active
                </Badge>
            );
        case 'provisioned':
            return (
                <Badge tone="warn" glyph="◔">
                    Awaiting first contact
                </Badge>
            );
        case 'suspended':
            return (
                <Badge tone="danger" glyph="⏸">
                    Suspended
                </Badge>
            );
        case 'retired':
            return (
                <Badge tone="neutral" glyph="—">
                    Retired
                </Badge>
            );
    }
}

export function MemberStatusBadge({ status, isAdmin }: { status: MemberStatus; isAdmin?: boolean }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            {status === 'active' ? (
                <Badge tone="ok" glyph="✓">
                    Active
                </Badge>
            ) : (
                <Badge tone="danger" glyph="⏸">
                    Suspended
                </Badge>
            )}
            {isAdmin && (
                <Badge tone="accent" glyph="★">
                    Device admin
                </Badge>
            )}
        </span>
    );
}

export function EnrollmentStatusBadge({ status }: { status: EnrollmentStatus }) {
    switch (status) {
        case 'active':
            return (
                <Badge tone="ok" glyph="✓">
                    Active
                </Badge>
            );
        case 'pending':
            return (
                <Badge tone="warn" glyph="◔">
                    Awaiting phone
                </Badge>
            );
        case 'revoked':
            return (
                <Badge tone="neutral" glyph="—">
                    Revoked
                </Badge>
            );
        case 'orphaned':
            // The backend thinks a slot is in use and the device disagrees.
            // Someone has to look at it, so it is styled to be noticed.
            return (
                <Badge tone="danger" glyph="!">
                    Needs reconciliation
                </Badge>
            );
    }
}

export function CommandStatusBadge({ status }: { status: string }) {
    switch (status) {
        case 'acked':
            return (
                <Badge tone="ok" glyph="✓">
                    Acknowledged
                </Badge>
            );
        case 'pending':
            return (
                <Badge tone="warn" glyph="◔">
                    Waiting for device
                </Badge>
            );
        case 'sent':
            return (
                <Badge tone="warn" glyph="→">
                    Delivered, unconfirmed
                </Badge>
            );
        case 'failed':
            return (
                <Badge tone="danger" glyph="✕">
                    Failed
                </Badge>
            );
        default:
            return (
                <Badge tone="neutral" glyph="—">
                    Expired
                </Badge>
            );
    }
}

/**
 * Method labels. `admin_auth` is called out because it is not a way through
 * the door — it is the check that opens the panel's settings portal.
 */
export function MethodLabel({ method, label }: { method: string; label: string }) {
    const glyph =
        method === 'fingerprint'
            ? '☝'
            : method === 'otp'
              ? '✉'
              : method === 'backup_code'
                ? '⌨'
                : method === 'admin_auth'
                  ? '⚙'
                  : '⇱';

    return (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
            <span aria-hidden="true" style={{ color: 'var(--text-subtle)' }}>
                {glyph}
            </span>
            {label}
        </span>
    );
}
