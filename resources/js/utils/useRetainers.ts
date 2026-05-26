import { defineStore } from 'pinia';
import { api } from '@/packages/api/src';
import { getCurrentOrganizationId } from '@/utils/useUser';
import { useNotificationsStore } from '@/utils/notification';
import { useQueryClient } from '@tanstack/vue-query';

export const useRetainersStore = defineStore('retainers', () => {
    const { handleApiRequestNotifications } = useNotificationsStore();
    const queryClient = useQueryClient();

    async function createRetainer(body: Parameters<typeof api['v1.retainers.store']>[0]) {
        const organization = getCurrentOrganizationId();
        if (organization) {
            const response = await handleApiRequestNotifications(
                () =>
                    api['v1.retainers.store'](body, {
                        params: {
                            organization,
                        },
                    }),
                'Retainer created successfully',
                'Failed to create retainer'
            );
            queryClient.invalidateQueries({ queryKey: ['retainers'] });
            return response?.data;
        }
    }

    async function updateRetainer(
        retainerId: string,
        body: Parameters<typeof api['v1.retainers.update']>[0]
    ) {
        const organization = getCurrentOrganizationId();
        if (organization) {
            await handleApiRequestNotifications(
                () =>
                    api['v1.retainers.update'](body, {
                        params: {
                            organization,
                            retainer: retainerId,
                        },
                    }),
                'Retainer updated successfully',
                'Failed to update retainer'
            );
            queryClient.invalidateQueries({ queryKey: ['retainers'] });
            queryClient.invalidateQueries({ queryKey: ['retainer', retainerId] });
        }
    }

    async function deleteRetainer(retainerId: string) {
        const organization = getCurrentOrganizationId();
        if (organization) {
            await handleApiRequestNotifications(
                () =>
                    api['v1.retainers.destroy'](undefined, {
                        params: {
                            organization,
                            retainer: retainerId,
                        },
                    }),
                'Retainer deleted successfully',
                'Failed to delete retainer'
            );
            queryClient.invalidateQueries({ queryKey: ['retainers'] });
        }
    }

    return { createRetainer, updateRetainer, deleteRetainer };
});
