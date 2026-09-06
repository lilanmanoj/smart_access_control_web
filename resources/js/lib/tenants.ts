import { useQuery } from '@tanstack/react-query';
import { api } from './api';
import { useAuth } from './auth';
import type { Paginated, Tenant } from '@/types';

/**
 * The tenants this operator may act across.
 *
 * Defined once, and deliberately so: two components previously fetched this
 * under the same React Query key while returning different shapes — one the
 * paginated envelope, the other the array inside it. Whichever mounted first
 * won, and the other silently read `.length` off an object. A single hook is
 * what makes that impossible rather than merely unlikely.
 *
 * Gated on the `tenant.manage` permission, matching how the API decides who
 * may name a tenant on a write.
 */
export function useTenantOptions() {
    const { can } = useAuth();

    return useQuery({
        queryKey: ['tenants'],
        queryFn: async (): Promise<Tenant[]> => {
            const { data } = await api.get<Paginated<Tenant>>('/tenants', {
                params: { per_page: 100 },
            });

            return data.data;
        },
        enabled: can('tenant.manage'),
        // Tenants change far less often than doors do.
        staleTime: 5 * 60_000,
    });
}
