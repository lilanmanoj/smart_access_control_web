import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api, ensureCsrfCookie, toApiError, type ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { deviceKeys } from '@/lib/devices';
import { useTenantOptions } from '@/lib/tenants';
import { formatRelative } from '@/lib/format';
import { DeviceStateBadge } from '@/components/status';
import { TimezoneSelect } from '@/components/TimezoneSelect';
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
import type { Device, Paginated, Tenant } from '@/types';

export function DevicesPage() {
    const { can, user, impersonatedTenantId } = useAuth();
    const queryClient = useQueryClient();
    const [search, setSearch] = useState('');
    const [adding, setAdding] = useState(false);

    // Only an operator who manages tenants may put a device somewhere other
    // than their own tenant. Gated on the permission rather than the role
    // name, matching how the API decides.
    const mayChooseTenant = can('tenant.manage');

    // A SuperAdmin viewing every tenant at once has no tenant bound, so the
    // form has to ask which one this device belongs to.
    const inFleetView = user?.is_super_admin === true && impersonatedTenantId === null;

    const tenantsQuery = useTenantOptions();

    // The timezone of the tenant a new device lands in when none is picked:
    // the one switched into, or the operator's own.
    const boundTenantTimezone = impersonatedTenantId
        ? (tenantsQuery.data?.find((tenant) => tenant.id === impersonatedTenantId)?.timezone ?? null)
        : (user?.tenant?.timezone ?? null);

    const { data, isPending, error, refetch } = useQuery({
        queryKey: deviceKeys.list(search),
        queryFn: async () => {
            const { data } = await api.get<Paginated<Device>>('/devices', {
                params: { search: search || undefined, per_page: 50 },
            });

            return data;
        },
    });

    const create = useMutation({
        mutationFn: async (payload: DevicePayload) => {
            await ensureCsrfCookie();
            await api.post('/devices', payload);
        },
        onSuccess: () => {
            setAdding(false);
            void queryClient.invalidateQueries({ queryKey: ['devices'] });
        },
    });

    return (
        <div className="flex flex-col gap-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold">Devices</h1>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        {inFleetView
                            ? 'Every panel across every tenant, and whether it is answering right now.'
                            : 'Every panel in this tenant, and whether it is answering right now.'}
                    </p>
                </div>

                {can('device.create') && (
                    <Button variant="primary" onClick={() => setAdding((open) => !open)}>
                        {adding ? 'Cancel' : 'Add device'}
                    </Button>
                )}
            </header>

            {adding && (
                <Panel
                    title="Adopt a device"
                    description="Enter the device id shown on the panel's own settings screen. Credentials are issued afterwards, from the device page."
                    className="p-4"
                >
                    <AddDeviceForm
                        // A deactivated tenant is refused as a target, so it is not offered.
                        tenants={
                            mayChooseTenant
                                ? (tenantsQuery.data ?? []).filter((tenant) => tenant.status === 'active')
                                : []
                        }
                        // Pre-selected when they have switched into a tenant;
                        // an explicit choice when they are viewing them all.
                        defaultTenantId={impersonatedTenantId ?? ''}
                        boundTenantTimezone={boundTenantTimezone}
                        tenantRequired={inFleetView}
                        pending={create.isPending}
                        error={create.error ? toApiError(create.error) : null}
                        onSubmit={(payload) => create.mutate(payload)}
                    />
                </Panel>
            )}

            <Panel
                title={`${data?.meta.total ?? 0} device${data?.meta.total === 1 ? '' : 's'}`}
                actions={
                    <Input
                        aria-label="Search devices"
                        placeholder="Search name, id or location"
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

                {data && data.data.length === 0 && (
                    <EmptyState
                        title="No devices yet"
                        description="Adopt a panel here, then issue it credentials and enter them on the device's own settings portal."
                    />
                )}

                {data && data.data.length > 0 && (
                    <TableWrap>
                        <table className="w-full text-sm">
                            <thead style={{ background: 'var(--surface-sunken)' }}>
                                <tr>
                                    <Th>Device</Th>
                                    {inFleetView && <Th>Tenant</Th>}
                                    <Th>State</Th>
                                    <Th>Last health poll</Th>
                                    <Th>Enrolled</Th>
                                    <Th>Firmware</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.data.map((device) => (
                                    <tr
                                        key={device.id}
                                        style={{ borderTop: '1px solid var(--border)' }}
                                    >
                                        <Td>
                                            <Link
                                                to={`/devices/${device.id}`}
                                                className="font-medium underline underline-offset-2"
                                            >
                                                {device.name}
                                            </Link>
                                            <div
                                                className="font-mono text-xs"
                                                style={{ color: 'var(--text-subtle)' }}
                                            >
                                                {device.device_id}
                                                {device.location ? ` · ${device.location}` : ''}
                                            </div>
                                        </Td>
                                        {inFleetView && (
                                            <Td style={{ color: 'var(--text-muted)' }}>
                                                {device.tenant?.name ?? '—'}
                                            </Td>
                                        )}
                                        <Td>
                                            <DeviceStateBadge device={device} />
                                        </Td>
                                        <Td>
                                            <span style={{ color: 'var(--text-muted)' }}>
                                                {formatRelative(device.last_health_at)}
                                            </span>
                                        </Td>
                                        <Td className="tabular-nums">
                                            {device.enrolled_count} / {device.template_capacity}
                                        </Td>
                                        <Td style={{ color: 'var(--text-muted)' }}>
                                            {device.firmware_version ?? '—'}
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

interface DevicePayload {
    device_id: string;
    name: string;
    location: string;
    timezone: string;
    tenant_id?: string;
}

function AddDeviceForm({
    tenants,
    defaultTenantId,
    boundTenantTimezone,
    tenantRequired,
    pending,
    error,
    onSubmit,
}: {
    /** Empty unless the operator holds `tenant.manage`. */
    tenants: Tenant[];
    defaultTenantId: string;
    /** The timezone of the tenant used when no tenant is picked. */
    boundTenantTimezone: string | null;
    tenantRequired: boolean;
    pending: boolean;
    error: ApiError | null;
    onSubmit(payload: DevicePayload): void;
}) {
    const [deviceId, setDeviceId] = useState('');
    const [name, setName] = useState('');
    const [location, setLocation] = useState('');
    const [tenantId, setTenantId] = useState(defaultTenantId);
    // null until the operator picks one: until then the field follows the
    // tenant's timezone, including when they switch tenant.
    const [chosenTimezone, setChosenTimezone] = useState<string | null>(null);

    const showTenantPicker = tenants.length > 0;

    const tenantTimezone = tenantId
        ? (tenants.find((tenant) => tenant.id === tenantId)?.timezone ?? null)
        : boundTenantTimezone;
    const timezone = chosenTimezone ?? tenantTimezone ?? '';

    return (
        <form
            className="grid gap-3 sm:grid-cols-3"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({
                    device_id: deviceId.trim(),
                    name,
                    location,
                    timezone,
                    // Omitted entirely when not chosen, so the request falls
                    // back to the operator's own bound tenant.
                    ...(tenantId ? { tenant_id: tenantId } : {}),
                });
            }}
        >
            {showTenantPicker && (
                <Field
                    id="tenant_id"
                    label="Tenant"
                    hint={
                        tenantRequired
                            ? 'You are viewing all tenants, so this device needs one.'
                            : 'Defaults to the tenant you are viewing.'
                    }
                    error={error?.fieldError('tenant_id')}
                >
                    <Select
                        id="tenant_id"
                        required={tenantRequired}
                        value={tenantId}
                        onChange={(event) => setTenantId(event.target.value)}
                    >
                        <option value="">
                            {tenantRequired ? 'Choose a tenant…' : 'The tenant I am viewing'}
                        </option>
                        {tenants.map((tenant) => (
                            <option key={tenant.id} value={tenant.id}>
                                {tenant.name}
                            </option>
                        ))}
                    </Select>
                </Field>
            )}

            <Field
                id="device_id"
                label="Device ID"
                hint="Generated on the panel's first boot, e.g. SMA_4821"
                error={error?.fieldError('device_id')}
            >
                <Input
                    id="device_id"
                    required
                    value={deviceId}
                    onChange={(event) => setDeviceId(event.target.value)}
                />
            </Field>

            <Field id="name" label="Name" error={error?.fieldError('name')}>
                <Input
                    id="name"
                    required
                    placeholder="Front entrance"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                />
            </Field>

            <Field id="location" label="Location" error={error?.fieldError('location')}>
                <Input
                    id="location"
                    placeholder="Reception lobby"
                    value={location}
                    onChange={(event) => setLocation(event.target.value)}
                />
            </Field>

            <Field
                id="timezone"
                label="Timezone"
                hint={
                    tenantTimezone
                        ? `Defaults to the tenant's (${tenantTimezone}); change it for a door somewhere else.`
                        : 'This tenant has no timezone yet, so choose one for the door.'
                }
                error={error?.fieldError('timezone')}
            >
                <TimezoneSelect id="timezone" required value={timezone} onChange={setChosenTimezone} />
            </Field>

            <div className="sm:col-span-3">
                <Button type="submit" variant="primary" loading={pending}>
                    Add device
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
