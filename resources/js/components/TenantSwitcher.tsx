import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import type { Paginated, Tenant } from '@/types';
import { Select } from './ui';

/**
 * SuperAdmin only.
 *
 * Switching binds every subsequent query to one tenant; clearing it returns to
 * the cross-tenant fleet view. Both sides of that are written to the audit
 * log, because deliberately crossing the isolation boundary is exactly the
 * kind of action that should leave a trace.
 */
export function TenantSwitcher() {
    const { user, impersonatedTenantId, refresh } = useAuth();
    const queryClient = useQueryClient();

    const { data } = useQuery({
        queryKey: ['tenants'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Tenant>>('/tenants', {
                params: { per_page: 50 },
            });

            return data;
        },
        enabled: user?.is_super_admin === true,
    });

    const switchTenant = useMutation({
        mutationFn: async (tenantId: string) => {
            if (tenantId === '') {
                await api.delete('/tenant-switch');

                return;
            }

            await api.post(`/tenants/${tenantId}/switch`);
        },
        onSuccess: async () => {
            // Everything cached belongs to the tenant we just left.
            queryClient.clear();
            await refresh();
        },
    });

    if (!user?.is_super_admin) {
        return null;
    }

    const current = data?.data.find((tenant) => tenant.id === impersonatedTenantId);

    return (
        <div className="flex flex-col gap-1">
            <label htmlFor="tenant-switcher" className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                Viewing tenant
            </label>
            <Select
                id="tenant-switcher"
                value={current?.id ?? ''}
                disabled={switchTenant.isPending}
                onChange={(event) => switchTenant.mutate(event.target.value)}
            >
                <option value="">All tenants (fleet view)</option>
                {data?.data.map((tenant) => (
                    <option key={tenant.id} value={tenant.id}>
                        {tenant.name}
                    </option>
                ))}
            </Select>
        </div>
    );
}
