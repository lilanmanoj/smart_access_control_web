import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { formatDay, formatNumber } from '@/lib/format';

interface Point {
    date: string;
    granted: number;
    denied: number;
}

/**
 * Daily entry attempts, split by outcome.
 *
 * Two decisions worth stating, because both look like taste and are not:
 *
 * **Emphasis, not two categories.** Granted and denied are not two equal
 * series competing for attention — the denials are the whole reason an
 * operator looks at this. So denials carry the one saturated colour and grants
 * recede to grey. The alternative, green-against-red, measures ΔE 5.1 under
 * simulated deuteranopia: for a reader with the most common form of colour
 * blindness those two bars are the same bar. Grey against red measures 17.1.
 *
 * **Stacked, not grouped.** Granted plus denied is every attempt at that door
 * that day, so the stack height is a real quantity rather than an artefact of
 * putting two bars next to each other.
 */
export function TrendChart({ data, days }: { data: Point[]; days: number }) {
    const [hovered, setHovered] = useState<number | null>(null);
    const [showTable, setShowTable] = useState(false);
    const titleId = useId();

    const max = useMemo(
        () => Math.max(1, ...data.map((point) => point.granted + point.denied)),
        [data],
    );

    const totals = useMemo(
        () =>
            data.reduce(
                (sum, point) => ({
                    granted: sum.granted + point.granted,
                    denied: sum.denied + point.denied,
                }),
                { granted: 0, denied: 0 },
            ),
        [data],
    );

    // The geometry is computed against the container's real width rather than
    // a fixed viewBox. A fixed one either letterboxes (uniform scaling leaves
    // the plot floating in the middle of a wide panel) or distorts the bar
    // radii (preserveAspectRatio="none"). Measuring avoids both.
    const containerRef = useRef<HTMLDivElement>(null);
    const [measured, setMeasured] = useState(720);

    useEffect(() => {
        const element = containerRef.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(([entry]) => {
            if (entry) {
                setMeasured(Math.max(320, Math.round(entry.contentRect.width)));
            }
        });

        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    const width = measured;
    const height = 190;
    const padding = { top: 12, right: 8, bottom: 26, left: 34 };
    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;

    const slot = plotWidth / Math.max(1, data.length);
    // Capped so a fortnight on a wide screen reads as columns, not slabs.
    const barWidth = Math.min(28, slot * 0.6);

    const y = (value: number) => padding.top + plotHeight - (value / max) * plotHeight;

    // Four gridlines is enough to read a magnitude off and few enough to stay
    // recessive.
    const ticks = [0, 0.25, 0.5, 0.75, 1].map((fraction) => Math.round(max * fraction));

    const active = hovered === null ? null : data[hovered];

    return (
        <figure className="m-0 flex flex-col gap-3">
            <figcaption className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h3 id={titleId} className="text-sm font-semibold">
                        Entry attempts
                    </h3>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        Last {days} days · {formatNumber(totals.granted + totals.denied)} attempts,{' '}
                        <strong style={{ color: 'var(--danger-text)' }}>
                            {formatNumber(totals.denied)} refused
                        </strong>
                    </p>
                </div>

                {/* Two series, so a legend is always present — identity is never
                    left to colour alone. */}
                <div className="flex items-center gap-3 text-xs">
                    <LegendSwatch color="var(--chart-signal)" label="Denied" />
                    <LegendSwatch color="var(--chart-context)" label="Granted" />
                    <button
                        type="button"
                        onClick={() => setShowTable((open) => !open)}
                        className="underline underline-offset-2"
                        style={{ color: 'var(--text-muted)' }}
                        aria-expanded={showTable}
                    >
                        {showTable ? 'Hide table' : 'View as table'}
                    </button>
                </div>
            </figcaption>

            <div ref={containerRef} className="w-full">
                <svg
                    viewBox={`0 0 ${width} ${height}`}
                    width={width}
                    height={height}
                    className="block w-full"
                    role="img"
                    aria-labelledby={titleId}
                    onMouseLeave={() => setHovered(null)}
                >
                    {/* Grid, recessive. */}
                    {ticks.map((tick) => (
                        <g key={tick}>
                            <line
                                x1={padding.left}
                                x2={width - padding.right}
                                y1={y(tick)}
                                y2={y(tick)}
                                stroke="var(--chart-grid)"
                                strokeWidth={1}
                            />
                            <text
                                x={padding.left - 6}
                                y={y(tick) + 3}
                                textAnchor="end"
                                fontSize={9}
                                fill="var(--text-subtle)"
                            >
                                {tick}
                            </text>
                        </g>
                    ))}

                    {data.map((point, index) => {
                        const total = point.granted + point.denied;
                        const x = padding.left + index * slot + (slot - barWidth) / 2;
                        const baseline = y(0);

                        const deniedHeight = (point.denied / max) * plotHeight;
                        const grantedHeight = (point.granted / max) * plotHeight;

                        // A 2px gap keeps the two segments from reading as one
                        // block when the colours are close in print or at a
                        // glance.
                        const gap = point.denied > 0 && point.granted > 0 ? 2 : 0;

                        const grantedY = baseline - grantedHeight;
                        const deniedY = grantedY - gap - deniedHeight;

                        return (
                            <g
                                key={point.date}
                                onMouseEnter={() => setHovered(index)}
                                onFocus={() => setHovered(index)}
                                onBlur={() => setHovered(null)}
                                tabIndex={0}
                                role="graphics-symbol"
                                aria-label={`${formatDay(point.date)}: ${point.granted} granted, ${point.denied} denied`}
                            >
                                {/* A generous invisible hit area — the bars
                                    themselves are too thin to aim at. */}
                                <rect
                                    x={padding.left + index * slot}
                                    y={padding.top}
                                    width={slot}
                                    height={plotHeight}
                                    fill={hovered === index ? 'var(--surface-sunken)' : 'transparent'}
                                />

                                {point.granted > 0 && (
                                    <rect
                                        x={x}
                                        y={grantedY}
                                        width={barWidth}
                                        height={Math.max(1, grantedHeight)}
                                        rx={point.denied > 0 ? 0 : 4}
                                        fill="var(--chart-context)"
                                    />
                                )}

                                {point.denied > 0 && (
                                    <rect
                                        x={x}
                                        y={deniedY}
                                        width={barWidth}
                                        height={Math.max(2, deniedHeight)}
                                        rx={4}
                                        fill="var(--chart-signal)"
                                    />
                                )}

                                {total === 0 && (
                                    <line
                                        x1={x}
                                        x2={x + barWidth}
                                        y1={baseline}
                                        y2={baseline}
                                        stroke="var(--border-strong)"
                                        strokeWidth={2}
                                    />
                                )}

                                {/* Date labels thin out rather than collide. */}
                                {index % Math.ceil(data.length / 7) === 0 && (
                                    <text
                                        x={x + barWidth / 2}
                                        y={height - 8}
                                        textAnchor="middle"
                                        fontSize={9}
                                        fill="var(--text-subtle)"
                                    >
                                        {formatDay(point.date)}
                                    </text>
                                )}
                            </g>
                        );
                    })}
                </svg>
            </div>

            {/* The tooltip lives outside the SVG so it can use normal text
                layout and stay readable at any zoom. */}
            <div
                aria-live="polite"
                className="min-h-[1.25rem] text-xs"
                style={{ color: 'var(--text-muted)' }}
            >
                {active ? (
                    <span>
                        <strong style={{ color: 'var(--text)' }}>{formatDay(active.date)}</strong>
                        {' · '}
                        {formatNumber(active.granted)} granted ·{' '}
                        <strong style={{ color: 'var(--danger-text)' }}>
                            {formatNumber(active.denied)} denied
                        </strong>
                        {active.granted + active.denied > 0 && (
                            <>
                                {' · '}
                                {Math.round(
                                    (active.denied / (active.granted + active.denied)) * 100,
                                )}
                                % refused
                            </>
                        )}
                    </span>
                ) : (
                    <span>Hover or tab through a day for its breakdown.</span>
                )}
            </div>

            {showTable && (
                <div className="overflow-x-auto">
                    <table className="w-full text-xs">
                        <caption className="sr-only">
                            Daily entry attempts by outcome
                        </caption>
                        <thead>
                            <tr style={{ color: 'var(--text-muted)' }}>
                                <th scope="col" className="px-2 py-1 text-left">
                                    Day
                                </th>
                                <th scope="col" className="px-2 py-1 text-right">
                                    Granted
                                </th>
                                <th scope="col" className="px-2 py-1 text-right">
                                    Denied
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((point) => (
                                <tr key={point.date} style={{ borderTop: '1px solid var(--border)' }}>
                                    <td className="px-2 py-1">{formatDay(point.date)}</td>
                                    <td className="px-2 py-1 text-right tabular-nums">
                                        {formatNumber(point.granted)}
                                    </td>
                                    <td className="px-2 py-1 text-right tabular-nums">
                                        {formatNumber(point.denied)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </figure>
    );
}

function LegendSwatch({ color, label }: { color: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
            <span
                aria-hidden="true"
                className="inline-block h-2.5 w-2.5 rounded-sm"
                style={{ background: color }}
            />
            {label}
        </span>
    );
}
