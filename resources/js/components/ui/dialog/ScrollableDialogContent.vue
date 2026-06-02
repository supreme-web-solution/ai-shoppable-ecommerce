<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import DialogContent from '@/components/ui/dialog/DialogContent.vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        bodyClass?: HTMLAttributes['class'];
        showCloseButton?: boolean;
    }>(),
    {
        showCloseButton: true,
    },
);
</script>

<template>
    <DialogContent
        :show-close-button="showCloseButton"
        :class="
            cn(
                'flex max-h-[min(90dvh,calc(100vh-2rem))] flex-col gap-0 overflow-hidden p-0',
                props.class,
            )
        "
    >
        <div v-if="$slots.header" class="shrink-0 border-b px-6 py-4">
            <slot name="header" />
        </div>
        <div
            :class="
                cn(
                    'min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-4',
                    bodyClass,
                )
            "
        >
            <slot />
        </div>
        <div v-if="$slots.footer" class="shrink-0 border-t px-6 py-4">
            <slot name="footer" />
        </div>
    </DialogContent>
</template>
