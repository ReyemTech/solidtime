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

interface RetainerSummary {
    id: string;
    name: string;
    starts_at: string;
    ends_at: string | null;
    period_unit: string | null;
    seconds_per_period: number | null;
    hard_cap_enabled: boolean;
}

const activeRetainer = computed(() => {
    const today = new Date().toISOString().slice(0, 10);
    return (
        (retainers.value as RetainerSummary[]).find(
            (r) =>
                r.starts_at <= today && (r.ends_at === null || r.ends_at >= today)
        ) ?? null
    );
});

const { data: statusData } = useRetainerStatusQuery(
    () => activeRetainer.value?.id ?? null
);

const status = computed(() => statusData.value ?? null);

// Prefer the current period (matches the status card's primary view).
// Fall back to cumulative if the retainer has no active period (e.g. before starts_at).
const period = computed(() => status.value?.current_period ?? null);

const toH = (n: number) => (n / 3600).toFixed(1);

const colorClass = computed(() => {
    if (!period.value) return 'text-text-secondary bg-tertiary';
    const pct = period.value.percent;
    if (pct >= 1.0) return 'text-red-700 bg-red-100 dark:text-red-300 dark:bg-red-900/30';
    if (pct >= 0.9) return 'text-amber-700 bg-amber-100 dark:text-amber-300 dark:bg-amber-900/30';
    return 'text-emerald-700 bg-emerald-100 dark:text-emerald-300 dark:bg-emerald-900/30';
});

const periodLabel = computed(() => {
    switch (activeRetainer.value?.period_unit) {
        case 'weekly': return 'this week';
        case 'monthly': return 'this month';
        case 'quarterly': return 'this quarter';
        default: return 'this period';
    }
});
</script>

<template>
    <div
        v-if="activeRetainer && period"
        :class="[
            'inline-flex items-center gap-1 text-xs px-2 py-1 rounded shrink-0',
            colorClass,
        ]"
        :title="`${activeRetainer.name} (${periodLabel}): ${toH(period.tracked_seconds)}h of ${toH(period.allocated_seconds)}h (${(period.percent * 100).toFixed(0)}%)`"
    >
        <span class="font-medium truncate max-w-[120px]">{{ activeRetainer.name }}:</span>
        <span class="whitespace-nowrap">{{ toH(period.tracked_seconds) }}h / {{ toH(period.allocated_seconds) }}h</span>
        <span class="whitespace-nowrap">({{ (period.percent * 100).toFixed(0) }}%)</span>
    </div>
</template>
