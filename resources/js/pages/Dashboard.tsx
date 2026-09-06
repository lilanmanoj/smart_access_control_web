import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api, toApiError } from '@/lib/api';
import { DenialReasons } from '@/components/DenialReasons';
import { LiveEvents } from '@/components/LiveEvents';
import { StatTile } from '@/components/StatTile';
import { TrendChart } from '@/components/TrendChart';
import { ErrorState, LoadingRows, Panel } from '@/components/ui';
import type { AccessEvent, DashboardSummary } from '@/types';

const TREND_DAYS = 14;

/**
 * The landing page.
 *
 * Ordered by what an operator needs first during an incident: which doors are
 * dark, how many entries were refused, then the live feed, then the trend.
 */
export function DashboardPage() {
    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['dashboard', TREND_DAYS],
        queryFn: async () => {
            const { data } = await api.get<DashboardSummary>('/dashboard/summary', {
                params: { days: TREND_DAYS },
            });

            return data;
        },
        // The tiles are cheap and go stale quickly; the live channel handles
        // the event feed itself.
        refetchInterval: 60_000,
    });

    if (error) {
        return <ErrorState message={toApiError(error).message} retry={() => void refetch()} />;
    }

    if (isPending) {
        return <LoadingRows rows={8} />;
    }

    const recent: AccessEvent[] = Array.isArray(data.recent_events)
        ? data.recent_events
        : (data.recent_events?.data ?? []);

    const attention = data.attention;
    const needsAttention =
        attention.devices_offline + attention.orphaned_enrollments > 0;

    return (
        <div className="flex flex-col gap-4">
            <header>
                <h1 className="text-lg font-semibold">Dashboard</h1>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    Door status, today's access activity and anything waiting on a person.
                </p>
            </header>

            {/* ------------------------------------------------------------ */}
            {/* What needs a person. Shown first, and only when there is       */}
            {/* something — a permanent "0 problems" banner trains people to   */}
            {/* stop reading it.                                               */}
            {/* ------------------------------------------------------------ */}
            {needsAttention && (
                <div
                    role="status"
                    className="panel flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3"
                    style={{ borderColor: 'var(--danger)', background: 'var(--danger-soft)' }}
                >
                    <span className="text-sm font-semibold" style={{ color: 'var(--danger-text)' }}>
                        <span aria-hidden="true">! </span>Needs attention
                    </span>

                    {attention.devices_offline > 0 && (
                        <Link to="/devices" className="text-xs underline underline-offset-2">
                            {attention.devices_offline} active device
                            {attention.devices_offline === 1 ? '' : 's'} not reporting
                        </Link>
                    )}

                    {attention.orphaned_enrollments > 0 && (
                        <Link to="/members" className="text-xs underline underline-offset-2">
                            {attention.orphaned_enrollments} enrolment
                            {attention.orphaned_enrollments === 1 ? '' : 's'} out of sync with a sensor
                        </Link>
                    )}
                </div>
            )}

            {/* ------------------------------------------------------------ */}
            {/* Headline numbers                                              */}
            {/* ------------------------------------------------------------ */}
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile
                    label="Doors online"
                    value={`${data.devices.online} / ${data.devices.total}`}
                    detail={
                        data.devices.offline > 0
                            ? `${data.devices.offline} not reporting`
                            : 'All devices reporting'
                    }
                    tone={data.attention.devices_offline > 0 ? 'alert' : 'default'}
                />

                <StatTile
                    label="Entries today"
                    value={data.today.granted}
                    detail={`${data.today.total} attempts in total`}
                />

                <StatTile
                    label="Refused today"
                    value={data.today.denied}
                    detail="Every refusal is in the access log"
                    tone={data.today.denied > 0 ? 'alert' : 'default'}
                />

                <StatTile
                    label="Refusal rate"
                    value={data.today.denied_rate}
                    unit="%"
                    detail={`${data.members.active} active members · ${data.members.admins} device admins`}
                />
            </div>

            {/* ------------------------------------------------------------ */}
            {/* Live feed, then trend                                         */}
            {/* ------------------------------------------------------------ */}
            <div className="grid gap-4 xl:grid-cols-3">
                <Panel
                    title="Live activity"
                    description="Every access decision, as the doors report them"
                    className="xl:col-span-2"
                >
                    <LiveEvents seed={recent} />
                </Panel>

                <Panel
                    title="Why entries were refused"
                    description={`Most common reasons, last ${TREND_DAYS} days`}
                >
                    <DenialReasons data={data.top_denial_reasons} />
                </Panel>
            </div>

            <Panel className="p-4">
                <TrendChart data={data.trend} days={TREND_DAYS} />
            </Panel>
        </div>
    );
}
