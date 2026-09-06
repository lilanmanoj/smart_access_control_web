import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api, ensureCsrfCookie, toApiError, type ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
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
    TableWrap,
    Td,
    Th,
} from '@/components/ui';
import type { Device, Paginated } from '@/types';

export function DevicesPage() {
    const { can } = useAuth();
    const queryClient = useQueryClient();
    const [search, setSearch] = useState('');
    const [adding, setAdding] = useState(false);

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
        mutationFn: async (payload: { device_id: string; name: string; location: string }) => {
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
                        Every panel in this tenant, and whether it is answering right now.
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
    pending,
    error,
    onSubmit,
}: {
    pending: boolean;
    error: ApiError | null;
    onSubmit(payload: { device_id: string; name: string; location: string }): void;
}) {
    const [deviceId, setDeviceId] = useState('');
    const [name, setName] = useState('');
    const [location, setLocation] = useState('');

    return (
        <form
            className="grid gap-3 sm:grid-cols-3"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({ device_id: deviceId.trim(), name, location });
            }}
        >
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
