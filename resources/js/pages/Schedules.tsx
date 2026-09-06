import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ensureCsrfCookie, toApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { weekdayName } from '@/lib/format';
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
} from '@/components/ui';
import type { AccessSchedule } from '@/types';

interface DraftWindow {
    weekday: number;
    starts_at: string;
    ends_at: string;
}

/**
 * Time-of-day access windows.
 *
 * The rule that matters and is easy to get backwards: a member with no
 * schedule is unrestricted, and attaching one *narrows* their access to the
 * union of its windows. Adding a schedule can never grant access that was not
 * already there.
 */
export function SchedulesPage() {
    const { can } = useAuth();
    const queryClient = useQueryClient();
    const [creating, setCreating] = useState(false);

    const { data, isPending, error, refetch } = useQuery({
        queryKey: ['access-schedules'],
        queryFn: async () => {
            const { data } = await api.get<{ data: AccessSchedule[] }>('/access-schedules');

            return data.data;
        },
    });

    const create = useMutation({
        mutationFn: async (payload: Record<string, unknown>) => {
            await ensureCsrfCookie();
            await api.post('/access-schedules', payload);
        },
        onSuccess: () => {
            setCreating(false);
            void queryClient.invalidateQueries({ queryKey: ['access-schedules'] });
        },
    });

    const remove = useMutation({
        mutationFn: async (id: string) => {
            await ensureCsrfCookie();
            await api.delete(`/access-schedules/${id}`);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['access-schedules'] }),
    });

    return (
        <div className="flex flex-col gap-4">
            <header className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-lg font-semibold">Access schedules</h1>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        Weekly windows, evaluated in the schedule's own timezone.
                    </p>
                </div>

                {can('schedule.manage') && (
                    <Button variant="primary" onClick={() => setCreating((open) => !open)}>
                        {creating ? 'Cancel' : 'New schedule'}
                    </Button>
                )}
            </header>

            <p className="panel px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                A member holding <strong>no</strong> schedule may enter at any hour. Attaching one
                restricts them to its windows — schedules narrow access, they never widen it.
            </p>

            {creating && (
                <Panel title="New schedule" className="p-4">
                    <ScheduleForm
                        pending={create.isPending}
                        error={create.error ? toApiError(create.error) : null}
                        onSubmit={(payload) => create.mutate(payload)}
                    />
                </Panel>
            )}

            {error && <ErrorState message={toApiError(error).message} retry={() => void refetch()} />}
            {isPending && <LoadingRows />}

            {data?.length === 0 && (
                <EmptyState
                    title="No schedules"
                    description="Without any, every active member may enter at any hour."
                />
            )}

            <div className="grid gap-3 md:grid-cols-2">
                {data?.map((schedule) => (
                    <Panel
                        key={schedule.id}
                        title={schedule.name}
                        description={`${schedule.timezone} · ${schedule.members_count ?? 0} member(s)`}
                        actions={
                            can('schedule.manage') && (
                                <Button
                                    variant="danger"
                                    onClick={() => {
                                        if (window.confirm(`Delete "${schedule.name}"?`)) {
                                            remove.mutate(schedule.id);
                                        }
                                    }}
                                >
                                    Delete
                                </Button>
                            )
                        }
                        className="p-0"
                    >
                        <div className="flex flex-col gap-1.5 p-4">
                            {!schedule.is_active && (
                                <Badge tone="neutral" glyph="—">
                                    Inactive — not enforced
                                </Badge>
                            )}

                            {schedule.windows?.map((window) => (
                                <div key={window.id} className="flex justify-between gap-3 text-xs">
                                    <span>{weekdayName(window.weekday)}</span>
                                    <span className="tabular-nums" style={{ color: 'var(--text-muted)' }}>
                                        {window.starts_at.slice(0, 5)} – {window.ends_at.slice(0, 5)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </Panel>
                ))}
            </div>
        </div>
    );
}

function ScheduleForm({
    pending,
    error,
    onSubmit,
}: {
    pending: boolean;
    error: ReturnType<typeof toApiError> | null;
    onSubmit(payload: Record<string, unknown>): void;
}) {
    const [name, setName] = useState('');
    const [timezone, setTimezone] = useState(
        Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
    );
    const [windows, setWindows] = useState<DraftWindow[]>([
        { weekday: 1, starts_at: '08:00', ends_at: '18:00' },
    ]);

    function updateWindow(index: number, patch: Partial<DraftWindow>) {
        setWindows((current) =>
            current.map((window, i) => (i === index ? { ...window, ...patch } : window)),
        );
    }

    return (
        <form
            className="flex flex-col gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit({ name, timezone, is_active: true, windows });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Field id="schedule_name" label="Name" error={error?.fieldError('name')}>
                    <Input
                        id="schedule_name"
                        required
                        placeholder="Office hours"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                    />
                </Field>

                <Field
                    id="schedule_tz"
                    label="Timezone"
                    hint="Windows are evaluated here, not on the server's clock."
                    error={error?.fieldError('timezone')}
                >
                    <Input
                        id="schedule_tz"
                        required
                        value={timezone}
                        onChange={(event) => setTimezone(event.target.value)}
                    />
                </Field>
            </div>

            <fieldset className="flex flex-col gap-2">
                <legend className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                    Windows
                </legend>

                {windows.map((window, index) => (
                    <div key={index} className="flex flex-wrap items-center gap-2">
                        <Select
                            aria-label={`Weekday for window ${index + 1}`}
                            value={window.weekday}
                            className="w-36"
                            onChange={(event) =>
                                updateWindow(index, { weekday: Number(event.target.value) })
                            }
                        >
                            {[1, 2, 3, 4, 5, 6, 7].map((day) => (
                                <option key={day} value={day}>
                                    {weekdayName(day)}
                                </option>
                            ))}
                        </Select>

                        <Input
                            type="time"
                            aria-label={`Start time for window ${index + 1}`}
                            value={window.starts_at}
                            className="w-32"
                            onChange={(event) => updateWindow(index, { starts_at: event.target.value })}
                        />

                        <Input
                            type="time"
                            aria-label={`End time for window ${index + 1}`}
                            value={window.ends_at}
                            className="w-32"
                            onChange={(event) => updateWindow(index, { ends_at: event.target.value })}
                        />

                        {windows.length > 1 && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    setWindows((current) => current.filter((_, i) => i !== index))
                                }
                            >
                                Remove
                            </Button>
                        )}
                    </div>
                ))}

                <div>
                    <Button
                        type="button"
                        onClick={() =>
                            setWindows((current) => [
                                ...current,
                                { weekday: 1, starts_at: '08:00', ends_at: '18:00' },
                            ])
                        }
                    >
                        Add window
                    </Button>
                </div>
            </fieldset>

            <div>
                <Button type="submit" variant="primary" loading={pending}>
                    Create schedule
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
