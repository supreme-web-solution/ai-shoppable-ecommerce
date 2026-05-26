<script setup lang="ts">
import { ref } from 'vue';
import EmbedPlayerApp from '@/embed/EmbedPlayerApp.vue';

defineProps<{
    embedSlug: string;
    embedName?: string;
}>();

const open = ref(false);
</script>

<template>
    <div class="fixed inset-0 pointer-events-none">
        <button
            type="button"
            class="pointer-events-auto fixed bottom-5 right-5 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-rose-600 text-2xl shadow-lg ring-2 ring-white/20 transition hover:scale-105"
            aria-label="Open shopping videos"
            @click="open = true"
        >
            ▶
        </button>

        <div
            v-if="open"
            class="pointer-events-auto fixed inset-0 z-[60] flex items-end justify-center bg-black/70 p-3 sm:items-center"
        >
            <div class="relative h-[92vh] w-full max-w-md overflow-hidden rounded-2xl bg-black shadow-2xl">
                <button
                    type="button"
                    class="absolute right-3 top-3 z-20 rounded-full bg-black/60 px-3 py-1 text-xs text-white"
                    @click="open = false"
                >
                    Close
                </button>
                <EmbedPlayerApp :embed-slug="embedSlug" />
            </div>
        </div>
    </div>
</template>
