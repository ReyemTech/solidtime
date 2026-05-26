<script setup lang="ts">
import { computed } from 'vue';
import type { RetainerStatus } from '@/utils/useRetainerStatusQuery';

interface RetainerSummary {
    id: string;
    name: string;
    period_unit: string | null;
    seconds_per_period: number | null;
    hard_cap_enabled: boolean;
}

const props = defineProps<{
    retainer: RetainerSummary;
    status: RetainerStatus | null;
}>();

const toHours = (sec: number) => (sec / 3600).toFixed(1);

const barWidth = computed(() => {
    if (!props.status) return '0%';
    return Math.min(100, props.status.percent * 100).toFixed(1) + '%';
});

const barColor = computed(() => {
    if (!props.status) return 'bg-tertiary';
    const pct = props.status.percent;
    if (pct >= 1.0) return 'bg-red-500';
    if (pct >= 0.9) return 'bg-amber-500';
    return 'bg-accent-300/70';
});
</script>

<template>
    <div class="rounded-lg border border-card-border bg-card-background shadow-card px-3.5 py-2.5">
        <div class="flex items-baseline justify-between">
            <h3 class="font-medium text-sm text-text-primary">{{ retainer.name }}</h3>
            <span v-if="retainer.period_unit" class="text-xs text-text-secondary capitalize">
                {{ retainer.period_unit }}
            </span>
        </div>
        <template v-if="status">
            <div class="mt-2 flex items-baseline justify-between text-sm">
                <span class="text-text-primary font-medium">
                    {{ toHours(status.tracked_seconds) }}h / {{ toHours(status.allocated_seconds) }}h
                </span>
                <span class="text-text-secondary">{{ (status.percent * 100).toFixed(0) }}%</span>
            </div>
            <div class="mt-2 h-1.5 w-full rounded-full bg-tertiary overflow-hidden">
                <div :class="['h-full transition-all duration-300', barColor]" :style="{ width: barWidth }" />
            </div>
            <div class="mt-2 text-xs text-text-secondary flex items-center gap-1.5">
                <template v-if="status.delta_seconds < 0">
                    {{ toHours(-status.delta_seconds) }}h under plan
                </template>
                <template v-else-if="status.delta_seconds > 0">
                    {{ toHours(status.delta_seconds) }}h over plan
                </template>
                <template v-else>On plan</template>
                <span
                    v-if="retainer.hard_cap_enabled"
                    class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-500/20 text-amber-700 dark:text-amber-400">
                    Hard cap
                </span>
            </div>
        </template>
        <div v-else class="mt-2 text-sm text-text-secondary">Loading…</div>
    </div>
</template>
