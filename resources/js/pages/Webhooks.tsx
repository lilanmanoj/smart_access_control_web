import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie, toApiError } from '@/lib/api';
import { formatRelative } from '@/lib/format';
import {
    Badge,
    Button,
    EmptyState,
    ErrorState,
    Field,
    Input,
    LoadingRows,
    Panel,
} from '@/components/ui';
import type { WebhookEndpoint } from '@/types';

/**
 * Outbound webhooks, so a tenant can wire denials and offline doors into their
 * own alerting rather than watching this screen.
 */
export function WebhooksPage() {
    const queryClient = useQueryClient();
    const [creating, setCreating] = useState(false);
    const [secret, setSecret] = useState<string | null>(null);

    const endpointsQuery = useQuery({
        queryKey: ['webhooks'],
        queryFn: async () => {
            const { data } = await api.get<{ data: WebhookEndpoint[] }>('/webhooks');

            return data.data;
        },
    });

    const eventsQuery = useQuery({
        queryKey: ['webhook-events'],
        queryFn: async () => {
            const { data } = await api.get<{ data: string[] }>('/webhook-events');

            return data.data;
        },
    });

    const create = useMutation({
        mutationFn: async (payload: { url: string; description: string; events: string[] }) => {
            await ensureCsrfCookie();
            const { data } = await api.post('/webhooks', payload);

            return data.secret as string;
        },
        onSuccess: (issuedSecret) => {
            setSecret(issuedSecret);
            setCreating(false);
            void queryClient.invalidateQueries({ queryKey: ['webhooks'] });
        },
    });

    const remove = useMutation({
        mutationFn: async (id: string) => {
            await ensureCsrfCookie();
            await api.delete(`/webhooks/${id}`);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['webhooks'] }),
    });

    return (
        <div className="flex flex-col gap-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold">Webhooks</h1>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        Deliveries are signed with a shared secret in an <code>X-Signature</code>{' '}
                        header, so a receiver can tell a genuine one from anything else that finds
                        the URL.
                    </p>
                </div>

                <Button variant="primary" onClick={() => setCreating((open) => !open)}>
                    {creating ? 'Cancel' : 'Add endpoint'}
                </Button>
            </header>

            {secret && (
                <section
                    role="status"
                    className="panel flex flex-col gap-2 p-4"
                    style={{ borderColor: 'var(--warn)', background: 'var(--warn-soft)' }}
                >
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold">Signing secret</h2>
                            <p className="text-xs" style={{ color: 'var(--warn-text)' }}>
                                <span aria-hidden="true">! </span>
                                Shown once. Your receiver needs it to verify every delivery.
                            </p>
                        </div>
                        <Button onClick={() => setSecret(null)}>I have recorded it</Button>
                    </div>
                    <code className="rounded p-2 font-mono text-xs break-all" style={{ background: 'var(--surface-sunken)' }}>
                        {secret}
                    </code>
                </section>
            )}

            {creating && (
                <Panel title="New endpoint" className="p-4">
                    <EndpointForm
                        available={eventsQuery.data ?? []}
                        pending={create.isPending}
                        error={create.error ? toApiError(create.error) : null}
                        onSubmit={(payload) => create.mutate(payload)}
                    />
                </Panel>
            )}

            <Panel title="Endpoints">
                {endpointsQuery.error && (
                    <ErrorState
                        message={toApiError(endpointsQuery.error).message}
                        retry={() => void endpointsQuery.refetch()}
                    />
                )}
                {endpointsQuery.isPending && <LoadingRows rows={3} />}

                {endpointsQuery.data?.length === 0 && (
                    <EmptyState
                        title="No endpoints"
                        description="Subscribe a URL to access.denied or device.offline to get alerts where your team already looks."
                    />
                )}

                <ul className="divide-y" style={{ borderColor: 'var(--border)' }}>
                    {endpointsQuery.data?.map((endpoint) => (
                        <li
                            key={endpoint.id}
                            className="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
                            style={{ borderColor: 'var(--border)' }}
                        >
                            <div className="min-w-0">
                                <p className="truncate font-mono text-xs">{endpoint.url}</p>
                                {endpoint.description && (
                                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                                        {endpoint.description}
                                    </p>
                                )}
                                <p className="mt-1 flex flex-wrap gap-1">
                                    {endpoint.events.map((event) => (
                                        <Badge key={event} tone="accent">
                                            {event}
                                        </Badge>
                                    ))}
                                </p>
                            </div>

                            <div className="flex items-center gap-2">
                                {endpoint.is_active ? (
                                    <Badge tone="ok" glyph="✓">
                                        Active
                                    </Badge>
                                ) : (
                                    <Badge tone="danger" glyph="✕">
                                        Disabled after {endpoint.consecutive_failures} failures
                                    </Badge>
                                )}
                                <span className="text-xs" style={{ color: 'var(--text-subtle)' }}>
                                    {formatRelative(endpoint.last_delivered_at)}
                                </span>
                                <Button
                                    variant="danger"
                                    onClick={() => {
                                        if (window.confirm('Delete this endpoint?')) {
                                            remove.mutate(endpoint.id);
                                        }
                                    }}
                                >
                                    Delete
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            </Panel>
        </div>
    );
}

function EndpointForm({
    available,
    pending,
    error,
    onSubmit,
}: {
    available: string[];
    pending: boolean;
    error: ReturnType<typeof toApiError> | null;
    onSubmit(payload: { url: string; description: string; events: string[] }): void;
}) {
    const [url, setUrl] = useState('');
    const [description, setDescription] = useState('');
    const [events, setEvents] = useState<string[]>(['access.denied']);

    return (
        <form
            className="flex flex-col gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({ url, description, events });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    id="webhook_url"
                    label="URL"
                    hint="HTTPS only — these payloads name people and doors."
                    error={error?.fieldError('url')}
                >
                    <Input
                        id="webhook_url"
                        type="url"
                        required
                        placeholder="https://example.com/hooks/access"
                        value={url}
                        onChange={(event) => setUrl(event.target.value)}
                    />
                </Field>

                <Field id="webhook_desc" label="Description" error={error?.fieldError('description')}>
                    <Input
                        id="webhook_desc"
                        value={description}
                        onChange={(event) => setDescription(event.target.value)}
                    />
                </Field>
            </div>

            <fieldset>
                <legend className="mb-1 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                    Events
                </legend>
                <div className="flex flex-wrap gap-3">
                    {available.map((name) => (
                        <label key={name} className="flex items-center gap-1.5 text-xs">
                            <input
                                type="checkbox"
                                checked={events.includes(name)}
                                onChange={(changed) =>
                                    setEvents((current) =>
                                        changed.target.checked
                                            ? [...current, name]
                                            : current.filter((value) => value !== name),
                                    )
                                }
                            />
                            <code>{name}</code>
                        </label>
                    ))}
                </div>
                {error?.fieldError('events') && (
                    <p role="alert" className="mt-1 text-xs" style={{ color: 'var(--danger-text)' }}>
                        {error.fieldError('events')}
                    </p>
                )}
            </fieldset>

            <div>
                <Button type="submit" variant="primary" loading={pending}>
                    Create endpoint
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
