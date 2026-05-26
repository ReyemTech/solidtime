import { useQuery } from '@tanstack/vue-query';
import { computed, type MaybeRefOrGetter, toValue } from 'vue';
import { api } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';

export interface RetainerStatus {
    as_of: string;
    allocated_seconds: number;
    tracked_seconds: number;
    delta_seconds: number;
    percent: number;
    hard_cap_enabled: boolean;
    hard_cap_scope: string | null;
}

export function useRetainerStatusQuery(retainerId: MaybeRefOrGetter<string | null>) {
    return useQuery({
        queryKey: computed(() => ['retainer', toValue(retainerId), 'status']),
        queryFn: async (): Promise<RetainerStatus | null> => {
            const organizationId = getCurrentOrganizationId();
            const rId = toValue(retainerId);
            if (!organizationId || !rId) return null;
            const response = await api['v1.retainers.status']({
                params: { organization: organizationId, retainer: rId },
            });
            // The openapi schema has RetainerStatusResource as z.string() but the backend
            // returns a JSON object; cast via unknown to the real shape.
            return (response as unknown as { data: RetainerStatus }).data;
        },
        enabled: computed(
            () => toValue(retainerId) !== null && !!getCurrentOrganizationId()
        ),
        refetchInterval: 60_000, // refresh every minute so UI stays fresh after backend cache expiry
    });
}
