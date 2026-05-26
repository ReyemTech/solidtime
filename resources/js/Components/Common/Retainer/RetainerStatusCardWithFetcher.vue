<script setup lang="ts">
import RetainerStatusCard from './RetainerStatusCard.vue';
import { useRetainerStatusQuery } from '@/utils/useRetainerStatusQuery';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';

interface RetainerSummary {
    id: string;
    name: string;
    period_unit: string | null;
    seconds_per_period: number | null;
    hard_cap_enabled: boolean;
}

const props = defineProps<{ retainer: RetainerSummary }>();
defineEmits<{ edit: []; delete: [] }>();

const { data: status } = useRetainerStatusQuery(() => props.retainer.id);
</script>

<template>
    <div class="space-y-2">
        <RetainerStatusCard :retainer="retainer" :status="status ?? null" />
        <div class="flex gap-2 justify-end">
            <SecondaryButton @click="$emit('edit')">Edit</SecondaryButton>
            <SecondaryButton @click="$emit('delete')">Delete</SecondaryButton>
        </div>
    </div>
</template>
