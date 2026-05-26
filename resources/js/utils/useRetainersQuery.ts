import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { computed, type MaybeRefOrGetter, toValue } from 'vue';
import { api } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';

export function useRetainersForClientQuery(clientId: MaybeRefOrGetter<string | null>) {
    const queryClient = useQueryClient();

    const query = useQuery({
        queryKey: computed(() => ['retainers', 'client', toValue(clientId)]),
        queryFn: async () => {
            const organizationId = getCurrentOrganizationId();
            const cId = toValue(clientId);
            if (!organizationId || !cId) throw new Error('No organization or client');
            return api['v1.retainers.for-client']({
                params: { organization: organizationId, client: cId },
            });
        },
        enabled: computed(
            () => toValue(clientId) !== null && !!getCurrentOrganizationId()
        ),
        staleTime: 1000 * 30, // 30 seconds
    });

    const retainers = computed(() => query.data.value?.data ?? []);

    const invalidateRetainers = () => {
        queryClient.invalidateQueries({ queryKey: ['retainers'] });
    };

    return {
        ...query,
        retainers,
        invalidateRetainers,
    };
}
