<script setup lang="ts">
import TextInput from '@/packages/ui/src/Input/TextInput.vue';
import SecondaryButton from '@/packages/ui/src/Buttons/SecondaryButton.vue';
import DialogModal from '@/packages/ui/src/DialogModal.vue';
import { ref } from 'vue';
import PrimaryButton from '@/packages/ui/src/Buttons/PrimaryButton.vue';
import { useRetainersStore } from '@/utils/useRetainers';
import { Field, FieldLabel } from '@/packages/ui/src/field';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/packages/ui/src';
import { Checkbox } from '@/packages/ui/src';

type Retainer = {
    id: string;
    name: string;
    period_mode: string;
    period_unit: string;
    seconds_per_period: number | null;
    anchor_date: string | null;
    starts_at: string | null;
    ends_at: string | null;
    billable_only: boolean;
    hard_cap_enabled: boolean;
    hard_cap_scope: string;
    hard_cap_enforcement: string;
    hard_cap_cumulative_seconds: number | null;
    sub_cap_mode: string;
};

const props = defineProps<{
    retainer: Retainer;
}>();

const emit = defineEmits<{
    submitted: [];
}>();

const { updateRetainer } = useRetainersStore();
const show = defineModel('show', { default: false });
const saving = ref(false);

// — visible fields —
const name = ref(props.retainer.name);
const periodUnit = ref<'weekly' | 'monthly' | 'quarterly'>(
    (props.retainer.period_unit as 'weekly' | 'monthly' | 'quarterly') ?? 'monthly'
);
const hoursPerPeriod = ref<number>(
    props.retainer.seconds_per_period != null ? props.retainer.seconds_per_period / 3600 : 40
);
const startsAt = ref(
    props.retainer.starts_at ? props.retainer.starts_at.slice(0, 10) : ''
);

// — advanced fields —
const showAdvanced = ref(false);
const periodMode = ref<'calendar' | 'anchor' | 'explicit'>(
    (props.retainer.period_mode as 'calendar' | 'anchor' | 'explicit') ?? 'calendar'
);
const anchorDate = ref(props.retainer.anchor_date ?? '');
const billableOnly = ref(props.retainer.billable_only);
const hardCapEnabled = ref(props.retainer.hard_cap_enabled);
const hardCapScope = ref<'per_period' | 'cumulative'>(
    (props.retainer.hard_cap_scope as 'per_period' | 'cumulative') ?? 'per_period'
);
const hardCapEnforcement = ref<'block'>('block');
const cumulativeCapHours = ref<number>(
    props.retainer.hard_cap_cumulative_seconds != null
        ? props.retainer.hard_cap_cumulative_seconds / 3600
        : 0
);
const subCapMode = ref<'soft' | 'strict'>(
    (props.retainer.sub_cap_mode as 'soft' | 'strict') ?? 'soft'
);
const endsAt = ref(props.retainer.ends_at ? props.retainer.ends_at.slice(0, 10) : '');

async function submit() {
    saving.value = true;
    try {
        const secondsPerPeriod = hoursPerPeriod.value * 3600;

        const payload = {
            name: name.value,
            period_mode: periodMode.value,
            period_unit: periodUnit.value as 'weekly' | 'monthly' | 'quarterly' | null,
            seconds_per_period: secondsPerPeriod,
            starts_at: new Date(startsAt.value).toISOString(),
            ends_at: endsAt.value ? new Date(endsAt.value).toISOString() : null,
            billable_only: billableOnly.value,
            hard_cap_enabled: hardCapEnabled.value,
            hard_cap_scope: hardCapEnabled.value ? hardCapScope.value : null,
            hard_cap_enforcement: hardCapEnabled.value ? hardCapEnforcement.value : null,
            hard_cap_cumulative_seconds:
                hardCapEnabled.value && hardCapScope.value === 'cumulative'
                    ? cumulativeCapHours.value * 3600
                    : null,
            sub_cap_mode: subCapMode.value,
            anchor_date:
                periodMode.value === 'anchor' && anchorDate.value ? anchorDate.value : null,
        };

        await updateRetainer(props.retainer.id, payload);
        emit('submitted');
        show.value = false;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <DialogModal closeable :show="show" @close="show = false">
        <template #title>
            <div class="flex space-x-2">
                <span>Edit Retainer</span>
            </div>
        </template>

        <template #content>
            <div class="space-y-4">
                <!-- Name -->
                <Field>
                    <FieldLabel for="retainerName">Name</FieldLabel>
                    <TextInput
                        id="retainerName"
                        v-model="name"
                        type="text"
                        placeholder="Retainer name"
                        class="block w-full"
                        required
                        @keydown.enter="submit" />
                </Field>

                <!-- Period unit -->
                <Field>
                    <FieldLabel for="periodUnit">Period</FieldLabel>
                    <Select v-model="periodUnit">
                        <SelectTrigger id="periodUnit" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="weekly">Weekly</SelectItem>
                            <SelectItem value="monthly">Monthly</SelectItem>
                            <SelectItem value="quarterly">Quarterly</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>

                <!-- Hours per period -->
                <Field>
                    <FieldLabel for="hoursPerPeriod">Hours per period</FieldLabel>
                    <TextInput
                        id="hoursPerPeriod"
                        v-model="hoursPerPeriod"
                        type="number"
                        min="0"
                        step="0.5"
                        class="block w-full" />
                </Field>

                <!-- Starts at -->
                <Field>
                    <FieldLabel for="startsAt">Starts at</FieldLabel>
                    <TextInput
                        id="startsAt"
                        v-model="startsAt"
                        type="date"
                        class="block w-full"
                        required />
                </Field>

                <!-- Advanced toggle -->
                <div>
                    <button
                        type="button"
                        class="text-sm text-text-secondary hover:text-text-primary transition-colors flex items-center gap-1"
                        @click="showAdvanced = !showAdvanced">
                        <span>{{ showAdvanced ? '▾' : '▸' }}</span>
                        <span>Advanced options</span>
                    </button>
                </div>

                <!-- Advanced section -->
                <div v-if="showAdvanced" class="space-y-4 border-t border-border-secondary pt-4">
                    <!-- Period mode -->
                    <Field>
                        <FieldLabel for="periodMode">Period mode</FieldLabel>
                        <Select v-model="periodMode">
                            <SelectTrigger id="periodMode" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="calendar">Calendar</SelectItem>
                                <SelectItem value="anchor">Anchor</SelectItem>
                                <SelectItem value="explicit">Explicit</SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>

                    <!-- Anchor date (only when mode = anchor) -->
                    <Field v-if="periodMode === 'anchor'">
                        <FieldLabel for="anchorDate">Anchor date</FieldLabel>
                        <TextInput
                            id="anchorDate"
                            v-model="anchorDate"
                            type="date"
                            class="block w-full" />
                    </Field>

                    <!-- Billable only -->
                    <Field>
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="billableOnly"
                                :checked="billableOnly"
                                @update:checked="billableOnly = Boolean($event)" />
                            <FieldLabel for="billableOnly" class="mb-0 cursor-pointer"
                                >Billable only</FieldLabel
                            >
                        </div>
                    </Field>

                    <!-- Hard cap enabled -->
                    <Field>
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="hardCapEnabled"
                                :checked="hardCapEnabled"
                                @update:checked="hardCapEnabled = Boolean($event)" />
                            <FieldLabel for="hardCapEnabled" class="mb-0 cursor-pointer"
                                >Enable hard cap</FieldLabel
                            >
                        </div>
                    </Field>

                    <!-- Hard cap sub-fields (only when hard cap enabled) -->
                    <div v-if="hardCapEnabled" class="space-y-4 pl-4 border-l-2 border-border-secondary">
                        <!-- Cap scope -->
                        <Field>
                            <FieldLabel for="hardCapScope">Cap scope</FieldLabel>
                            <Select v-model="hardCapScope">
                                <SelectTrigger id="hardCapScope" class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="per_period">Per period</SelectItem>
                                    <SelectItem value="cumulative">Cumulative</SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>

                        <!-- Enforcement -->
                        <Field>
                            <FieldLabel for="hardCapEnforcement">Enforcement</FieldLabel>
                            <Select v-model="hardCapEnforcement">
                                <SelectTrigger id="hardCapEnforcement" class="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="block">Block</SelectItem>
                                    <SelectItem value="flag" disabled>Flag (v1.1)</SelectItem>
                                    <SelectItem value="approval" disabled
                                        >Approval (v1.1)</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </Field>

                        <!-- Cumulative cap hours (only when scope = cumulative) -->
                        <Field v-if="hardCapScope === 'cumulative'">
                            <FieldLabel for="cumulativeCapHours"
                                >Cumulative cap (hours)</FieldLabel
                            >
                            <TextInput
                                id="cumulativeCapHours"
                                v-model="cumulativeCapHours"
                                type="number"
                                min="0"
                                step="0.5"
                                class="block w-full" />
                        </Field>
                    </div>

                    <!-- Sub-cap mode -->
                    <Field>
                        <FieldLabel for="subCapMode">Sub-cap mode</FieldLabel>
                        <Select v-model="subCapMode">
                            <SelectTrigger id="subCapMode" class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="soft">Soft</SelectItem>
                                <SelectItem value="strict">Strict</SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>

                    <!-- End date -->
                    <Field>
                        <FieldLabel for="endsAt">End date (optional)</FieldLabel>
                        <TextInput
                            id="endsAt"
                            v-model="endsAt"
                            type="date"
                            class="block w-full" />
                    </Field>
                </div>
            </div>
        </template>

        <template #footer>
            <SecondaryButton @click="show = false">Cancel</SecondaryButton>
            <PrimaryButton
                class="ms-3"
                :class="{ 'opacity-25': saving }"
                :disabled="saving"
                @click="submit">
                Update Retainer
            </PrimaryButton>
        </template>
    </DialogModal>
</template>

<style scoped></style>
