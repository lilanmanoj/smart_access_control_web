import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { api, ensureCsrfCookie, toApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatRelative, humanise } from '@/lib/format';
import {
    CommandStatusBadge,
    DeviceStateBadge,
    EnrollmentStatusBadge,
} from '@/components/status';
import {
    Badge,
    Button,
    EmptyState,
    ErrorState,
    LoadingRows,
    Panel,
    TableWrap,
    Td,
    Th,
} from '@/components/ui';
import type { Device, Enrollment, Paginated } from '@/types';

/**
 * One door: its state, who is enrolled on it, what is queued for it, and the
 * two operations that reach it — remote unlock and a backup-code rotation.
 */
export function DeviceDetailPage() {
    const { deviceId = '' } = useParams();
    const { can } = useAuth();
    const queryClient = useQueryClient();
    const [issuedCodes, setIssuedCodes] = useState<string[] | null>(null);
    const [issuedCredential, setIssuedCredential] = useState<{
        device_id: string;
        api_key: string;
        api_secret: string;
    } | null>(null);

    const deviceQuery = useQuery({
        queryKey: ['device', deviceId],
        queryFn: async () => {
            const { data } = await api.get<{ data: Device }>(`/devices/${deviceId}`);

            return data.data;
        },
        refetchInterval: 30_000,
    });

    const enrollmentsQuery = useQuery({
        queryKey: ['device-enrollments', deviceId],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Enrollment>>('/enrollments', {
                params: { device_id: deviceId, per_page: 100 },
            });

            return data;
        },
        enabled: can('enrollment.view'),
    });

    const invalidate = () => {
        void queryClient.invalidateQueries({ queryKey: ['device', deviceId] });
        void queryClient.invalidateQueries({ queryKey: ['device-enrollments', deviceId] });
    };

    const unlock = useMutation({
        mutationFn: async () => {
            await ensureCsrfCookie();
            await api.post(`/devices/${deviceId}/unlock`, { reason: 'Dashboard remote unlock' });
        },
        onSuccess: invalidate,
    });

    const rotateCodes = useMutation({
        mutationFn: async () => {
            await ensureCsrfCookie();
            const { data } = await api.post(`/devices/${deviceId}/backup-codes/rotations`);

            return data.codes as string[];
        },
        onSuccess: (codes) => {
            setIssuedCodes(codes);
            invalidate();
        },
    });

    const issueCredential = useMutation({
        mutationFn: async () => {
            await ensureCsrfCookie();
            const { data } = await api.post(`/devices/${deviceId}/credentials`, {});

            return data;
        },
        onSuccess: (data) => {
            setIssuedCredential({
                device_id: data.device_id,
                api_key: data.api_key,
                api_secret: data.api_secret,
            });
            invalidate();
        },
    });

    const revoke = useMutation({
        mutationFn: async (enrollmentId: string) => {
            await ensureCsrfCookie();
            await api.delete(`/enrollments/${enrollmentId}`);
        },
        onSuccess: invalidate,
    });

    if (deviceQuery.error) {
        return (
            <ErrorState
                message={toApiError(deviceQuery.error).message}
                retry={() => void deviceQuery.refetch()}
            />
        );
    }

    if (deviceQuery.isPending) {
        return <LoadingRows rows={8} />;
    }

    const device = deviceQuery.data;
    const pending = device.pending_commands ?? [];

    return (
        <div className="flex flex-col gap-4">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <Link
                        to="/devices"
                        className="text-xs underline underline-offset-2"
                        style={{ color: 'var(--text-muted)' }}
                    >
                        ← All devices
                    </Link>
                    <h1 className="mt-1 flex items-center gap-2 text-lg font-semibold">
                        {device.name}
                        <DeviceStateBadge device={device} />
                    </h1>
                    <p className="font-mono text-xs" style={{ color: 'var(--text-subtle)' }}>
                        {device.device_id}
                        {device.location ? ` · ${device.location}` : ''}
                    </p>
                </div>

                <div className="flex flex-wrap gap-2">
                    {can('device.unlock') && (
                        <Button
                            variant="primary"
                            loading={unlock.isPending}
                            onClick={() => unlock.mutate()}
                        >
                            Unlock remotely
                        </Button>
                    )}
                    {can('backupcode.rotate') && (
                        <Button
                            loading={rotateCodes.isPending}
                            onClick={() => rotateCodes.mutate()}
                        >
                            Rotate backup codes
                        </Button>
                    )}
                </div>
            </header>

            {unlock.isSuccess && (
                <p
                    role="status"
                    className="panel px-4 py-2 text-xs"
                    style={{ borderColor: 'var(--warn)', color: 'var(--warn-text)' }}
                >
                    Unlock queued. The panel cannot be pushed to, so it opens at its next health
                    poll — up to about 30 seconds away. It is already recorded in the access log
                    against your name.
                </p>
            )}

            {/* Shown once, and nowhere else afterwards. */}
            {issuedCodes && (
                <RevealOnce
                    title="New backup codes"
                    warning="These are shown once. Write them down before you close this."
                    onDismiss={() => setIssuedCodes(null)}
                >
                    <ul className="flex flex-wrap gap-2">
                        {issuedCodes.map((code) => (
                            <li
                                key={code}
                                className="rounded-lg px-3 py-1.5 font-mono text-base tracking-widest"
                                style={{ background: 'var(--surface-sunken)' }}
                            >
                                {code}
                            </li>
                        ))}
                    </ul>
                </RevealOnce>
            )}

            {issuedCredential && (
                <RevealOnce
                    title="New device credential"
                    warning="The secret is shown once. Enter these three values into the panel's settings portal now."
                    onDismiss={() => setIssuedCredential(null)}
                >
                    <dl className="grid gap-1 font-mono text-xs sm:grid-cols-[10rem_1fr]">
                        <dt style={{ color: 'var(--text-muted)' }}>X-Device-Id</dt>
                        <dd className="break-all">{issuedCredential.device_id}</dd>
                        <dt style={{ color: 'var(--text-muted)' }}>X-API-Key</dt>
                        <dd className="break-all">{issuedCredential.api_key}</dd>
                        <dt style={{ color: 'var(--text-muted)' }}>X-API-Secret</dt>
                        <dd className="break-all">{issuedCredential.api_secret}</dd>
                    </dl>
                </RevealOnce>
            )}

            <div className="grid gap-4 lg:grid-cols-3">
                <Panel title="Status" className="p-4">
                    <dl className="flex flex-col gap-2 text-xs">
                        <Row label="Last health poll" value={formatRelative(device.last_health_at)} />
                        <Row label="Last seen" value={formatDateTime(device.last_seen_at)} />
                        <Row label="Firmware" value={device.firmware_version ?? 'Unknown'} />
                        <Row
                            label="Templates stored"
                            value={`${device.enrolled_count} of ${device.template_capacity}`}
                        />
                        <Row label="Administrative state" value={humanise(device.status)} />
                    </dl>
                </Panel>

                <Panel
                    title="Backup codes"
                    description="Metadata only — the codes themselves are stored hashed"
                    className="p-4"
                >
                    {device.active_backup_code_set ? (
                        <div className="flex flex-col gap-2 text-xs">
                            <Row
                                label="Issued"
                                value={formatDateTime(device.active_backup_code_set.issued_at)}
                            />
                            <Row
                                label="Reason"
                                value={humanise(device.active_backup_code_set.reason)}
                            />
                            <div className="mt-1 flex flex-wrap gap-1.5">
                                {device.active_backup_code_set.codes?.map((code) => (
                                    <span
                                        key={code.id}
                                        className="rounded px-2 py-0.5 font-mono"
                                        style={{
                                            background: 'var(--surface-sunken)',
                                            color: code.used_at
                                                ? 'var(--text-subtle)'
                                                : 'var(--text)',
                                            textDecoration: code.used_at ? 'line-through' : 'none',
                                        }}
                                    >
                                        ••{code.last4}
                                    </span>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                            No set issued yet. The panel gets one the first time it asks.
                        </p>
                    )}
                </Panel>

                <Panel
                    title="Queued commands"
                    description="Nothing reaches a panel until it polls"
                    className="p-4"
                >
                    {pending.length === 0 ? (
                        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                            Nothing queued.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2 text-xs">
                            {pending.map((command) => (
                                <li key={command.id} className="flex items-center justify-between gap-2">
                                    <span>{humanise(command.type)}</span>
                                    <CommandStatusBadge status={command.status} />
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>

            {/* ------------------------------------------------------------ */}
            {/* Credentials                                                   */}
            {/* ------------------------------------------------------------ */}
            {can('device.update') && (
                <Panel
                    title="API credentials"
                    description="Two live credentials are allowed, so a rotation overlaps instead of locking the panel out"
                    actions={
                        <Button
                            loading={issueCredential.isPending}
                            onClick={() => issueCredential.mutate()}
                        >
                            Issue credential
                        </Button>
                    }
                >
                    {issueCredential.error && (
                        <p role="alert" className="px-4 py-2 text-xs" style={{ color: 'var(--danger-text)' }}>
                            {toApiError(issueCredential.error).message}
                        </p>
                    )}

                    {(device.credentials ?? []).length === 0 ? (
                        <EmptyState
                            title="No credentials issued"
                            description="The panel cannot reach the backend until it has an API key and secret."
                        />
                    ) : (
                        <TableWrap>
                            <table className="w-full text-sm">
                                <thead style={{ background: 'var(--surface-sunken)' }}>
                                    <tr>
                                        <Th>Key</Th>
                                        <Th>Label</Th>
                                        <Th>Last used</Th>
                                        <Th>State</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {device.credentials?.map((credential) => (
                                        <tr key={credential.id} style={{ borderTop: '1px solid var(--border)' }}>
                                            <Td className="font-mono text-xs">{credential.api_key}</Td>
                                            <Td style={{ color: 'var(--text-muted)' }}>
                                                {credential.label ?? '—'}
                                            </Td>
                                            <Td style={{ color: 'var(--text-muted)' }}>
                                                {formatRelative(credential.last_used_at)}
                                            </Td>
                                            <Td>
                                                {credential.is_usable ? (
                                                    <Badge tone="ok" glyph="✓">
                                                        Usable
                                                    </Badge>
                                                ) : (
                                                    <Badge tone="neutral" glyph="—">
                                                        Revoked
                                                    </Badge>
                                                )}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </TableWrap>
                    )}
                </Panel>
            )}

            {/* ------------------------------------------------------------ */}
            {/* Enrolments                                                    */}
            {/* ------------------------------------------------------------ */}
            {can('enrollment.view') && (
                <Panel
                    title="Enrolments"
                    description="Fingerprint templates live in the sensor's own flash; the backend only records who owns which slot"
                >
                    {enrollmentsQuery.isPending && <LoadingRows rows={3} />}

                    {enrollmentsQuery.data?.data.length === 0 && (
                        <EmptyState
                            title="Nobody enrolled yet"
                            description="Enrolment starts at the panel — that is where the finger is."
                        />
                    )}

                    {(enrollmentsQuery.data?.data.length ?? 0) > 0 && (
                        <TableWrap>
                            <table className="w-full text-sm">
                                <thead style={{ background: 'var(--surface-sunken)' }}>
                                    <tr>
                                        <Th>Member</Th>
                                        <Th>Slot</Th>
                                        <Th>State</Th>
                                        <Th>Enrolled</Th>
                                        <Th>{can('enrollment.revoke') ? 'Actions' : ''}</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {enrollmentsQuery.data?.data.map((enrollment) => (
                                        <tr key={enrollment.id} style={{ borderTop: '1px solid var(--border)' }}>
                                            <Td>
                                                {enrollment.member ? (
                                                    <Link
                                                        to={`/members/${enrollment.member.id}`}
                                                        className="underline underline-offset-2"
                                                    >
                                                        {enrollment.member.full_name}
                                                    </Link>
                                                ) : (
                                                    '—'
                                                )}
                                            </Td>
                                            <Td className="tabular-nums">
                                                {enrollment.fingerprint_slot ?? (
                                                    <span style={{ color: 'var(--text-subtle)' }}>
                                                        phone only
                                                    </span>
                                                )}
                                            </Td>
                                            <Td>
                                                <EnrollmentStatusBadge status={enrollment.status} />
                                            </Td>
                                            <Td style={{ color: 'var(--text-muted)' }}>
                                                {formatDateTime(enrollment.enrolled_at)}
                                            </Td>
                                            <Td>
                                                {can('enrollment.revoke') &&
                                                    enrollment.status !== 'revoked' && (
                                                        <Button
                                                            variant="danger"
                                                            loading={revoke.isPending}
                                                            onClick={() => {
                                                                if (
                                                                    window.confirm(
                                                                        'Revoke this enrolment? The template erase is queued and takes effect when the device next polls — until then the finger still opens the door.',
                                                                    )
                                                                ) {
                                                                    revoke.mutate(enrollment.id);
                                                                }
                                                            }}
                                                        >
                                                            Revoke
                                                        </Button>
                                                    )}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </TableWrap>
                    )}
                </Panel>
            )}
        </div>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt style={{ color: 'var(--text-muted)' }}>{label}</dt>
            <dd className="text-right font-medium">{value}</dd>
        </div>
    );
}

/**
 * A panel for a secret that exists exactly once. Deliberately noisy: an
 * operator who clicks past this cannot get the value back.
 */
function RevealOnce({
    title,
    warning,
    children,
    onDismiss,
}: {
    title: string;
    warning: string;
    children: React.ReactNode;
    onDismiss(): void;
}) {
    return (
        <section
            role="status"
            className="panel flex flex-col gap-3 p-4"
            style={{ borderColor: 'var(--warn)', background: 'var(--warn-soft)' }}
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold">{title}</h2>
                    <p className="text-xs" style={{ color: 'var(--warn-text)' }}>
                        <span aria-hidden="true">! </span>
                        {warning}
                    </p>
                </div>
                <Button onClick={onDismiss}>I have recorded these</Button>
            </div>
            {children}
        </section>
    );
}
