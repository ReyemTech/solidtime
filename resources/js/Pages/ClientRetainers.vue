<script setup lang="ts">
import { ref } from 'vue';
import MainContainer from '@/packages/ui/src/MainContainer.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { UserCircleIcon, ChevronRightIcon } from '@heroicons/vue/20/solid';
import { Link } from '@inertiajs/vue3';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import PageTitle from '@/Components/Common/PageTitle.vue';
import { useRetainersForClientQuery } from '@/utils/useRetainersQuery';
import { useRetainersStore } from '@/utils/useRetainers';
import RetainerStatusCardWithFetcher from '@/Components/Common/Retainer/RetainerStatusCardWithFetcher.vue';
import RetainerCreateModal from '@/Components/Common/Retainer/RetainerCreateModal.vue';
import RetainerEditModal from '@/Components/Common/Retainer/RetainerEditModal.vue';

const props = defineProps<{
    client: { id: string; name: string };
}>();

const { retainers, isLoading } = useRetainersForClientQuery(() => props.client.id);

const showCreate = ref(false);
const showEdit = ref(false);
const editingRetainer = ref<any | null>(null);
const retainersStore = useRetainersStore();

function openEdit(retainer: any) {
    editingRetainer.value = retainer;
    showEdit.value = true;
}

function closeEdit() {
    showEdit.value = false;
    editingRetainer.value = null;
}

async function deleteRetainer(id: string) {
    if (!confirm('Delete this retainer?')) return;
    await retainersStore.deleteRetainer(id);
}
</script>

<template>
    <AppLayout :title="`Retainers · ${client.name}`" data-testid="client_retainers_view">
        <MainContainer
            class="py-5 border-b border-default-background-separator flex justify-between items-center">
            <nav class="flex" aria-label="Breadcrumb">
                <ol role="list" class="flex items-center space-x-2">
                    <li>
                        <div class="flex items-center space-x-6">
                            <Link
                                :href="route('clients')"
                                class="flex items-center space-x-2 sm:space-x-2.5">
                                <UserCircleIcon class="w-5 text-icon-default"></UserCircleIcon>
                                <span class="text-sm sm:text-base font-medium">Clients</span>
                            </Link>
                        </div>
                    </li>
                    <li>
                        <div
                            class="flex items-center space-x-3 text-text-primary font-semibold text-base">
                            <ChevronRightIcon
                                class="h-5 w-5 flex-shrink-0 text-text-secondary"
                                aria-hidden="true" />
                            <span>{{ client.name }}</span>
                        </div>
                    </li>
                    <li>
                        <div
                            class="flex items-center space-x-3 text-text-secondary text-base">
                            <ChevronRightIcon
                                class="h-5 w-5 flex-shrink-0 text-text-secondary"
                                aria-hidden="true" />
                            <span>Retainers</span>
                        </div>
                    </li>
                </ol>
            </nav>
            <PrimaryButton @click="showCreate = true">New retainer</PrimaryButton>
        </MainContainer>

        <MainContainer class="py-6">
            <div v-if="isLoading" class="text-text-tertiary text-sm">Loading…</div>

            <div v-else-if="!retainers.length" class="text-text-tertiary text-sm">
                No retainers for this client yet.
            </div>

            <div v-else class="space-y-3 max-w-3xl">
                <RetainerStatusCardWithFetcher
                    v-for="retainer in retainers"
                    :key="retainer.id"
                    :retainer="retainer"
                    @edit="openEdit(retainer)"
                    @delete="deleteRetainer(retainer.id)" />
            </div>
        </MainContainer>

        <RetainerCreateModal
            v-model:show="showCreate"
            :client-id="client.id"
            @submitted="showCreate = false" />

        <RetainerEditModal
            v-if="editingRetainer"
            v-model:show="showEdit"
            :retainer="editingRetainer"
            @submitted="closeEdit" />
    </AppLayout>
</template>
