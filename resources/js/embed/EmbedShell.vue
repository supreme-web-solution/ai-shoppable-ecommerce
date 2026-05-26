<script setup lang="ts">
import { computed } from 'vue';
import CarouselEmbed from '@/embed/CarouselEmbed.vue';
import EmbedPlayerApp from '@/embed/EmbedPlayerApp.vue';
import FloatingWidgetEmbed from '@/embed/FloatingWidgetEmbed.vue';
import ProductPageEmbed from '@/embed/ProductPageEmbed.vue';

const props = defineProps<{
    embedSlug: string;
    embedType: string;
    embedName?: string;
}>();

const renderer = computed(() => {
    switch (props.embedType) {
        case 'floating_widget':
            return FloatingWidgetEmbed;
        case 'carousel':
            return CarouselEmbed;
        case 'product_page':
            return ProductPageEmbed;
        default:
            return EmbedPlayerApp;
    }
});
</script>

<template>
    <component
        :is="renderer"
        :embed-slug="embedSlug"
        :embed-name="embedName"
    />
</template>
