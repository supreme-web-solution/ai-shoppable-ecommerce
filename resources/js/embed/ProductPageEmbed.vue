<script setup lang="ts">
import EmbedPlayerApp from '@/embed/EmbedPlayerApp.vue';

defineProps<{
    embedSlug: string;
    embedName?: string;
}>();
</script>

<template>
    <div class="product-page-embed-host">
        <EmbedPlayerApp :embed-slug="embedSlug" layout="product_page" />
    </div>
</template>

<style scoped>
.product-page-embed-host {
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
            circle at 10% 0%,
            rgba(232, 86, 58, 0.1),
            transparent 30%
        ),
        #ece8e2;
}

@media (min-width: 900px) {
    .product-page-embed-host {
        padding: 20px;
    }
}
</style>
