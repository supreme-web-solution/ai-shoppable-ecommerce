<script setup lang="ts">
import { computed } from 'vue';
import { Label } from '@/components/ui/label';
import {
    EMBED_DISPLAY_OPTIONS
    
} from '@/lib/videoEmbed';
import type {EmbedDisplayType} from '@/lib/videoEmbed';

const model = defineModel<EmbedDisplayType>({ required: true });

withDefaults(
    defineProps<{
        disabled?: boolean;
        compact?: boolean;
        label?: string;
    }>(),
    {
        disabled: false,
        compact: false,
        label: 'Display style',
    },
);

const activeDescription = computed(
    () => EMBED_DISPLAY_OPTIONS.find((option) => option.value === model.value)?.description ?? '',
);
</script>

<template>
    <div :class="compact ? 'min-w-[140px]' : 'space-y-1.5'">
        <Label
            v-if="!compact"
            class="text-xs font-semibold uppercase tracking-wide text-gray-500"
        >
            {{ label }}
        </Label>
        <select
            v-model="model"
            :disabled="disabled"
            :class="[
                'w-full rounded-lg border bg-white text-sm text-gray-800 focus:border-[#E8563A]/50 focus:outline-none focus:ring-2 focus:ring-[#E8563A]/15',
                compact ? 'h-8 px-2 text-xs font-semibold' : 'h-10 px-3',
                disabled ? 'cursor-not-allowed opacity-60' : '',
            ]"
            :aria-label="label"
        >
            <option
                v-for="option in EMBED_DISPLAY_OPTIONS"
                :key="option.value"
                :value="option.value"
            >
                {{ option.label }}
            </option>
        </select>
        <p
            v-if="!compact && activeDescription"
            class="text-xs text-gray-500"
        >
            {{ activeDescription }}
        </p>
    </div>
</template>
