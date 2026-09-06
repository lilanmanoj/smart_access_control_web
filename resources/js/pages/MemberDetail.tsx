import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, ensureCsrfCookie, toApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime } from '@/lib/format';
import { EnrollmentStatusBadge, MemberStatusBadge } from '@/components/status';
import {
    Button,
    ErrorState,
    LoadingRows,
    Panel,
    TableWrap,
    Td,
    Th,
} from '@/components/ui';
import { MemberForm } from './Members';
import type { Member } from '@/types';

export function MemberDetailPage() {
    const { memberId = '' } = useParams();
    const { can } = useAuth();
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['member', memberId],
        queryFn: async () => {
            const { data } = await api.get<{ data: Member }>(`/members/${memberId}`);

            return data.data;
        },
    });

    const update = useMutation({
        mutationFn: async (payload: Record<string, unknown>) => {
            await ensureCsrfCookie();
            await api.patch(`/members/${memberId}`, payload);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['member', memberId] });
            void queryClient.invalidateQueries({ queryKey: ['members'] });
        },
    });

    const remove = useMutation({
        mutationFn: async () => {
            await ensureCsrfCookie();
            await api.delete(`/members/${memberId}`);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['members'] });
            navigate('/members');
        },
    });

    const revoke = useMutation({
        mutationFn: async (enrollmentId: string) => {
            await ensureCsrfCookie();
            await api.delete(`/enrollments/${enrollmentId}`);
        },
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: ['member', memberId] });
        },
    });

    if (error) {
        return <ErrorState message={toApiError(error).message} retry={() => void refetch()} />;
    }

    if (isPending) {
        return <LoadingRows rows={6} />;
    }

    return (
        <div className="flex flex-col gap-4">
            <header>
                <Link
                    to="/members"
                    className="text-xs underline underline-offset-2"
                    style={{ color: 'var(--text-muted)' }}
                >
                    ← All members
                </Link>
                <h1 className="mt-1 flex flex-wrap items-center gap-2 text-lg font-semibold">
                    {data.full_name}
                    <MemberStatusBadge status={data.status} isAdmin={data.is_admin} />
                </h1>
                <p className="text-xs" style={{ color: 'var(--text-subtle)' }}>
                    Added {formatDateTime(data.created_at)}
                </p>
            </header>

            {can('member.update') && (
                <Panel title="Details" className="p-4">
                    <MemberForm
                        initial={data}
                        pending={update.isPending}
                        error={update.error ? toApiError(update.error) : null}
                        onSubmit={(payload) => update.mutate(payload)}
                    />
                </Panel>
            )}

            <Panel
                title="Enrolments"
                description="One row per door. Templates live on the sensor, so revoking queues an erase rather than deleting anything here."
            >
                {(data.enrollments?.length ?? 0) === 0 ? (
                    <p className="px-4 py-6 text-xs" style={{ color: 'var(--text-muted)' }}>
                        Not enrolled on any device. This member can still be admitted by OTP if they
                        have a phone number on record.
                    </p>
                ) : (
                    <TableWrap>
                        <table className="w-full text-sm">
                            <thead style={{ background: 'var(--surface-sunken)' }}>
                                <tr>
                                    <Th>Device</Th>
                                    <Th>Slot</Th>
                                    <Th>State</Th>
                                    <Th>Enrolled</Th>
                                    <Th>{can('enrollment.revoke') ? 'Actions' : ''}</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.enrollments?.map((enrollment) => (
                                    <tr key={enrollment.id} style={{ borderTop: '1px solid var(--border)' }}>
                                        <Td>
                                            {enrollment.device ? (
                                                <Link
                                                    to={`/devices/${enrollment.device.id}`}
                                                    className="underline underline-offset-2"
                                                >
                                                    {enrollment.device.name}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </Td>
                                        <Td className="tabular-nums">
                                            {enrollment.fingerprint_slot ?? (
                                                <span style={{ color: 'var(--text-subtle)' }}>phone only</span>
                                            )}
                                        </Td>
                                        <Td>
                                            <EnrollmentStatusBadge status={enrollment.status} />
                                        </Td>
                                        <Td style={{ color: 'var(--text-muted)' }}>
                                            {formatDateTime(enrollment.enrolled_at)}
                                        </Td>
                                        <Td>
                                            {can('enrollment.revoke') && enrollment.status !== 'revoked' && (
                                                <Button
                                                    variant="danger"
                                                    onClick={() => {
                                                        if (
                                                            window.confirm(
                                                                'Revoke this enrolment? The erase reaches the sensor at the device’s next poll; until then the finger still works.',
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

            {can('member.delete') && (
                <Panel title="Danger zone" className="p-4">
                    <p className="mb-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                        Deleting keeps the member's access history intact — the log has to keep
                        naming them — but removes them from every list and refuses them at every
                        door.
                    </p>
                    <Button
                        variant="danger"
                        loading={remove.isPending}
                        onClick={() => {
                            if (window.confirm(`Delete ${data.full_name}?`)) {
                                remove.mutate();
                            }
                        }}
                    >
                        Delete member
                    </Button>
                </Panel>
            )}
        </div>
    );
}
