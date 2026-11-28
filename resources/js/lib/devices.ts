import { useQuery } from '@tanstack/react-query';
import { api } from './api';
import { useAuth } from './auth';
import type { Device, Paginated } from '@/types';

/**
 * React Query keys for device lists.
 *
 * Both start with `'devices'`, so invalidating `['devices']` after a write
 * refreshes both of them. The second segment tells the two shapes apart: the
 * Devices page caches the paginated envelope, while `options` caches the array
 * inside it. The two once shared `['devices', '']`. Whichever page mounted
 * first filled that cache entry, and the other page then crashed while
 * rendering, reading `.meta` off an array or calling `.map` on an object.
 */
export const deviceKeys = {
    list: (search: string) => ['devices', 'list', search] as const,
    options: ['devices', 'options'] as const,
};

/** The devices this operator can see, for filter dropdowns. */
export function useDeviceOptions() {
    const { can } = useAuth();

    return useQuery({
        queryKey: deviceKeys.options,
        queryFn: async (): Promise<Device[]> => {
            const { data } = await api.get<Paginated<Device>>('/devices', {
                params: { per_page: 100 },
            });

            return data.data;
        },
        enabled: can('device.view'),
    });
}
