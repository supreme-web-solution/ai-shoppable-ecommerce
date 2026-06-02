<script setup lang="ts">
import { computed } from 'vue';
import CarouselEmbed from '@/embed/CarouselEmbed.vue';
import EmbedPlayerApp from '@/embed/EmbedPlayerApp.vue';
import FloatingWidgetEmbed from '@/embed/FloatingWidgetEmbed.vue';
import ProductPageEmbed from '@/embed/ProductPageEmbed.vue';
import { normalizeEmbedDisplayType } from '@/lib/videoEmbed';

const props = defineProps<{
    embedSlug: string;
    embedType: string;
    embedName?: string;
}>();

const displayType = computed(() => normalizeEmbedDisplayType(props.embedType));
</script>

<template>
    <FloatingWidgetEmbed
        v-if="displayType === 'floating_widget'"
        :embed-slug="embedSlug"
        :embed-name="embedName"
    />
    <CarouselEmbed
        v-else-if="displayType === 'carousel'"
        :embed-slug="embedSlug"
        :embed-name="embedName"
    />
    <ProductPageEmbed
        v-else-if="displayType === 'product_page'"
        :embed-slug="embedSlug"
        :embed-name="embedName"
    />
    <EmbedPlayerApp
        v-else
        :embed-slug="embedSlug"
        layout="vertical"
    />
</template>
