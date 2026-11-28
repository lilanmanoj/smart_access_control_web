import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie, toApiError, type ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDay, formatNumber } from '@/lib/format';
import { TenantStatusBadge } from '@/components/status';
import { TimezoneSelect, browserTimezone } from '@/components/TimezoneSelect';
import {
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
import type { Paginated, Tenant, TenantStatus } from '@/types';

/**
 * The overrides a tenant may set. Anything left unset follows the platform
 * default, so each one is a three-way choice rather than a checkbox: "off"
 * and "not overridden" are different answers.
 */
const SETTINGS: { key: string; group: string; field: string; label: string; hint: string }[] = [
    {
        key: 'backup_codes.online_verification_only',
        group: 'backup_codes',
        field: 'online_verification_only',
        label: 'Verify backup codes online only',
        hint: 'Doors stop accepting backup codes while they are offline.',
    },
    {
        key: 'otp.require_enrollment',
        group: 'otp',
        field: 'require_enrollment',
        label: 'One-time codes need an enrolment',
        hint: 'Only members enrolled at the requesting door can receive a code.',
    },
    {
        key: 'members.require_approval',
        group: 'members',
        field: 'require_approval',
        label: 'Members enrolled at a door need approval',
        hint: 'An operator approves each member before they can get in.',
    },
];

type SettingChoice = '' | 'on' | 'off';

interface TenantPayload {
    name: string;
    slug?: string;
    timezone: string;
    settings: Record<string, Record<string, boolean | null>>;
}

/**
 * Tenant management, for operators holding `tenant.manage`.
 *
 * Deactivating a tenant is immediate: its operators are refused on their next
 * request and its doors can no longer reach the server.
 */
export function TenantsPage() {
    const { can, user } = useAuth();
    const queryClient = useQueryClient();

    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<'' | TenantStatus>('');
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<Tenant | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const tenantsQuery = useQuery({
        // Under the `tenants` prefix, so a change here also refreshes the
        // tenant switcher, which caches its own list at ['tenants'].
        queryKey: ['tenants', 'manage', search, status],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Tenant>>('/tenants', {
                params: { search: search || undefined, status: status || undefined, per_page: 100 },
            });

            return data;
        },
        enabled: can('tenant.manage'),
        placeholderData: keepPreviousData,
    });

    const refresh = () => queryClient.invalidateQueries({ queryKey: ['tenants'] });

    const create = useMutation({
        mutationFn: async (payload: TenantPayload) => {
            await ensureCsrfCookie();
            const { data } = await api.post<{ data: Tenant }>('/tenants', payload);

            return data.data;
        },
        onSuccess: (tenant) => {
            setCreating(false);
            setNotice(`${tenant.name} was created. Switch into it to add its doors and operators.`);
            void refresh();
        },
    });

    const update = useMutation({
        mutationFn: async ({ id, payload }: { id: string; payload: Partial<TenantPayload> }) => {
            await ensureCsrfCookie();
            const { data } = await api.patch<{ data: Tenant }>(`/tenants/${id}`, payload);

            return data.data;
        },
        onSuccess: (tenant) => {
            setEditing(null);
            setNotice(`${tenant.name} was saved.`);
            void refresh();
        },
    });

    const setTenantStatus = useMutation({
        mutationFn: async ({ tenant, next }: { tenant: Tenant; next: TenantStatus }) => {
            await ensureCsrfCookie();
            const { data } = await api.patch<{ data: Tenant }>(`/tenants/${tenant.id}`, { status: next });

            return data.data;
        },
        onSuccess: (tenant) => {
            setNotice(
                tenant.status === 'active'
                    ? `${tenant.name} is active again. Its operators and doors can reach the server.`
                    : `${tenant.name} was deactivated. Its operators are signed out and its doors are refused by the server.`,
            );
            void refresh();
        },
    });

    if (!can('tenant.manage')) {
        return (
            <EmptyState
                title="You cannot manage tenants"
                description="Managing tenants needs the tenant.manage permission."
            />
        );
    }

    const tenants = tenantsQuery.data?.data ?? [];
    const filtered = search !== '' || status !== '';

    return (
        <div className="flex flex-col gap-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold">Tenants</h1>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        Each tenant is a separate site or customer. Its doors, members and operators cannot see any other tenant's.
                    </p>
                </div>

                <Button
                    variant="primary"
                    onClick={() => {
                        setEditing(null);
                        setCreating((open) => !open);
                    }}
                >
                    {creating ? 'Cancel' : 'New tenant'}
                </Button>
            </header>

            {creating && (
                <Panel title="New tenant" className="p-4">
                    <TenantForm
                        submitLabel="Create tenant"
                        pending={create.isPending}
                        error={create.error ? toApiError(create.error) : null}
                        onSubmit={(payload) => create.mutate(payload)}
                    />
                </Panel>
            )}

            {editing && (
                <Panel
                    title={`Edit ${editing.name}`}
                    className="p-4"
                    actions={
                        <Button variant="ghost" onClick={() => setEditing(null)}>
                            Cancel
                        </Button>
                    }
                >
                    <TenantForm
                        // Remounted per tenant so the fields start from its values.
                        key={editing.id}
                        tenant={editing}
                        submitLabel="Save changes"
                        pending={update.isPending}
                        error={update.error ? toApiError(update.error) : null}
                        onSubmit={({ name, timezone, settings }) =>
                            update.mutate({ id: editing.id, payload: { name, timezone, settings } })
                        }
                    />
                </Panel>
            )}

            {notice && (
                <p role="status" className="panel px-4 py-2 text-xs" style={{ color: 'var(--text-muted)' }}>
                    {notice}
                </p>
            )}

            {setTenantStatus.error && (
                <p role="alert" className="text-xs" style={{ color: 'var(--danger-text)' }}>
                    {toApiError(setTenantStatus.error).message}
                </p>
            )}

            <Panel
                title={`${tenantsQuery.data?.meta.total ?? 0} tenant${tenantsQuery.data?.meta.total === 1 ? '' : 's'}`}
                actions={
                    <>
                        <Input
                            aria-label="Search tenants"
                            placeholder="Search name or slug"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="w-48"
                        />
                        <Select
                            aria-label="Filter by status"
                            value={status}
                            onChange={(event) => setStatus(event.target.value as '' | TenantStatus)}
                            className="w-36"
                        >
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="suspended">Deactivated</option>
                        </Select>
                    </>
                }
            >
                {tenantsQuery.error && (
                    <ErrorState
                        message={toApiError(tenantsQuery.error).message}
                        retry={() => void tenantsQuery.refetch()}
                    />
                )}

                {tenantsQuery.isPending && <LoadingRows />}

                {tenantsQuery.data && tenants.length === 0 && (
                    <EmptyState
                        title={filtered ? 'No tenants match' : 'No tenants yet'}
                        description={
                            filtered
                                ? 'Try another search or status.'
                                : 'Create a tenant for each site or customer, then switch into it to add its doors.'
                        }
                    />
                )}

                {tenants.length > 0 && (
                    <TableWrap>
                        <table className="w-full text-sm">
                            <thead style={{ background: 'var(--surface-sunken)' }}>
                                <tr>
                                    <Th>Tenant</Th>
                                    <Th>Status</Th>
                                    <Th>Timezone</Th>
                                    <Th className="text-right">Devices</Th>
                                    <Th className="text-right">Members</Th>
                                    <Th className="text-right">Operators</Th>
                                    <Th>Created</Th>
                                    <Th>
                                        <span className="sr-only">Actions</span>
                                    </Th>
                                </tr>
                            </thead>
                            <tbody>
                                {tenants.map((tenant) => {
                                    const active = tenant.status === 'active';
                                    // Deactivating your own tenant would sign
                                    // you out mid-click; the API refuses it too.
                                    const isOwn = user?.tenant?.id === tenant.id;
                                    const busy =
                                        setTenantStatus.isPending &&
                                        setTenantStatus.variables?.tenant.id === tenant.id;

                                    return (
                                        <tr key={tenant.id} style={{ borderTop: '1px solid var(--border)' }}>
                                            <Td>
                                                <div className="font-medium">{tenant.name}</div>
                                                <div className="font-mono text-xs" style={{ color: 'var(--text-subtle)' }}>
                                                    {tenant.slug}
                                                </div>
                                            </Td>
                                            <Td>
                                                <TenantStatusBadge status={tenant.status} />
                                            </Td>
                                            <Td style={{ color: tenant.timezone ? 'var(--text-muted)' : 'var(--warn-text)' }}>
                                                {tenant.timezone ?? 'Not set'}
                                            </Td>
                                            <Td className="text-right tabular-nums">
                                                {formatNumber(tenant.devices_count ?? 0)}
                                            </Td>
                                            <Td className="text-right tabular-nums">
                                                {formatNumber(tenant.members_count ?? 0)}
                                            </Td>
                                            <Td className="text-right tabular-nums">
                                                {formatNumber(tenant.users_count ?? 0)}
                                            </Td>
                                            <Td style={{ color: 'var(--text-muted)' }}>
                                                {formatDay(tenant.created_at)}
                                            </Td>
                                            <Td>
                                                <span className="flex justify-end gap-1 whitespace-nowrap">
                                                    <Button
                                                        variant="ghost"
                                                        aria-label={`Edit ${tenant.name}`}
                                                        onClick={() => {
                                                            setCreating(false);
                                                            update.reset();
                                                            setEditing(tenant);
                                                        }}
                                                    >
                                                        Edit
                                                    </Button>
                                                    {active ? (
                                                        <Button
                                                            variant="ghost"
                                                            style={{ color: 'var(--danger-text)' }}
                                                            disabled={isOwn}
                                                            title={isOwn ? 'This is your own tenant.' : undefined}
                                                            loading={busy}
                                                            aria-label={`Deactivate ${tenant.name}`}
                                                            onClick={() => {
                                                                if (
                                                                    window.confirm(
                                                                        `Deactivate ${tenant.name}?\n\n` +
                                                                            'Its operators are signed out and its doors are refused by the server until it is activated again. ' +
                                                                            'Anything a door can do offline, it keeps doing.',
                                                                    )
                                                                ) {
                                                                    setTenantStatus.mutate({ tenant, next: 'suspended' });
                                                                }
                                                            }}
                                                        >
                                                            Deactivate
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            variant="secondary"
                                                            loading={busy}
                                                            aria-label={`Activate ${tenant.name}`}
                                                            onClick={() => setTenantStatus.mutate({ tenant, next: 'active' })}
                                                        >
                                                            Activate
                                                        </Button>
                                                    )}
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

/** A slug as the API will store it: lower case, words joined by dashes. */
function slugify(value: string): string {
    return value
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60);
}

function readSetting(tenant: Tenant | undefined, group: string, field: string): SettingChoice {
    const value = (tenant?.settings?.[group] as Record<string, unknown> | undefined)?.[field];

    return value === true ? 'on' : value === false ? 'off' : '';
}

function TenantForm({
    tenant,
    submitLabel,
    pending,
    error,
    onSubmit,
}: {
    tenant?: Tenant;
    submitLabel: string;
    pending: boolean;
    error: ApiError | null;
    onSubmit(payload: TenantPayload): void;
}) {
    const [name, setName] = useState(tenant?.name ?? '');
    const [slug, setSlug] = useState(tenant?.slug ?? '');
    // Follows the name until the operator types a slug of their own.
    const [slugEdited, setSlugEdited] = useState(false);
    // A new tenant starts from the operator's own timezone. An existing one
    // without a timezone starts empty, so saving it is a deliberate choice.
    const [timezone, setTimezone] = useState(tenant ? (tenant.timezone ?? '') : browserTimezone());
    const [settings, setSettings] = useState<Record<string, SettingChoice>>(() =>
        Object.fromEntries(SETTINGS.map(({ key, group, field }) => [key, readSetting(tenant, group, field)])),
    );

    const editing = tenant !== undefined;

    return (
        <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(event) => {
                event.preventDefault();

                const payload: TenantPayload = { name, timezone, settings: {} };

                for (const { key, group, field } of SETTINGS) {
                    const choice = settings[key] ?? '';
                    // null clears an override, so the platform default applies.
                    payload.settings[group] = {
                        ...payload.settings[group],
                        [field]: choice === '' ? null : choice === 'on',
                    };
                }

                if (!editing) {
                    payload.slug = slug;
                }

                onSubmit(payload);
            }}
        >
            <Field id="tenant_name" label="Name" error={error?.fieldError('name')}>
                <Input
                    id="tenant_name"
                    required
                    maxLength={120}
                    value={name}
                    onChange={(event) => {
                        setName(event.target.value);
                        if (!editing && !slugEdited) {
                            setSlug(slugify(event.target.value));
                        }
                    }}
                />
            </Field>

            <Field
                id="tenant_slug"
                label="Slug"
                hint={editing ? 'A slug cannot be changed once the tenant is created.' : 'Letters, numbers and dashes. Made from the name unless you change it.'}
                error={error?.fieldError('slug')}
            >
                <Input
                    id="tenant_slug"
                    className="font-mono"
                    maxLength={60}
                    pattern="[A-Za-z0-9_\-]+"
                    readOnly={editing}
                    aria-readonly={editing || undefined}
                    value={slug}
                    onChange={(event) => {
                        setSlugEdited(true);
                        setSlug(event.target.value);
                    }}
                    style={editing ? { background: 'var(--surface-sunken)', color: 'var(--text-muted)' } : undefined}
                />
            </Field>

            <Field
                id="tenant_timezone"
                label="Timezone"
                hint={
                    editing && !tenant?.timezone
                        ? 'Not set yet. Choose one to save this tenant.'
                        : "Where this site is. New devices in this tenant start from it."
                }
                error={error?.fieldError('timezone')}
            >
                <TimezoneSelect id="tenant_timezone" required value={timezone} onChange={setTimezone} />
            </Field>

            <fieldset className="grid gap-3 sm:col-span-2 sm:grid-cols-3">
                <legend className="mb-2 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>
                    Settings
                </legend>
                {SETTINGS.map(({ key, label, hint }) => {
                    const id = `tenant_setting_${key.replace('.', '_')}`;

                    return (
                        <Field key={key} id={id} label={label} hint={hint} error={error?.fieldError(`settings.${key}`)}>
                            <Select
                                id={id}
                                value={settings[key] ?? ''}
                                onChange={(event) =>
                                    setSettings((current) => ({
                                        ...current,
                                        [key]: event.target.value as SettingChoice,
                                    }))
                                }
                            >
                                <option value="">Platform default</option>
                                <option value="on">On</option>
                                <option value="off">Off</option>
                            </Select>
                        </Field>
                    );
                })}
            </fieldset>

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
