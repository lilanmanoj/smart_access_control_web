import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api, toApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { getEcho } from '@/lib/echo';
import { realtimeAvailable } from '@/lib/config';
import { formatDateTime } from '@/lib/format';
import { MethodLabel, ResultBadge } from '@/components/status';
import {
    Button,
    EmptyState,
    ErrorState,
    Input,
    LoadingRows,
    Panel,
    Select,
    TableWrap,
    Td,
    Th,
} from '@/components/ui';
import type { AccessEvent, CursorPaginated, Device } from '@/types';

interface Filters {
    device_id: string;
    method: string;
    result: string;
    from: string;
    to: string;
}

const EMPTY: Filters = { device_id: '', method: '', result: '', from: '', to: '' };

/**
 * The access log.
 *
 * Cursor-paginated, because this table is unbounded — a busy door writes a lot
 * of rows and `OFFSET 200000` makes the database walk all of them.
 *
 * Live-append is opt-in and only meaningful with no filters applied: silently
 * prepending rows that do not match the filter would be a lie about what is
 * on screen.
 */
export function AccessEventsPage() {
    const { user, can } = useAuth();
    const [filters, setFilters] = useState<Filters>(EMPTY);
    const [cursor, setCursor] = useState<string | null>(null);
    const [live, setLive] = useState(false);
    const [appended, setAppended] = useState<AccessEvent[]>([]);
    const liveRef = useRef(live);

    liveRef.current = live;

    const hasFilters = Object.values(filters).some(Boolean);

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['access-events', filters, cursor],
        queryFn: async () => {
            const { data } = await api.get<CursorPaginated<AccessEvent>>('/access-events', {
                params: {
                    ...Object.fromEntries(
                        Object.entries(filters).filter(([, value]) => value !== ''),
                    ),
                    cursor: cursor ?? undefined,
                    per_page: 50,
                },
            });

            return data;
        },
    });

    const devicesQuery = useQuery({
        queryKey: ['devices', ''],
        queryFn: async () => {
            const { data } = await api.get('/devices', { params: { per_page: 100 } });

            return data.data as Device[];
        },
        enabled: can('device.view'),
    });

    const tenantUuid = user?.tenant?.id ?? null;

    useEffect(() => {
        if (!live || !tenantUuid) {
            return;
        }

        const echo = getEcho();

        if (!echo) {
            return;
        }

        const name = `tenant.${tenantUuid}.events`;
        echo.private(name).listen('.access.event', (payload: AccessEvent) => {
            if (liveRef.current) {
                setAppended((current) => [payload, ...current].slice(0, 100));
            }
        });

        return () => {
            echo.leave(name);
        };
    }, [live, tenantUuid]);

    const rows = [...appended, ...(data?.data ?? [])];

    function updateFilter(key: keyof Filters, value: string) {
        setFilters((current) => ({ ...current, [key]: value }));
        setCursor(null);
        setAppended([]);
    }

    const exportUrl = (format: 'csv' | 'xlsx') => {
        const params = new URLSearchParams(
            Object.entries(filters).filter(([, value]) => value !== ''),
        );
        params.set('format', format);

        return `/api/admin/v1/access-events/export?${params.toString()}`;
    };

    return (
        <div className="flex flex-col gap-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold">Access events</h1>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        Every attempt through every method, granted or denied. Append-only.
                    </p>
                </div>

                {can('event.export') && (
                    <div className="flex gap-2">
                        {/* A plain link, so the browser handles the streamed
                            download and the session cookie goes with it. */}
                        <a href={exportUrl('csv')} download>
                            <Button>Export CSV</Button>
                        </a>
                        <a href={exportUrl('xlsx')} download>
                            <Button>Export XLSX</Button>
                        </a>
                    </div>
                )}
            </header>

            <Panel className="p-3">
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                    <Select
                        aria-label="Filter by device"
                        value={filters.device_id}
                        onChange={(event) => updateFilter('device_id', event.target.value)}
                    >
                        <option value="">All devices</option>
                        {devicesQuery.data?.map((device) => (
                            <option key={device.id} value={device.id}>
                                {device.name}
                            </option>
                        ))}
                    </Select>

                    <Select
                        aria-label="Filter by method"
                        value={filters.method}
                        onChange={(event) => updateFilter('method', event.target.value)}
                    >
                        <option value="">All methods</option>
                        <option value="fingerprint">Fingerprint</option>
                        <option value="otp">OTP</option>
                        <option value="backup_code">Backup code</option>
                        <option value="admin_auth">Admin authentication</option>
                        <option value="remote">Remote unlock</option>
                    </Select>

                    <Select
                        aria-label="Filter by result"
                        value={filters.result}
                        onChange={(event) => updateFilter('result', event.target.value)}
                    >
                        <option value="">Granted and denied</option>
                        <option value="denied">Denied only</option>
                        <option value="granted">Granted only</option>
                    </Select>

                    <Input
                        type="date"
                        aria-label="From date"
                        value={filters.from}
                        onChange={(event) => updateFilter('from', event.target.value)}
                    />

                    <Input
                        type="date"
                        aria-label="To date"
                        value={filters.to}
                        onChange={(event) => updateFilter('to', event.target.value)}
                    />
                </div>

                <div className="mt-2 flex flex-wrap items-center gap-3 text-xs">
                    {hasFilters && (
                        <button
                            type="button"
                            className="underline underline-offset-2"
                            onClick={() => {
                                setFilters(EMPTY);
                                setCursor(null);
                                setAppended([]);
                            }}
                        >
                            Clear filters
                        </button>
                    )}

                    {realtimeAvailable && (
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={live}
                                disabled={hasFilters}
                                onChange={(event) => {
                                    setLive(event.target.checked);
                                    setAppended([]);
                                }}
                            />
                            <span style={{ color: hasFilters ? 'var(--text-subtle)' : undefined }}>
                                Append new events live
                                {hasFilters && ' (clear filters first)'}
                            </span>
                        </label>
                    )}
                </div>
            </Panel>

            <Panel>
                {error && (
                    <ErrorState message={toApiError(error).message} retry={() => void refetch()} />
                )}
                {isPending && <LoadingRows rows={8} />}

                {!isPending && rows.length === 0 && (
                    <EmptyState
                        title="No events match"
                        description="Widen the date range, or clear the filters."
                    />
                )}

                {rows.length > 0 && (
                    <>
                        <TableWrap>
                            <table className="w-full text-sm">
                                <thead style={{ background: 'var(--surface-sunken)' }}>
                                    <tr>
                                        <Th>When</Th>
                                        <Th>Outcome</Th>
                                        <Th>Method</Th>
                                        <Th>Member</Th>
                                        <Th>Device</Th>
                                        <Th>Detail</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((event, index) => (
                                        <tr
                                            key={event.id}
                                            className={index < appended.length ? 'row-enter' : ''}
                                            style={{ borderTop: '1px solid var(--border)' }}
                                        >
                                            <Td className="whitespace-nowrap tabular-nums">
                                                {formatDateTime(event.occurred_at)}
                                            </Td>
                                            <Td>
                                                <ResultBadge
                                                    result={event.result}
                                                    reason={
                                                        event.result === 'denied'
                                                            ? event.reason_label
                                                            : undefined
                                                    }
                                                />
                                            </Td>
                                            <Td>
                                                <MethodLabel
                                                    method={event.method}
                                                    label={event.method_label}
                                                />
                                            </Td>
                                            <Td>
                                                {event.member ? (
                                                    <Link
                                                        to={`/members/${event.member.id}`}
                                                        className="underline underline-offset-2"
                                                    >
                                                        {event.member.full_name}
                                                    </Link>
                                                ) : (
                                                    <span style={{ color: 'var(--text-subtle)' }}>
                                                        Unidentified
                                                    </span>
                                                )}
                                            </Td>
                                            <Td>
                                                {event.device && (
                                                    <Link
                                                        to={`/devices/${event.device.id}`}
                                                        className="underline underline-offset-2"
                                                    >
                                                        {event.device.name}
                                                    </Link>
                                                )}
                                            </Td>
                                            <Td className="text-xs" style={{ color: 'var(--text-muted)' }}>
                                                {event.fingerprint_slot !== null &&
                                                    `slot ${event.fingerprint_slot}`}
                                                {event.confidence !== null &&
                                                    ` · confidence ${event.confidence}`}
                                                {event.actor && ` · by ${event.actor.name}`}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </TableWrap>

                        <div
                            className="flex items-center justify-between gap-2 border-t px-4 py-2"
                            style={{ borderColor: 'var(--border)' }}
                        >
                            <Button
                                disabled={!data?.meta.prev_cursor}
                                onClick={() => setCursor(data?.meta.prev_cursor ?? null)}
                            >
                                Newer
                            </Button>
                            <span className="text-xs" style={{ color: 'var(--text-subtle)' }}>
                                {rows.length} shown
                            </span>
                            <Button
                                disabled={!data?.meta.next_cursor}
                                onClick={() => setCursor(data?.meta.next_cursor ?? null)}
                            >
                                Older
                            </Button>
                        </div>
                    </>
                )}
            </Panel>
        </div>
    );
}
