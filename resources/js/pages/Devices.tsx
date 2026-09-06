import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api, ensureCsrfCookie, toApiError, type ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useTenantOptions } from '@/lib/tenants';
import { formatRelative } from '@/lib/format';
import { DeviceStateBadge } from '@/components/status';
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

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['devices', search],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Device>>('/devices', {
                params: { search: search || undefined, per_page: 50 },
            });

            return data;
        },
    });

    const create = useMutation({
        mutationFn: async (payload: {
            device_id: string;
            name: string;
            location: string;
            tenant_id?: string;
        }) => {
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
                        tenants={mayChooseTenant ? (tenantsQuery.data ?? []) : []}
                        // Pre-selected when they have switched into a tenant;
                        // an explicit choice when they are viewing them all.
                        defaultTenantId={impersonatedTenantId ?? ''}
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

function AddDeviceForm({
    tenants,
    defaultTenantId,
    tenantRequired,
    pending,
    error,
    onSubmit,
}: {
    /** Empty unless the operator holds `tenant.manage`. */
    tenants: Tenant[];
    defaultTenantId: string;
    tenantRequired: boolean;
    pending: boolean;
    error: ApiError | null;
    onSubmit(payload: {
        device_id: string;
        name: string;
        location: string;
        tenant_id?: string;
    }): void;
}) {
    const [deviceId, setDeviceId] = useState('');
    const [name, setName] = useState('');
    const [location, setLocation] = useState('');
    const [tenantId, setTenantId] = useState(defaultTenantId);

    const showTenantPicker = tenants.length > 0;

    return (
        <form
            className="grid gap-3 sm:grid-cols-3"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({
                    device_id: deviceId.trim(),
                    name,
                    location,
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
