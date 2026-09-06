import type { ReactNode } from 'react';
import { formatNumber } from '@/lib/format';

/**
 * A headline number.
 *
 * A single current value is a stat tile, not a one-bar bar chart — the number
 * itself is the most legible rendering of one number there is.
 */
export function StatTile({
    label,
    value,
    unit,
    detail,
    tone = 'default',
    href,
}: {
    label: string;
    value: number | string;
    unit?: string;
    detail?: ReactNode;
    /** `alert` is for a number that means someone should do something. */
    tone?: 'default' | 'alert';
    href?: ReactNode;
}) {
    const isAlert = tone === 'alert';

    return (
        <div
            className="panel flex flex-col gap-1 px-4 py-3"
            style={isAlert ? { borderColor: 'var(--danger)' } : undefined}
        >
            <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                {label}
            </p>
            <p
                className="text-2xl leading-none font-semibold tabular-nums"
                style={isAlert ? { color: 'var(--danger-text)' } : undefined}
            >
                {typeof value === 'number' ? formatNumber(value) : value}
                {unit && (
                    <span className="ml-0.5 text-sm font-normal" style={{ color: 'var(--text-muted)' }}>
                        {unit}
                    </span>
                )}
            </p>
            {detail && (
                <p className="text-xs" style={{ color: 'var(--text-subtle)' }}>
                    {detail}
                </p>
            )}
            {href}
        </div>
    );
}
