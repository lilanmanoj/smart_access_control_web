import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { Select } from '@/components/ui';

interface Timezone {
    /** IANA name, e.g. Asia/Colombo. */
    id: string;
    /** Today's UTC offset, e.g. +05:30. */
    offset: string;
}

/**
 * The timezones the API accepts, fetched once per session.
 *
 * From the server rather than `Intl.supportedValuesOf('timeZone')`: browsers
 * still use some retired names (Asia/Calcutta) that the API's validation
 * rejects, and the list must be exactly what the API will take.
 */
export function useTimezones() {
    return useQuery({
        queryKey: ['timezones'],
        queryFn: async (): Promise<Timezone[]> => {
            const { data } = await api.get<{ data: Timezone[] }>('/timezones');

            return data.data;
        },
        staleTime: Infinity,
    });
}

/** The browser's own timezone, as a sensible first choice for a new tenant. */
export function browserTimezone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
    } catch {
        return '';
    }
}

/**
 * A timezone dropdown, grouped by region: "Asia › Colombo (UTC+05:30)".
 *
 * A value the list does not contain (an old name stored before validation
 * existed) is still shown, so editing a record never silently changes it.
 */
export function TimezoneSelect({
    id,
    value,
    onChange,
    required = false,
    placeholder = 'Choose a timezone…',
}: {
    id: string;
    value: string;
    onChange(value: string): void;
    required?: boolean;
    placeholder?: string;
}) {
    const { data: zones, isPending, isError } = useTimezones();

    const groups = useMemo(() => {
        const byRegion = new Map<string, Timezone[]>();

        for (const zone of zones ?? []) {
            const region = zone.id.includes('/') ? (zone.id.split('/')[0] ?? 'Other') : 'Other';
            byRegion.set(region, [...(byRegion.get(region) ?? []), zone]);
        }

        return [...byRegion.entries()];
    }, [zones]);

    const known = zones?.some((zone) => zone.id === value) ?? false;

    return (
        <Select
            id={id}
            required={required}
            value={value}
            disabled={isPending && value === ''}
            onChange={(event) => onChange(event.target.value)}
        >
            <option value="">
                {isPending ? 'Loading timezones…' : isError ? 'Timezones could not be loaded' : placeholder}
            </option>
            {value !== '' && !known && <option value={value}>{value}</option>}
            {groups.map(([region, regionZones]) => (
                <optgroup key={region} label={region}>
                    {regionZones.map((zone) => (
                        <option key={zone.id} value={zone.id}>
                            {cityLabel(zone.id)} (UTC{zone.offset})
                        </option>
                    ))}
                </optgroup>
            ))}
        </Select>
    );
}

/** "America/Argentina/Buenos_Aires" → "Argentina › Buenos Aires". */
function cityLabel(id: string): string {
    const parts = id.split('/');
    const rest = parts.length > 1 ? parts.slice(1) : parts;

    return rest.map((part) => part.replace(/_/g, ' ')).join(' › ');
}
