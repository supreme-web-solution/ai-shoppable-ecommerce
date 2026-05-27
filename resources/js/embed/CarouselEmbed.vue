<script setup lang="ts">
import EmbedPlayerApp from '@/embed/EmbedPlayerApp.vue';

defineProps<{
    embedSlug: string;
    embedName?: string;
}>();
</script>

<template>
    <div class="carousel-embed-host">
        <EmbedPlayerApp :embed-slug="embedSlug" layout="carousel" />
    </div>
</template>

<style scoped>
.carousel-embed-host {
    position: relative;
    z-index: 1000;
    isolation: isolate;
    display: flex;
    width: 100%;
    min-height: 100dvh;
    justify-content: center;
    padding: 12px;
    box-sizing: border-box;
    background:
        radial-gradient(
            circle at 14% 8%,
            rgba(232, 86, 58, 0.14),
            transparent 32%
        ),
        radial-gradient(
            circle at 88% 0%,
            rgba(255, 140, 66, 0.1),
            transparent 28%
        ),
        #ece8e2;
}

@media (min-width: 900px) {
    .carousel-embed-host {
        padding: 20px;
    }
}
</style>
