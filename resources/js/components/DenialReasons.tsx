import { formatNumber, humanise } from '@/lib/format';

/**
 * Why entries were refused, most common first.
 *
 * Ranked magnitude with long category names, so: horizontal bars. Every bar is
 * the same colour — the reasons are nominal, and colouring them by their own
 * value would spend the identity channel re-encoding what bar length already
 * shows. The value is direct-labelled, which is the accessible reading of the
 * chart and also the faster one.
 */
export function DenialReasons({
    data,
}: {
    data: { reason: string; label?: string; count: number }[];
}) {
    if (data.length === 0) {
        return (
            <p className="px-4 py-6 text-xs" style={{ color: 'var(--text-muted)' }}>
                No entries were refused in this period.
            </p>
        );
    }

    const max = Math.max(...data.map((row) => row.count));

    return (
        <ul className="flex flex-col gap-2.5 px-4 py-3">
            {data.map((row) => (
                <li key={row.reason} className="flex flex-col gap-1">
                    <div className="flex items-baseline justify-between gap-3 text-xs">
                        <span>{row.label ?? humanise(row.reason)}</span>
                        <span className="tabular-nums" style={{ color: 'var(--text-muted)' }}>
                            {formatNumber(row.count)}
                        </span>
                    </div>
                    <div
                        className="h-1.5 w-full overflow-hidden rounded-full"
                        style={{ background: 'var(--surface-sunken)' }}
                    >
                        <div
                            className="h-full rounded-full"
                            style={{
                                width: `${Math.max(2, (row.count / max) * 100)}%`,
                                background: 'var(--chart-signal)',
                            }}
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}
