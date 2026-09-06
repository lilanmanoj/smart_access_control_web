import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api, ensureCsrfCookie, toApiError, type ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { MemberStatusBadge } from '@/components/status';
import {
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
import type { Member, Paginated } from '@/types';

/**
 * Members are the people who walk through doors — deliberately separate from
 * dashboard users, since a member may have no login at all.
 */
export function MembersPage() {
    const { can } = useAuth();
    const queryClient = useQueryClient();
    const [search, setSearch] = useState('');
    const [adding, setAdding] = useState(false);

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['members', search],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Member>>('/members', {
                params: { search: search || undefined, per_page: 50 },
            });

            return data;
        },
    });

    const create = useMutation({
        mutationFn: async (payload: Record<string, unknown>) => {
            await ensureCsrfCookie();
            await api.post('/members', payload);
        },
        onSuccess: () => {
            setAdding(false);
            void queryClient.invalidateQueries({ queryKey: ['members'] });
        },
    });

    return (
        <div className="flex flex-col gap-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold">Members</h1>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        The people who walk through doors. A member needs no login.
                    </p>
                </div>

                {can('member.create') && (
                    <Button variant="primary" onClick={() => setAdding((open) => !open)}>
                        {adding ? 'Cancel' : 'Add member'}
                    </Button>
                )}
            </header>

            {adding && (
                <Panel title="New member" className="p-4">
                    <MemberForm
                        pending={create.isPending}
                        error={create.error ? toApiError(create.error) : null}
                        onSubmit={(payload) => create.mutate(payload)}
                    />
                </Panel>
            )}

            <Panel
                title={`${data?.meta.total ?? 0} member${data?.meta.total === 1 ? '' : 's'}`}
                actions={
                    <Input
                        aria-label="Search members"
                        placeholder="Search name, phone or email"
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

                {data?.data.length === 0 && (
                    <EmptyState
                        title="No members"
                        description="Members are usually created at the panel during enrolment, and can also be added here for the OTP flow."
                    />
                )}

                {(data?.data.length ?? 0) > 0 && (
                    <TableWrap>
                        <table className="w-full text-sm">
                            <thead style={{ background: 'var(--surface-sunken)' }}>
                                <tr>
                                    <Th>Name</Th>
                                    <Th>Phone</Th>
                                    <Th>Status</Th>
                                    <Th>Enrolments</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {data?.data.map((member) => (
                                    <tr key={member.id} style={{ borderTop: '1px solid var(--border)' }}>
                                        <Td>
                                            <Link
                                                to={`/members/${member.id}`}
                                                className="font-medium underline underline-offset-2"
                                            >
                                                {member.full_name}
                                            </Link>
                                            {member.email && (
                                                <div className="text-xs" style={{ color: 'var(--text-subtle)' }}>
                                                    {member.email}
                                                </div>
                                            )}
                                        </Td>
                                        <Td className="font-mono text-xs">{member.phone ?? '—'}</Td>
                                        <Td>
                                            <MemberStatusBadge
                                                status={member.status}
                                                isAdmin={member.is_admin}
                                            />
                                        </Td>
                                        <Td className="tabular-nums">
                                            {member.enrollments_count ?? 0}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </TableWrap>
                )}
            </Panel>
        </div>
    );
}

export function MemberForm({
    initial,
    pending,
    error,
    onSubmit,
    submitLabel = 'Save member',
}: {
    initial?: Partial<Member>;
    pending: boolean;
    error: ApiError | null;
    onSubmit(payload: Record<string, unknown>): void;
    submitLabel?: string;
}) {
    const [fullName, setFullName] = useState(initial?.full_name ?? '');
    const [phone, setPhone] = useState(initial?.phone ?? '');
    const [email, setEmail] = useState(initial?.email ?? '');
    const [isAdmin, setIsAdmin] = useState(initial?.is_admin ?? false);
    const [suspended, setSuspended] = useState(initial?.status === 'suspended');
    const [notes, setNotes] = useState(initial?.notes ?? '');

    return (
        <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({
                    full_name: fullName,
                    phone: phone || null,
                    email: email || null,
                    is_admin: isAdmin,
                    status: suspended ? 'suspended' : 'active',
                    notes: notes || null,
                });
            }}
        >
            <Field id="full_name" label="Full name" error={error?.fieldError('full_name')}>
                <Input
                    id="full_name"
                    required
                    value={fullName}
                    onChange={(event) => setFullName(event.target.value)}
                />
            </Field>

            <Field
                id="phone"
                label="Phone"
                hint="Stored in international format. The panel's own field holds 14 characters, so anything longer is refused."
                error={error?.fieldError('phone')}
            >
                <Input
                    id="phone"
                    inputMode="tel"
                    placeholder="0771234567"
                    value={phone ?? ''}
                    onChange={(event) => setPhone(event.target.value)}
                />
            </Field>

            <Field
                id="email"
                label="Email"
                hint="Used to deliver one-time codes alongside SMS."
                error={error?.fieldError('email')}
            >
                <Input
                    id="email"
                    type="email"
                    value={email ?? ''}
                    onChange={(event) => setEmail(event.target.value)}
                />
            </Field>

            <Field id="notes" label="Notes" error={error?.fieldError('notes')}>
                <Input id="notes" value={notes ?? ''} onChange={(event) => setNotes(event.target.value)} />
            </Field>

            <label className="flex items-start gap-2 text-xs sm:col-span-2">
                <input
                    type="checkbox"
                    checked={isAdmin}
                    className="mt-0.5"
                    onChange={(event) => setIsAdmin(event.target.checked)}
                />
                <span>
                    <strong>Device administrator.</strong> Lets this person's fingerprint open the
                    panel's own settings portal. It is not an extra way through the door.
                </span>
            </label>

            <label className="flex items-start gap-2 text-xs sm:col-span-2">
                <input
                    type="checkbox"
                    checked={suspended}
                    className="mt-0.5"
                    onChange={(event) => setSuspended(event.target.checked)}
                />
                <span>
                    <strong>Suspended.</strong> Refused at every door immediately, without erasing
                    their enrolments.
                </span>
            </label>

            <div className="sm:col-span-2">
                <Button type="submit" variant="primary" loading={pending}>
                    {submitLabel}
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
