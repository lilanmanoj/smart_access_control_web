import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '@/lib/auth';
import { getEcho } from '@/lib/echo';
import { realtimeAvailable } from '@/lib/config';
import { formatTime } from '@/lib/format';
import { MethodLabel, ResultBadge } from './status';
import { Badge } from './ui';
import type { AccessEvent } from '@/types';

/**
 * The live ticker.
 *
 * Subscribes to the tenant's private event channel. New rows are prepended and
 * briefly tinted rather than flashed — an operator is reading this while it
 * moves, and a hard change is disorienting.
 *
 * The list is capped: an unbounded live feed is a memory leak with a scrollbar.
 */
const MAX_ROWS = 40;

export function LiveEvents({ seed = [] }: { seed?: AccessEvent[] }) {
    const { user } = useAuth();
    const [events, setEvents] = useState<AccessEvent[]>(seed);
    const [connected, setConnected] = useState(false);
    const [paused, setPaused] = useState(false);
    const pausedRef = useRef(paused);

    pausedRef.current = paused;

    const tenantUuid = user?.tenant?.id ?? null;

    useEffect(() => {
        setEvents(seed);
        // Only when the seed identity changes, not on every render of an
        // equal-but-new array.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [seed.map((event) => event.id).join(',')]);

    useEffect(() => {
        const echo = getEcho();

        if (!echo || !tenantUuid) {
            return;
        }

        const channel = echo.private(`tenant.${tenantUuid}.events`);

        channel.listen('.access.event', (payload: AccessEvent) => {
            if (pausedRef.current) {
                return;
            }

            setEvents((current) => [payload, ...current].slice(0, MAX_ROWS));
        });

        setConnected(true);

        return () => {
            echo.leave(`tenant.${tenantUuid}.events`);
            setConnected(false);
        };
    }, [tenantUuid]);

    return (
        <div className="flex flex-col">
            <div
                className="flex items-center justify-between gap-2 border-b px-4 py-2"
                style={{ borderColor: 'var(--border)' }}
            >
                <div className="flex items-center gap-2">
                    {realtimeAvailable ? (
                        connected ? (
                            <Badge tone="ok" glyph="●">
                                Live
                            </Badge>
                        ) : (
                            <Badge tone="warn" glyph="◔">
                                Connecting
                            </Badge>
                        )
                    ) : (
                        <Badge tone="neutral" glyph="—">
                            Live updates off
                        </Badge>
                    )}
                </div>

                {realtimeAvailable && (
                    <button
                        type="button"
                        onClick={() => setPaused((value) => !value)}
                        className="text-xs underline underline-offset-2"
                        style={{ color: 'var(--text-muted)' }}
                    >
                        {/* Reading a row that keeps sliding away is the most
                            common complaint about live feeds. */}
                        {paused ? 'Resume' : 'Pause'}
                    </button>
                )}
            </div>

            <ul
                className="max-h-96 divide-y overflow-y-auto"
                style={{ borderColor: 'var(--border)' }}
                aria-live="polite"
                aria-relevant="additions"
                aria-label="Live access events"
            >
                {events.length === 0 && (
                    <li className="px-4 py-6 text-xs" style={{ color: 'var(--text-muted)' }}>
                        Nothing yet. Events appear here the moment a door reports one.
                    </li>
                )}

                {events.map((event, index) => (
                    <li
                        key={event.id}
                        className={`flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-xs ${
                            index === 0 && connected ? 'row-enter' : ''
                        }`}
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <span className="tabular-nums" style={{ color: 'var(--text-subtle)' }}>
                            {formatTime(event.occurred_at)}
                        </span>

                        <ResultBadge
                            result={event.result}
                            reason={event.result === 'denied' ? event.reason_label : undefined}
                        />

                        <MethodLabel method={event.method} label={event.method_label} />

                        <span className="font-medium">
                            {event.member?.full_name ?? 'Unidentified'}
                        </span>

                        {event.device && (
                            <Link
                                to={`/devices/${event.device.id}`}
                                className="ml-auto underline underline-offset-2"
                                style={{ color: 'var(--text-muted)' }}
                            >
                                {event.device.name}
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
