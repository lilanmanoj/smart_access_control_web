import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api, toApiError } from '@/lib/api';
import { formatDateTime, humanise } from '@/lib/format';
import {
    Badge,
    EmptyState,
    ErrorState,
    LoadingRows,
    Panel,
    TableWrap,
    Td,
    Th,
} from '@/components/ui';
import type { BackupCodeSet, Paginated } from '@/types';

/**
 * Backup code history.
 *
 * Only metadata and the last four digits: the codes are stored as keyed
 * hashes, and the plaintext exists in exactly one response — the one that
 * generated them. Rotation lives on the device page, next to the door it
 * affects.
 */
export function BackupCodesPage() {
    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['backup-code-sets'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<BackupCodeSet & { device?: unknown }>>(
                '/backup-code-sets',
                { params: { per_page: 50 } },
            );

            return data;
        },
    });

    return (
        <div className="flex flex-col gap-4">
            <header>
                <h1 className="text-lg font-semibold">Backup codes</h1>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    Five six-digit codes per door, cached on the panel so it still opens when the
                    network is down. Using any one retires the whole set.
                </p>
            </header>

            <div
                className="panel px-4 py-3 text-xs"
                style={{ borderColor: 'var(--warn)', background: 'var(--warn-soft)' }}
            >
                <p style={{ color: 'var(--warn-text)' }}>
                    <span aria-hidden="true">! </span>
                    <strong>These codes are device-scoped, not personal.</strong> They open the door
                    but identify nobody, so an entry made with one names no member in the access
                    log. That is the trade for a method that keeps working offline.
                </p>
            </div>

            <Panel title="Issued sets">
                {error && (
                    <ErrorState message={toApiError(error).message} retry={() => void refetch()} />
                )}
                {isPending && <LoadingRows />}

                {data?.data.length === 0 && (
                    <EmptyState
                        title="No sets issued"
                        description="A device gets its first set the moment it asks for one."
                    />
                )}

                {(data?.data.length ?? 0) > 0 && (
                    <TableWrap>
                        <table className="w-full text-sm">
                            <thead style={{ background: 'var(--surface-sunken)' }}>
                                <tr>
                                    <Th>Device</Th>
                                    <Th>State</Th>
                                    <Th>Issued</Th>
                                    <Th>Why</Th>
                                    <Th>Codes</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {data?.data.map((set) => {
                                    const device = (set as { device?: { id: string; name: string } })
                                        .device;

                                    return (
                                        <tr key={set.id} style={{ borderTop: '1px solid var(--border)' }}>
                                            <Td>
                                                {device ? (
                                                    <Link
                                                        to={`/devices/${device.id}`}
                                                        className="underline underline-offset-2"
                                                    >
                                                        {device.name}
                                                    </Link>
                                                ) : (
                                                    '—'
                                                )}
                                            </Td>
                                            <Td>
                                                {set.status === 'active' ? (
                                                    <Badge tone="ok" glyph="✓">
                                                        Active
                                                    </Badge>
                                                ) : (
                                                    <Badge tone="neutral" glyph="—">
                                                        Superseded
                                                    </Badge>
                                                )}
                                            </Td>
                                            <Td style={{ color: 'var(--text-muted)' }}>
                                                {formatDateTime(set.issued_at)}
                                            </Td>
                                            <Td style={{ color: 'var(--text-muted)' }}>
                                                {humanise(set.reason)}
                                            </Td>
                                            <Td>
                                                <span className="flex flex-wrap gap-1 font-mono text-xs">
                                                    {set.codes?.map((code) => (
                                                        <span
                                                            key={code.id}
                                                            className="rounded px-1.5 py-0.5"
                                                            style={{
                                                                background: 'var(--surface-sunken)',
                                                                color: code.used_at
                                                                    ? 'var(--text-subtle)'
                                                                    : 'var(--text)',
                                                                textDecoration: code.used_at
                                                                    ? 'line-through'
                                                                    : 'none',
                                                            }}
                                                            title={
                                                                code.used_at
                                                                    ? `Used ${formatDateTime(code.used_at)}`
                                                                    : 'Unused'
                                                            }
                                                        >
                                                            ••{code.last4}
                                                        </span>
                                                    ))}
                                                </span>
                                            </Td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </TableWrap>
                )}
            </Panel>
        </div>
    );
}
