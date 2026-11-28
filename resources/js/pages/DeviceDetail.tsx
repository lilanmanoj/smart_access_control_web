import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { api, ensureCsrfCookie, toApiError, type ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatRelative, humanise } from '@/lib/format';
import {
    BackupDeliveryBadge,
    backupReasonLabel,
    backupSetWasSeen,
    CommandStatusBadge,
    DeviceStateBadge,
    EnrollmentStatusBadge,
    TemplateSyncBadge,
} from '@/components/status';
import { TimezoneSelect } from '@/components/TimezoneSelect';
import {
    Badge,
    Button,
    EmptyState,
    ErrorState,
    Field,
    Input,
    LoadingRows,
    Panel,
    TableWrap,
    Td,
    Th,
} from '@/components/ui';
import type { Device, DeviceCommand, Enrollment, Paginated, TemplateSync } from '@/types';

/**
 * One door: its state, who is enrolled on it, what is queued for it, and the
 * two operations that reach it — remote unlock and a backup-code rotation.
 */
export function DeviceDetailPage() {
    const { deviceId = '' } = useParams();
    const { can } = useAuth();
    const queryClient = useQueryClient();
    const [issuedCodes, setIssuedCodes] = useState<string[] | null>(null);
    const [unlockCommandId, setUnlockCommandId] = useState<string | null>(null);
    const [editingDetails, setEditingDetails] = useState(false);
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
            const { data } = await api.post<{ command: DeviceCommand }>(`/devices/${deviceId}/unlock`, {
                reason: 'Dashboard remote unlock',
            });

            return data.command;
        },
        onSuccess: (command) => {
            setUnlockCommandId(command.id);
            invalidate();
        },
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

    const saveDetails = useMutation({
        mutationFn: async (payload: DeviceDetailsPayload) => {
            await ensureCsrfCookie();
            await api.patch(`/devices/${deviceId}`, payload);
        },
        onSuccess: () => {
            setEditingDetails(false);
            invalidate();
            void queryClient.invalidateQueries({ queryKey: ['devices'] });
        },
    });

    // The device API admits only an active panel. A new one starts
    // provisioned and stays refused until somebody decides to let it in;
    // deactivating is the same switch in the other direction.
    const setStatus = useMutation({
        mutationFn: async (status: 'active' | 'suspended') => {
            await ensureCsrfCookie();
            await api.patch(`/devices/${deviceId}`, { status });
        },
        onSuccess: () => {
            invalidate();
            void queryClient.invalidateQueries({ queryKey: ['devices'] });
        },
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
                    {can('device.update') && (
                        <Button
                            variant="ghost"
                            onClick={() => {
                                saveDetails.reset();
                                setEditingDetails((open) => !open);
                            }}
                        >
                            {editingDetails ? 'Cancel editing' : 'Edit details'}
                        </Button>
                    )}
                    {can('device.update') && device.status !== 'active' && (
                        <Button
                            variant="primary"
                            loading={setStatus.isPending}
                            onClick={() => setStatus.mutate('active')}
                        >
                            Activate
                        </Button>
                    )}
                    {can('device.update') && device.status === 'active' && (
                        <Button
                            variant="danger"
                            loading={setStatus.isPending}
                            onClick={() => {
                                if (
                                    window.confirm(
                                        `Deactivate ${device.name}? The panel is refused by the portal from its next request: OTP and backup codes stop working there, and it shows "No service". Fingerprints stored in its sensor still open the door.`,
                                    )
                                ) {
                                    setStatus.mutate('suspended');
                                }
                            }}
                        >
                            Deactivate
                        </Button>
                    )}
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
                            onClick={() => {
                                if (
                                    window.confirm(
                                        `Rotate the backup codes for ${device.name}? You see the new codes once, so download them. The door keeps accepting the current codes until it collects the new set at its next sync.`,
                                    )
                                ) {
                                    rotateCodes.mutate();
                                }
                            }}
                        >
                            Rotate backup codes
                        </Button>
                    )}
                </div>
            </header>

            {device.status !== 'active' && (
                <p
                    role="status"
                    className="panel px-4 py-2 text-xs"
                    style={{ borderColor: 'var(--warn)', color: 'var(--warn-text)' }}
                >
                    <span aria-hidden="true">! </span>
                    {device.status === 'provisioned'
                        ? 'This door is not activated yet. The portal refuses it until you press Activate, so its status bar shows "No service".'
                        : 'This door is deactivated. The portal refuses it, so OTP and backup codes do not work there. Press Activate to let it back in.'}
                </p>
            )}

            {editingDetails && (
                <Panel title="Device details" className="p-4">
                    <DeviceDetailsForm
                        device={device}
                        pending={saveDetails.isPending}
                        error={saveDetails.error ? toApiError(saveDetails.error) : null}
                        onSubmit={(payload) => saveDetails.mutate(payload)}
                    />
                </Panel>
            )}

            {device.timezone === null && !editingDetails && can('device.update') && (
                <p
                    role="status"
                    className="panel px-4 py-2 text-xs"
                    style={{ borderColor: 'var(--warn)', color: 'var(--warn-text)' }}
                >
                    <span aria-hidden="true">! </span>
                    This door has no timezone yet. Press <strong>Edit details</strong> to set one
                    {device.tenant?.timezone ? ` (the tenant's is ${device.tenant.timezone})` : ''}.
                </p>
            )}

            {setStatus.error && (
                <p role="alert" className="text-xs" style={{ color: 'var(--danger-text)' }}>
                    {toApiError(setStatus.error).message}
                </p>
            )}

            {unlockCommandId && (
                <UnlockProgress
                    deviceId={deviceId}
                    commandId={unlockCommandId}
                    onDismiss={() => setUnlockCommandId(null)}
                />
            )}

            {/* Shown once, and nowhere else afterwards. */}
            {issuedCodes && (
                <RevealOnce
                    title="New backup codes"
                    warning="These are shown once. Download them or write them down before you close this."
                    onDismiss={() => setIssuedCodes(null)}
                >
                    <p className="text-xs">
                        They start working when the door collects them at its next sync: at start-up,
                        then every Fingerprint Sync Interval (3 hours by default). Switch the door off
                        and on to deliver them now. Until then the door keeps accepting the previous
                        codes. Hand these out once the <strong>Backup codes</strong> panel below shows{' '}
                        <strong>On the door</strong>.
                    </p>
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
                    <div>
                        <Button onClick={() => downloadCodes(device.name, issuedCodes)}>
                            Download codes
                        </Button>
                    </div>
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
                        <Row label="Realtime" value={<RealtimeState device={device} />} />
                        <Row label="Timezone" value={device.timezone ?? 'Not set'} />
                        <Row
                            label="Templates stored"
                            value={`${device.enrolled_count} of ${device.template_capacity}`}
                        />
                        <Row label="Administrative state" value={ADMIN_STATE_LABEL[device.status]} />
                    </dl>
                </Panel>

                <Panel
                    title="Backup codes"
                    description="The door collects its set at start-up and with every fingerprint sync"
                    className="p-4"
                >
                    {device.active_backup_code_set ? (
                        <div className="flex flex-col gap-2 text-xs">
                            <Row
                                label="Issued"
                                value={formatDateTime(device.active_backup_code_set.issued_at)}
                            />
                            <Row
                                label="Why"
                                value={backupReasonLabel(device.active_backup_code_set.reason)}
                            />
                            <div className="flex items-baseline justify-between gap-3">
                                <dt style={{ color: 'var(--text-muted)' }}>Delivery</dt>
                                <dd>
                                    <BackupDeliveryBadge set={device.active_backup_code_set} />
                                </dd>
                            </div>
                            {device.active_backup_code_set.fetched_at && (
                                <Row
                                    label="On the door since"
                                    value={formatDateTime(device.active_backup_code_set.fetched_at)}
                                />
                            )}
                            {device.active_backup_code_set.last_fetched_at && (
                                <Row
                                    label="Last collected"
                                    value={formatRelative(device.active_backup_code_set.last_fetched_at)}
                                />
                            )}
                            {!backupSetWasSeen(device.active_backup_code_set.reason) && (
                                <p role="note" style={{ color: 'var(--warn-text)' }}>
                                    <span aria-hidden="true">! </span>
                                    Nobody has seen these codes: the portal made this set for the door
                                    itself. Rotate to get codes you can hand out.
                                </p>
                            )}
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
                            No set yet. The door gets one at its first sync. Rotate to get codes you
                            can hand out.
                        </p>
                    )}
                </Panel>

                <Panel
                    title="Queued commands"
                    description="Delivered at the panel's next poll, or within seconds over its realtime connection"
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

            {device.template_sync && (
                <TemplateSyncPanel sync={device.template_sync} capacity={device.template_capacity} />
            )}

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
                                                                        'Revoke this enrolment? The panel deletes the template at its next template sync — until then the finger still opens the door.',
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

/**
 * The panel mirrors the template set it is sent, deleting anything unlisted.
 * This is where an operator sees whether that is working: the last report,
 * what failed, where the sensor and the backend disagree, and whether the set
 * had to be cut to fit the sensor.
 */
function TemplateSyncPanel({ sync, capacity }: { sync: TemplateSync; capacity: number }) {
    const report = sync.last_report ?? null;
    const drift = report?.drift ?? null;
    const hasDrift = drift !== null && (drift.missing_slots.length > 0 || drift.unknown_slots.length > 0);

    return (
        <Panel
            title="Template sync"
            description="The panel holds exactly the templates it is sent, and deletes any slot not listed"
            className="p-4"
        >
            <div className="flex flex-col gap-3 text-xs">
                <div className="flex flex-wrap items-center gap-2">
                    <TemplateSyncBadge status={report?.status ?? null} />
                    {sync.truncated && (
                        <Badge tone="warn" glyph="!">
                            Over capacity — some members left off
                        </Badge>
                    )}
                    {sync.deferred > 0 && (
                        <Badge tone="warn" glyph="◔">
                            {sync.deferred} waiting for a free slot
                        </Badge>
                    )}
                    {hasDrift && (
                        <Badge tone="danger" glyph="≠">
                            Sensor and backend disagree
                        </Badge>
                    )}
                </div>

                <dl className="grid gap-2 sm:grid-cols-2">
                    <Row label="Last report" value={formatRelative(report?.received_at ?? null)} />
                    <Row label="Last set served" value={formatRelative(sync.served_at)} />
                    <Row
                        label="Templates in set"
                        value={sync.total === null ? 'Never synced' : `${sync.total} of ${capacity}`}
                    />
                    <Row
                        label="Slots awaiting deletion"
                        value={sync.releasing_slots === undefined ? '—' : String(sync.releasing_slots)}
                    />
                    {report && (
                        <>
                            <Row
                                label="Written · unchanged · deleted"
                                value={`${report.written ?? 0} · ${report.unchanged ?? 0} · ${report.deleted ?? 0}`}
                            />
                            <Row label="Failed" value={String(report.failed)} />
                        </>
                    )}
                    {report?.abort_reason && <Row label="Aborted because" value={humanise(report.abort_reason)} />}
                </dl>

                {report && report.errors.length > 0 && (
                    <div>
                        <h3 className="mb-1 font-medium">Failures</h3>
                        <ul className="flex flex-wrap gap-1.5">
                            {report.errors.map((error, index) => (
                                <li key={`${error.slot}-${index}`}>
                                    <Badge tone="danger" glyph="✕">
                                        Slot {error.slot === 65535 ? '?' : error.slot}: {humanise(error.error)}
                                    </Badge>
                                </li>
                            ))}
                        </ul>
                        {report.failed > report.errors.length && (
                            <p className="mt-1" style={{ color: 'var(--text-muted)' }}>
                                …and {report.failed - report.errors.length} more.
                            </p>
                        )}
                    </div>
                )}

                {hasDrift && drift && (
                    <dl className="flex flex-col gap-1">
                        {drift.missing_slots.length > 0 && (
                            <Row
                                label="Should hold a template, but empty"
                                value={drift.missing_slots.join(', ')}
                            />
                        )}
                        {drift.unknown_slots.length > 0 && (
                            <Row
                                label="Occupied, but nobody owns it here"
                                value={drift.unknown_slots.join(', ')}
                            />
                        )}
                        <p style={{ color: 'var(--text-muted)' }}>
                            Not corrected automatically — the next sync tries again.
                        </p>
                    </dl>
                )}
            </div>
        </Panel>
    );
}

/** Matches the wording of the Activate / Deactivate buttons. */
const ADMIN_STATE_LABEL: Record<Device['status'], string> = {
    provisioned: 'Not activated',
    active: 'Active',
    suspended: 'Deactivated',
    retired: 'Retired',
};

/**
 * Save the codes from the one dashboard response that carries them. Built in
 * the browser from data already on screen, so the server never sends them to
 * an operator twice.
 */
function downloadCodes(deviceName: string, codes: string[]): void {
    const stamp = new Date().toISOString().slice(0, 16).replace('T', ' ');
    const text = [
        `Backup codes for ${deviceName}`,
        `Issued ${stamp} (UTC)`,
        '',
        ...codes,
        '',
        'They start working once the door has collected them (its next sync,',
        'or at once after a restart).',
        'Using any one code ends all five. Rotate again for new codes.',
        '',
    ].join('\n');

    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `backup-codes-${deviceName.replace(/[^A-Za-z0-9_-]+/g, '-')}.txt`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

interface DeviceDetailsPayload {
    name: string;
    location: string | null;
    timezone: string;
}

/**
 * Name, location and timezone. A device with no timezone yet starts from its
 * tenant's, which the operator can keep or change.
 */
function DeviceDetailsForm({
    device,
    pending,
    error,
    onSubmit,
}: {
    device: Device;
    pending: boolean;
    error: ApiError | null;
    onSubmit(payload: DeviceDetailsPayload): void;
}) {
    const tenantTimezone = device.tenant?.timezone ?? null;
    const [name, setName] = useState(device.name);
    const [location, setLocation] = useState(device.location ?? '');
    const [timezone, setTimezone] = useState(device.timezone ?? tenantTimezone ?? '');

    let hint = "Where this door is. Usually the tenant's; change it for a door somewhere else.";

    if (device.timezone === null) {
        hint = tenantTimezone
            ? `Not set yet, so the tenant's (${tenantTimezone}) is selected. Save to keep it.`
            : 'Not set yet, and the tenant has none either. Choose one.';
    }

    return (
        <form
            className="grid gap-3 sm:grid-cols-3"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({ name, location: location === '' ? null : location, timezone });
            }}
        >
            <Field id="device_name" label="Name" error={error?.fieldError('name')}>
                <Input
                    id="device_name"
                    required
                    maxLength={120}
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                />
            </Field>

            <Field id="device_location" label="Location" error={error?.fieldError('location')}>
                <Input
                    id="device_location"
                    maxLength={160}
                    value={location}
                    onChange={(event) => setLocation(event.target.value)}
                />
            </Field>

            <Field id="device_timezone" label="Timezone" hint={hint} error={error?.fieldError('timezone')}>
                <TimezoneSelect id="device_timezone" required value={timezone} onChange={setTimezone} />
            </Field>

            <div className="sm:col-span-3">
                <Button type="submit" variant="primary" loading={pending}>
                    Save details
                </Button>
                {error && Object.keys(error.fields).length === 0 && (
                    <span role="alert" className="ml-3 text-xs" style={{ color: 'var(--danger-text)' }}>
                        {error.message}
                    </span>
                )}
            </div>
        </form>
    );
}

/**
 * Follows one remote unlock from the button to the door: waiting, then opened
 * or did not open, as the panel acknowledges it (device API 1.4.0). The access
 * log records the opening only once the panel confirms it.
 */
function UnlockProgress({
    deviceId,
    commandId,
    onDismiss,
}: {
    deviceId: string;
    commandId: string;
    onDismiss: () => void;
}) {
    const commandQuery = useQuery({
        queryKey: ['device-command', commandId],
        queryFn: async () => {
            const { data } = await api.get<Paginated<DeviceCommand>>('/device-commands', {
                params: { device_id: deviceId, per_page: 25 },
            });

            return data.data.find((command) => command.id === commandId) ?? null;
        },
        refetchInterval: (query) => {
            const command = query.state.data;

            return command && ['acked', 'failed', 'expired'].includes(command.status) ? false : 2_000;
        },
    });

    const command = commandQuery.data;
    const expired =
        command?.status === 'expired' ||
        (command?.expires_at != null &&
            ['pending', 'sent'].includes(command.status) &&
            Date.parse(command.expires_at) < Date.now());

    let state: ReactNode;

    if (command?.status === 'acked') {
        state = (
            <Badge tone="ok" glyph="✓">
                Door opened
            </Badge>
        );
    } else if (command?.status === 'failed') {
        state = (
            <Badge tone="danger" glyph="✕">
                Did not open: {unlockFailureLabel(command.result)}
            </Badge>
        );
    } else if (expired) {
        state = (
            <Badge tone="neutral" glyph="—">
                Did not open: the door did not collect it in time
            </Badge>
        );
    } else {
        state = (
            <Badge tone="warn" glyph="◔">
                Waiting for the door…
            </Badge>
        );
    }

    return (
        <div role="status" className="panel flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-xs">
            <span className="flex flex-wrap items-center gap-2">
                <span>Remote unlock</span>
                {state}
            </span>
            <button type="button" className="underline underline-offset-2" onClick={onDismiss}>
                Dismiss
            </button>
        </div>
    );
}

function unlockFailureLabel(result: Record<string, unknown> | null): string {
    switch (result?.error) {
        case 'busy':
            return 'the panel was busy';
        case 'expired':
            return 'the request expired before the panel could act';
        case 'unsupported':
            return 'this panel cannot unlock remotely';
        default:
            return typeof result?.error === 'string' ? humanise(result.error) : 'no reason given';
    }
}

/** The MQTT doorbell, as the panel last reported it. */
function RealtimeState({ device }: { device: Device }) {
    const realtime = device.realtime;

    if (realtime?.connected == null) {
        return <span>Not reported</span>;
    }

    const reconnects =
        realtime.reconnects == null
            ? ''
            : ` · ${realtime.reconnects} reconnect${realtime.reconnects === 1 ? '' : 's'}`;

    return realtime.connected ? (
        <Badge tone="ok" glyph="●">
            Connected{reconnects}
        </Badge>
    ) : (
        <Badge tone="warn" glyph="○">
            Not connected{reconnects}
        </Badge>
    );
}

function Row({ label, value }: { label: string; value: ReactNode }) {
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
