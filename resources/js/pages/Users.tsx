import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie, toApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatRelative, humanise } from '@/lib/format';
import {
    Badge,
    Button,
    EmptyState,
    ErrorState,
    Field,
    Input,
    LoadingRows,
    Panel,
    Select,
    TableWrap,
    Td,
    Th,
} from '@/components/ui';
import type { Paginated, User } from '@/types';

interface RoleDefinition {
    name: string;
    permissions: string[];
}

/**
 * Dashboard operators and what each role can do.
 *
 * Invitations set no password: the invitee follows a reset link, so a password
 * never travels through this API or sits in an inbox.
 */
export function UsersPage() {
    const { can, user: currentUser } = useAuth();
    const queryClient = useQueryClient();
    const [inviting, setInviting] = useState(false);

    const usersQuery = useQuery({
        queryKey: ['users'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<User>>('/users', {
                params: { per_page: 100 },
            });

            return data;
        },
    });

    const rolesQuery = useQuery({
        queryKey: ['roles'],
        queryFn: async () => {
            const { data } = await api.get<{ data: RoleDefinition[] }>('/roles');

            return data.data;
        },
    });

    const invite = useMutation({
        mutationFn: async (payload: { name: string; email: string; roles: string[] }) => {
            await ensureCsrfCookie();
            await api.post('/users', payload);
        },
        onSuccess: () => {
            setInviting(false);
            void queryClient.invalidateQueries({ queryKey: ['users'] });
        },
    });

    const changeRole = useMutation({
        mutationFn: async ({ id, role }: { id: string; role: string }) => {
            await ensureCsrfCookie();
            await api.patch(`/users/${id}`, { roles: [role] });
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['users'] }),
    });

    return (
        <div className="flex flex-col gap-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold">Users &amp; roles</h1>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        Operators who sign in here. Distinct from members, who walk through doors.
                    </p>
                </div>

                {can('user.invite') && (
                    <Button variant="primary" onClick={() => setInviting((open) => !open)}>
                        {inviting ? 'Cancel' : 'Invite user'}
                    </Button>
                )}
            </header>

            {inviting && (
                <Panel title="Invite an operator" className="p-4">
                    <InviteForm
                        roles={rolesQuery.data ?? []}
                        pending={invite.isPending}
                        error={invite.error ? toApiError(invite.error) : null}
                        onSubmit={(payload) => invite.mutate(payload)}
                    />
                </Panel>
            )}

            <Panel title="Operators">
                {usersQuery.error && (
                    <ErrorState
                        message={toApiError(usersQuery.error).message}
                        retry={() => void usersQuery.refetch()}
                    />
                )}
                {usersQuery.isPending && <LoadingRows />}

                {usersQuery.data?.data.length === 0 && <EmptyState title="No operators yet" />}

                {(usersQuery.data?.data.length ?? 0) > 0 && (
                    <TableWrap>
                        <table className="w-full text-sm">
                            <thead style={{ background: 'var(--surface-sunken)' }}>
                                <tr>
                                    <Th>Name</Th>
                                    <Th>Role</Th>
                                    <Th>Two-factor</Th>
                                    <Th>Last signed in</Th>
                                    <Th>Status</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {usersQuery.data?.data.map((user) => (
                                    <tr key={user.id} style={{ borderTop: '1px solid var(--border)' }}>
                                        <Td>
                                            <div className="font-medium">{user.name}</div>
                                            <div className="text-xs" style={{ color: 'var(--text-subtle)' }}>
                                                {user.email}
                                            </div>
                                        </Td>
                                        <Td>
                                            {/* Nobody edits their own role — that
                                                is how a device_manager quietly
                                                becomes a tenant_admin. */}
                                            {can('role.manage') && currentUser?.id !== user.id ? (
                                                <Select
                                                    aria-label={`Role for ${user.name}`}
                                                    value={user.roles?.[0] ?? ''}
                                                    onChange={(event) =>
                                                        changeRole.mutate({
                                                            id: user.id,
                                                            role: event.target.value,
                                                        })
                                                    }
                                                >
                                                    {rolesQuery.data?.map((role) => (
                                                        <option key={role.name} value={role.name}>
                                                            {humanise(role.name)}
                                                        </option>
                                                    ))}
                                                </Select>
                                            ) : (
                                                humanise(user.roles?.[0] ?? 'none')
                                            )}
                                        </Td>
                                        <Td>
                                            {user.two_factor_enabled ? (
                                                <Badge tone="ok" glyph="✓">
                                                    Enabled
                                                </Badge>
                                            ) : user.two_factor_required ? (
                                                <Badge tone="danger" glyph="!">
                                                    Required, not set up
                                                </Badge>
                                            ) : (
                                                <Badge tone="neutral" glyph="—">
                                                    Not enabled
                                                </Badge>
                                            )}
                                        </Td>
                                        <Td style={{ color: 'var(--text-muted)' }}>
                                            {formatRelative(user.last_login_at)}
                                        </Td>
                                        <Td>
                                            {user.status === 'active' ? (
                                                <Badge tone="ok" glyph="✓">
                                                    Active
                                                </Badge>
                                            ) : (
                                                <Badge tone="warn" glyph="◔">
                                                    {humanise(user.status)}
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

            {/* --------------------------------------------------------- */}
            {/* Permission matrix                                          */}
            {/* --------------------------------------------------------- */}
            <Panel
                title="What each role can do"
                description="Permissions are checked individually by policies; a role is only a bundle of them."
            >
                <PermissionMatrix roles={rolesQuery.data ?? []} />
            </Panel>
        </div>
    );
}

function InviteForm({
    roles,
    pending,
    error,
    onSubmit,
}: {
    roles: RoleDefinition[];
    pending: boolean;
    error: ReturnType<typeof toApiError> | null;
    onSubmit(payload: { name: string; email: string; roles: string[] }): void;
}) {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [role, setRole] = useState('operator');

    return (
        <form
            className="grid gap-3 sm:grid-cols-3"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({ name, email, roles: [role] });
            }}
        >
            <Field id="invite_name" label="Name" error={error?.fieldError('name')}>
                <Input
                    id="invite_name"
                    required
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                />
            </Field>

            <Field id="invite_email" label="Email" error={error?.fieldError('email')}>
                <Input
                    id="invite_email"
                    type="email"
                    required
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                />
            </Field>

            <Field
                id="invite_role"
                label="Role"
                hint="Device and member management require a second factor."
                error={error?.fieldError('roles')}
            >
                <Select
                    id="invite_role"
                    value={role}
                    onChange={(event) => setRole(event.target.value)}
                >
                    {roles.map((definition) => (
                        <option key={definition.name} value={definition.name}>
                            {humanise(definition.name)}
                        </option>
                    ))}
                </Select>
            </Field>

            <div className="sm:col-span-3">
                <Button type="submit" variant="primary" loading={pending}>
                    Send invitation
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

function PermissionMatrix({ roles }: { roles: RoleDefinition[] }) {
    if (roles.length === 0) {
        return <LoadingRows rows={4} />;
    }

    const permissions = [...new Set(roles.flatMap((role) => role.permissions))].sort();

    return (
        <TableWrap>
            <table className="w-full text-xs">
                <thead style={{ background: 'var(--surface-sunken)' }}>
                    <tr>
                        <Th>Permission</Th>
                        {roles.map((role) => (
                            <Th key={role.name} className="text-center">
                                {humanise(role.name)}
                            </Th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {permissions.map((permission) => (
                        <tr key={permission} style={{ borderTop: '1px solid var(--border)' }}>
                            <Td className="font-mono">{permission}</Td>
                            {roles.map((role) => {
                                const granted = role.permissions.includes(permission);

                                return (
                                    <Td key={role.name} className="text-center">
                                        {/* A glyph, not a coloured cell: this
                                            table has to be readable in
                                            greyscale and by a screen reader. */}
                                        <span
                                            aria-hidden="true"
                                            style={{
                                                color: granted
                                                    ? 'var(--ok-text)'
                                                    : 'var(--text-subtle)',
                                            }}
                                        >
                                            {granted ? '✓' : '·'}
                                        </span>
                                        <span className="sr-only">
                                            {granted ? 'granted' : 'not granted'}
                                        </span>
                                    </Td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </TableWrap>
    );
}
