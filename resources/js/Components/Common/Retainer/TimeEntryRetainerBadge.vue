<script setup lang="ts">
import { computed } from 'vue';
import { useProjectsQuery } from '@/utils/useProjectsQuery';
import { useRetainersForClientQuery } from '@/utils/useRetainersQuery';
import { useRetainerStatusQuery } from '@/utils/useRetainerStatusQuery';

const props = defineProps<{ projectId: string | null }>();

const { projects } = useProjectsQuery();

const clientId = computed<string | null>(() => {
    if (!props.projectId) return null;
    const project = projects.value.find((p) => p.id === props.projectId);
    return project?.client_id ?? null;
});

const { retainers } = useRetainersForClientQuery(clientId);

const activeRetainer = computed(() => {
    const today = new Date().toISOString().slice(0, 10);
    return (
        retainers.value.find(
            (r: any) =>
                r.starts_at <= today && (r.ends_at === null || r.ends_at >= today)
        ) ?? null
    );
});

const { data: statusData } = useRetainerStatusQuery(
    () => activeRetainer.value?.id ?? null
);

const status = computed(() => statusData.value ?? null);

const toH = (n: number) => (n / 3600).toFixed(1);

const colorClass = computed(() => {
    if (!status.value) return 'text-text-secondary bg-tertiary';
    const pct = status.value.percent;
    if (pct >= 1.0) return 'text-red-700 bg-red-100 dark:text-red-300 dark:bg-red-900/30';
    if (pct >= 0.9) return 'text-amber-700 bg-amber-100 dark:text-amber-300 dark:bg-amber-900/30';
    return 'text-emerald-700 bg-emerald-100 dark:text-emerald-300 dark:bg-emerald-900/30';
});
</script>

<template>
    <div
        v-if="activeRetainer && status"
        :class="[
            'inline-flex items-center gap-1 text-xs px-2 py-1 rounded shrink-0',
            colorClass,
        ]"
        :title="`${activeRetainer.name}: ${toH(status.tracked_seconds)}h of ${toH(status.allocated_seconds)}h (${(status.percent * 100).toFixed(0)}%)`"
    >
        <span class="font-medium truncate max-w-[120px]">{{ activeRetainer.name }}:</span>
        <span class="whitespace-nowrap">{{ toH(status.tracked_seconds) }}h / {{ toH(status.allocated_seconds) }}h</span>
        <span class="whitespace-nowrap">({{ (status.percent * 100).toFixed(0) }}%)</span>
    </div>
</template>
