import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie, toApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatRelative, humanise } from '@/lib/format';
import { useTenantOptions } from '@/lib/tenants';
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
import type { Paginated, Tenant, User } from '@/types';

interface RoleDefinition {
    name: string;
    permissions: string[];
}

type ApiError = ReturnType<typeof toApiError>;

interface InvitePayload {
    name: string;
    email: string;
    roles: string[];
    tenant_id?: string;
}

/** Only the fields that changed are sent, so each one is checked against its own permission. */
interface UserChanges {
    name?: string;
    email?: string;
    roles?: string[];
    status?: 'active' | 'suspended';
    tenant_id?: string;
}

/**
 * Dashboard operators and what each role can do.
 *
 * Invitations set no password: the invitee follows a reset link, so a password
 * never travels through this API or sits in an inbox.
 *
 * A tenant admin sees and edits the operators of their own tenant. Choosing or
 * changing someone's tenant takes `tenant.manage`, which by default only a
 * SuperAdmin holds. The API enforces all of this; the page only avoids offering
 * what would be refused.
 */
export function UsersPage() {
    const { can, user: currentUser, impersonatedTenantId, refresh } = useAuth();
    const queryClient = useQueryClient();
    const [inviting, setInviting] = useState(false);
    const [editing, setEditing] = useState<User | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    // Gated on the permission, matching how the API decides who may name a tenant.
    const mayChooseTenant = can('tenant.manage');
    // The list is cross-tenant only for a SuperAdmin, so only they need the column.
    const showTenantColumn = currentUser?.is_super_admin === true;
    // A SuperAdmin viewing every tenant has none bound, so an invite must name one.
    const inFleetView = currentUser?.is_super_admin === true && impersonatedTenantId === null;

    const tenantsQuery = useTenantOptions();
    // A deactivated tenant is refused as a target, so it is not offered.
    const activeTenants = mayChooseTenant
        ? (tenantsQuery.data ?? []).filter((tenant) => tenant.status === 'active')
        : [];

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
        mutationFn: async (payload: InvitePayload) => {
            await ensureCsrfCookie();
            await api.post('/users', payload);

            return payload.email;
        },
        onSuccess: (email) => {
            setInviting(false);
            setNotice(`Invitation sent to ${email}. They choose their own password from the link in it.`);
            void queryClient.invalidateQueries({ queryKey: ['users'] });
            void queryClient.invalidateQueries({ queryKey: ['tenants'] });
        },
    });

    const resend = useMutation({
        mutationFn: async (target: User) => {
            await ensureCsrfCookie();
            await api.post(`/users/${target.id}/invitations`);

            return target.email;
        },
        onSuccess: (email) => setNotice(`A new invitation was sent to ${email}. Earlier links no longer work.`),
    });

    const update = useMutation({
        mutationFn: async ({ target, changes }: { target: User; changes: UserChanges }) => {
            await ensureCsrfCookie();
            const { data } = await api.patch<{ data: User }>(`/users/${target.id}`, changes);

            return { before: target, after: data.data, changes };
        },
        onSuccess: ({ before, after, changes }) => {
            setEditing(null);
            setNotice(
                changes.email && after.status === 'invited'
                    ? `Saved. A fresh invitation went to ${after.email}; the earlier link no longer works.`
                    : `Saved changes to ${after.name}.`,
            );
            void queryClient.invalidateQueries({ queryKey: ['users'] });
            if (changes.tenant_id !== undefined || changes.roles !== undefined) {
                void queryClient.invalidateQueries({ queryKey: ['tenants'] });
            }
            // The header shows the signed-in operator's own name.
            if (currentUser?.id === before.id) {
                void refresh();
            }
        },
    });

    const startEditing = (target: User) => {
        update.reset();
        setInviting(false);
        setEditing(target);
    };

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
                    <Button
                        variant="primary"
                        onClick={() => {
                            setEditing(null);
                            setInviting((open) => !open);
                        }}
                    >
                        {inviting ? 'Cancel' : 'Invite user'}
                    </Button>
                )}
            </header>

            {inviting && (
                <Panel title="Invite an operator" className="p-4">
                    <InviteForm
                        roles={rolesQuery.data ?? []}
                        tenants={activeTenants}
                        defaultTenantId={impersonatedTenantId ?? ''}
                        tenantRequired={inFleetView}
                        pending={invite.isPending}
                        error={invite.error ? toApiError(invite.error) : null}
                        onSubmit={(payload) => invite.mutate(payload)}
                    />
                </Panel>
            )}

            {editing && (
                <Panel title={`Edit ${editing.name}`} className="p-4">
                    <EditUserForm
                        // Remounted per user, so the fields start from that user's values.
                        key={editing.id}
                        target={editing}
                        roles={rolesQuery.data ?? []}
                        tenants={activeTenants}
                        isSelf={currentUser?.id === editing.id}
                        mayManageRoles={can('role.manage')}
                        mayAssignTenant={mayChooseTenant}
                        pending={update.isPending}
                        error={update.error ? toApiError(update.error) : null}
                        onSubmit={(changes) => {
                            if (Object.keys(changes).length === 0) {
                                setEditing(null);

                                return;
                            }
                            update.mutate({ target: editing, changes });
                        }}
                        onCancel={() => setEditing(null)}
                    />
                </Panel>
            )}

            {notice && (
                <p role="status" className="panel px-4 py-2 text-xs" style={{ color: 'var(--text-muted)' }}>
                    {notice}
                </p>
            )}

            {resend.error && (
                <p role="alert" className="text-xs" style={{ color: 'var(--danger-text)' }}>
                    {toApiError(resend.error).message}
                </p>
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
                                    {showTenantColumn && <Th>Tenant</Th>}
                                    <Th>Role</Th>
                                    <Th>Two-factor</Th>
                                    <Th>Last signed in</Th>
                                    <Th>Status</Th>
                                    {can('user.update') && (
                                        <Th>
                                            <span className="sr-only">Actions</span>
                                        </Th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {usersQuery.data?.data.map((user) => (
                                    <tr key={user.id} style={{ borderTop: '1px solid var(--border)' }}>
                                        <Td>
                                            <div className="font-medium">
                                                {user.name}
                                                {currentUser?.id === user.id && (
                                                    <span className="ml-1 text-xs" style={{ color: 'var(--text-subtle)' }}>
                                                        (you)
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-xs" style={{ color: 'var(--text-subtle)' }}>
                                                {user.email}
                                            </div>
                                        </Td>
                                        {showTenantColumn && (
                                            <Td>
                                                <TenantCell user={user} />
                                            </Td>
                                        )}
                                        <Td>{humanise(user.roles?.[0] ?? 'none')}</Td>
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
                                                <span className="inline-flex flex-wrap items-center gap-2">
                                                    <Badge tone="warn" glyph="◔">
                                                        {humanise(user.status)}
                                                    </Badge>
                                                    {user.status === 'invited' && can('user.invite') && (
                                                        <Button
                                                            variant="ghost"
                                                            loading={resend.isPending && resend.variables?.id === user.id}
                                                            onClick={() => resend.mutate(user)}
                                                        >
                                                            Resend invitation
                                                        </Button>
                                                    )}
                                                </span>
                                            )}
                                        </Td>
                                        {can('user.update') && (
                                            <Td className="text-right">
                                                {/* A tenant operator never reaches a SuperAdmin's account. */}
                                                {(currentUser?.is_super_admin || !user.is_super_admin) && (
                                                    <Button
                                                        variant="ghost"
                                                        aria-label={`Edit ${user.name}`}
                                                        onClick={() => startEditing(user)}
                                                    >
                                                        Edit
                                                    </Button>
                                                )}
                                            </Td>
                                        )}
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

function TenantCell({ user }: { user: User }) {
    if (user.is_super_admin) {
        return (
            <Badge tone="accent" glyph="◇">
                All tenants
            </Badge>
        );
    }

    if (!user.tenant) {
        // Every request such an account makes is refused; editing it is the fix.
        return (
            <Badge tone="danger" glyph="!">
                No tenant
            </Badge>
        );
    }

    return <span>{user.tenant.name}</span>;
}

function TenantPicker({
    id,
    tenants,
    value,
    required,
    emptyLabel,
    hint,
    error,
    onChange,
}: {
    id: string;
    tenants: Tenant[];
    value: string;
    required: boolean;
    emptyLabel: string;
    hint: string;
    error?: string;
    onChange(value: string): void;
}) {
    return (
        <Field id={id} label="Tenant" hint={hint} error={error}>
            <Select id={id} required={required} value={value} onChange={(event) => onChange(event.target.value)}>
                <option value="">{emptyLabel}</option>
                {tenants.map((tenant) => (
                    <option key={tenant.id} value={tenant.id}>
                        {tenant.name}
                        {tenant.status === 'active' ? '' : ' (deactivated)'}
                    </option>
                ))}
            </Select>
        </Field>
    );
}

function RoleSelect({
    id,
    roles,
    value,
    hint,
    error,
    onChange,
}: {
    id: string;
    roles: RoleDefinition[];
    value: string;
    hint?: string;
    error?: string;
    onChange(value: string): void;
}) {
    return (
        <Field id={id} label="Role" hint={hint} error={error}>
            <Select id={id} value={value} onChange={(event) => onChange(event.target.value)}>
                {roles.map((definition) => (
                    <option key={definition.name} value={definition.name}>
                        {humanise(definition.name)}
                    </option>
                ))}
            </Select>
        </Field>
    );
}

function FormError({ error }: { error: ApiError | null }) {
    // Field errors are shown beside their field; this is for everything else.
    if (!error || Object.keys(error.fields).length > 0) {
        return null;
    }

    return (
        <span role="alert" className="ml-3 text-xs" style={{ color: 'var(--danger-text)' }}>
            {error.message}
        </span>
    );
}

function InviteForm({
    roles,
    tenants,
    defaultTenantId,
    tenantRequired,
    pending,
    error,
    onSubmit,
}: {
    roles: RoleDefinition[];
    /** Empty unless the operator holds `tenant.manage`. */
    tenants: Tenant[];
    defaultTenantId: string;
    tenantRequired: boolean;
    pending: boolean;
    error: ApiError | null;
    onSubmit(payload: InvitePayload): void;
}) {
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [role, setRole] = useState('operator');
    const [tenantId, setTenantId] = useState(defaultTenantId);

    // A SuperAdmin has no tenant of their own, so there is nothing to choose.
    const showTenantPicker = tenants.length > 0 && role !== 'super_admin';

    return (
        <form
            className="grid gap-3 sm:grid-cols-3"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({
                    name,
                    email,
                    roles: [role],
                    // Omitted when not chosen, so the invitee joins the tenant
                    // the request is already bound to.
                    ...(showTenantPicker && tenantId ? { tenant_id: tenantId } : {}),
                });
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

            <RoleSelect
                id="invite_role"
                roles={roles}
                value={role}
                hint={
                    role === 'super_admin'
                        ? 'Works across every tenant, so belongs to none.'
                        : 'Device and member management require a second factor.'
                }
                error={error?.fieldError('roles')}
                onChange={setRole}
            />

            {showTenantPicker && (
                <TenantPicker
                    id="invite_tenant"
                    tenants={tenants}
                    value={tenantId}
                    required={tenantRequired}
                    emptyLabel={tenantRequired ? 'Choose a tenant…' : 'The tenant I am viewing'}
                    hint={
                        tenantRequired
                            ? 'You are viewing all tenants, so this user needs one.'
                            : 'Defaults to the tenant you are viewing.'
                    }
                    error={error?.fieldError('tenant_id')}
                    onChange={setTenantId}
                />
            )}

            <div className="sm:col-span-3">
                <Button type="submit" variant="primary" loading={pending}>
                    Send invitation
                </Button>
                <FormError error={error} />
            </div>
        </form>
    );
}

function EditUserForm({
    target,
    roles,
    tenants,
    isSelf,
    mayManageRoles,
    mayAssignTenant,
    pending,
    error,
    onSubmit,
    onCancel,
}: {
    target: User;
    roles: RoleDefinition[];
    /** Active tenants; empty unless the operator holds `tenant.manage`. */
    tenants: Tenant[];
    isSelf: boolean;
    mayManageRoles: boolean;
    mayAssignTenant: boolean;
    pending: boolean;
    error: ApiError | null;
    onSubmit(changes: UserChanges): void;
    onCancel(): void;
}) {
    const currentRole = target.roles?.[0] ?? '';
    const currentTenantId = target.tenant?.id ?? '';

    const [name, setName] = useState(target.name);
    const [email, setEmail] = useState(target.email);
    const [role, setRole] = useState(currentRole);
    const [status, setStatus] = useState(target.status);
    const [tenantId, setTenantId] = useState(currentTenantId);

    // Nobody changes their own role, status or tenant: that is how an account
    // escalates itself, or the last administrator locks themselves out.
    const showRole = mayManageRoles && !isSelf;
    // An invitee becomes active by accepting, not by being edited.
    const showStatus = !isSelf && target.status !== 'invited';
    const becomesSuperAdmin = role === 'super_admin';
    const showTenant = mayAssignTenant && !isSelf && !becomesSuperAdmin;

    // The user's current tenant stays selectable even if it has been deactivated.
    const tenantOptions =
        target.tenant && !tenants.some((tenant) => tenant.id === target.tenant?.id)
            ? [target.tenant, ...tenants]
            : tenants;

    // Roles a tenant admin cannot grant are not in the list, but the user's
    // current one still has to display.
    const roleOptions =
        currentRole && !roles.some((definition) => definition.name === currentRole)
            ? [{ name: currentRole, permissions: [] }, ...roles]
            : roles;

    return (
        <form
            className="grid gap-3 sm:grid-cols-3"
            onSubmit={(event) => {
                event.preventDefault();

                const changes: UserChanges = {};
                if (name !== target.name) changes.name = name;
                if (email !== target.email) changes.email = email;
                if (showRole && role !== currentRole) changes.roles = [role];
                if (showStatus && status !== target.status) changes.status = status as 'active' | 'suspended';
                if (showTenant && tenantId !== currentTenantId) changes.tenant_id = tenantId;

                onSubmit(changes);
            }}
        >
            <Field id="edit_name" label="Name" error={error?.fieldError('name')}>
                <Input id="edit_name" required value={name} onChange={(event) => setName(event.target.value)} />
            </Field>

            <Field
                id="edit_email"
                label="Email"
                hint={
                    target.status === 'invited'
                        ? 'Changing it sends a fresh invitation to the new address.'
                        : 'They sign in with this address.'
                }
                error={error?.fieldError('email')}
            >
                <Input
                    id="edit_email"
                    type="email"
                    required
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                />
            </Field>

            {showRole ? (
                <RoleSelect
                    id="edit_role"
                    roles={roleOptions}
                    value={role}
                    hint={
                        becomesSuperAdmin && target.tenant
                            ? `A super administrator belongs to no tenant, so saving removes them from ${target.tenant.name}.`
                            : undefined
                    }
                    error={error?.fieldError('roles')}
                    onChange={setRole}
                />
            ) : (
                <ReadOnly
                    label="Role"
                    value={humanise(currentRole || 'none')}
                    hint={isSelf ? 'Another administrator has to change your role.' : undefined}
                />
            )}

            {showStatus && (
                <Field id="edit_status" label="Status" error={error?.fieldError('status')}>
                    <Select id="edit_status" value={status} onChange={(event) => setStatus(event.target.value)}>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended — cannot sign in</option>
                    </Select>
                </Field>
            )}

            {showTenant && (
                <TenantPicker
                    id="edit_tenant"
                    tenants={tenantOptions}
                    value={tenantId}
                    // Anyone but a SuperAdmin needs a tenant to sign in at all.
                    required
                    emptyLabel="Choose a tenant…"
                    hint={
                        currentTenantId && tenantId !== currentTenantId
                            ? 'They lose access to their current tenant as soon as you save.'
                            : 'The tenant whose doors and members this user manages.'
                    }
                    error={error?.fieldError('tenant_id')}
                    onChange={setTenantId}
                />
            )}

            <div className="flex flex-wrap items-center gap-2 sm:col-span-3">
                <Button type="submit" variant="primary" loading={pending}>
                    Save changes
                </Button>
                <Button type="button" variant="ghost" onClick={onCancel}>
                    Cancel
                </Button>
                <FormError error={error} />
            </div>
        </form>
    );
}

function ReadOnly({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                {label}
            </span>
            <span className="py-1.5 text-sm">{value}</span>
            {hint && (
                <p className="text-xs" style={{ color: 'var(--text-subtle)' }}>
                    {hint}
                </p>
            )}
        </div>
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
