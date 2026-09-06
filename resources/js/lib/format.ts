/**
 * Formatting helpers.
 *
 * Timestamps from the API are always UTC ISO-8601 and always server-assigned.
 * They are rendered in the operator's own timezone, because "when did that
 * door open" is a question about their day, not the server's.
 */

const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const timeOnly = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
});

const dayOnly = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
});

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) return '—';

    return dateTime.format(new Date(iso));
}

export function formatTime(iso: string | null | undefined): string {
    if (!iso) return '—';

    return timeOnly.format(new Date(iso));
}

export function formatDay(iso: string | null | undefined): string {
    if (!iso) return '—';

    return dayOnly.format(new Date(iso));
}

/**
 * "3 minutes ago". Used for last-seen values, where the exact instant matters
 * less than how stale it is.
 */
export function formatRelative(iso: string | null | undefined): string {
    if (!iso) return 'never';

    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 10) return 'just now';
    if (seconds < 60) return `${seconds}s ago`;

    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours}h ago`;

    return `${Math.round(hours / 24)}d ago`;
}

export function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}

const WEEKDAYS = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

/** ISO-8601 weekday: 1 = Monday. */
export function weekdayName(weekday: number): string {
    return WEEKDAYS[weekday - 1] ?? 'Unknown';
}

/** `member_suspended` -> `Member suspended`, as a last resort. */
export function humanise(value: string): string {
    const spaced = value.replace(/[_.]/g, ' ');

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}
