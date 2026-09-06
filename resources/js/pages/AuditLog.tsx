import { Fragment, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, toApiError } from '@/lib/api';
import { formatDateTime, humanise } from '@/lib/format';
import {
    EmptyState,
    ErrorState,
    Input,
    LoadingRows,
    Panel,
    TableWrap,
    Td,
    Th,
} from '@/components/ui';
import type { AuditLog, Paginated } from '@/types';

/**
 * Read-only, by design. There is no endpoint that edits or deletes an entry —
 * an audit trail that can be tidied up is not one.
 *
 * Distinct from the access log: this records what an operator changed, that
 * one what happened at a door.
 */
export function AuditLogPage() {
    const [search, setSearch] = useState('');
    const [expanded, setExpanded] = useState<string | null>(null);

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['audit-logs', search],
        queryFn: async () => {
            const { data } = await api.get<Paginated<AuditLog>>('/audit-logs', {
                params: { search: search || undefined, per_page: 50 },
            });

            return data;
        },
    });

    return (
        <div className="flex flex-col gap-4">
            <header>
                <h1 className="text-lg font-semibold">Audit log</h1>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    Every dashboard change, with before and after state. Secrets are redacted before
                    they are written.
                </p>
            </header>

            <Panel
                title={`${data?.meta.total ?? 0} entries`}
                actions={
                    <Input
                        aria-label="Search actions"
                        placeholder="Filter by action"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="w-56"
                    />
                }
            >
                {error && (
                    <ErrorState message={toApiError(error).message} retry={() => void refetch()} />
                )}
                {isPending && <LoadingRows />}

                {data?.data.length === 0 && <EmptyState title="Nothing recorded yet" />}

                {(data?.data.length ?? 0) > 0 && (
                    <TableWrap>
                        <table className="w-full text-sm">
                            <thead style={{ background: 'var(--surface-sunken)' }}>
                                <tr>
                                    <Th>When</Th>
                                    <Th>Who</Th>
                                    <Th>Action</Th>
                                    <Th>Subject</Th>
                                    <Th>From</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {data?.data.map((entry) => (
                                    // A keyed Fragment: the expanded detail is a
                                    // second <tr>, and a table row cannot nest.
                                    <Fragment key={entry.id}>
                                        <tr style={{ borderTop: '1px solid var(--border)' }}>
                                            <Td className="whitespace-nowrap tabular-nums">
                                                {formatDateTime(entry.created_at)}
                                            </Td>
                                            <Td>{entry.user?.name ?? 'System'}</Td>
                                            <Td>
                                                <button
                                                    type="button"
                                                    className="underline underline-offset-2"
                                                    aria-expanded={expanded === entry.id}
                                                    onClick={() =>
                                                        setExpanded(
                                                            expanded === entry.id ? null : entry.id,
                                                        )
                                                    }
                                                >
                                                    {humanise(entry.action)}
                                                </button>
                                            </Td>
                                            <Td style={{ color: 'var(--text-muted)' }}>
                                                {entry.subject_type ?? '—'}
                                            </Td>
                                            <Td
                                                className="font-mono text-xs"
                                                style={{ color: 'var(--text-subtle)' }}
                                            >
                                                {entry.ip ?? '—'}
                                            </Td>
                                        </tr>

                                        {expanded === entry.id && (
                                            <tr>
                                                <td colSpan={5} className="px-4 pb-3">
                                                    <div className="grid gap-3 sm:grid-cols-2">
                                                        <StatePanel label="Before" value={entry.before} />
                                                        <StatePanel label="After" value={entry.after} />
                                                    </div>
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </TableWrap>
                )}
            </Panel>
        </div>
    );
}

function StatePanel({ label, value }: { label: string; value: Record<string, unknown> | null }) {
    return (
        <div className="rounded-lg p-3" style={{ background: 'var(--surface-sunken)' }}>
            <p className="mb-1 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                {label}
            </p>
            <pre className="overflow-x-auto text-xs whitespace-pre-wrap">
                {value ? JSON.stringify(value, null, 2) : '—'}
            </pre>
        </div>
    );
}
