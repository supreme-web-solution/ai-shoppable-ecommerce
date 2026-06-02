<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { embedApiUrl } from '@/embed/config';

export type LinkPreviewData = {
    url: string;
    title?: string | null;
    description?: string | null;
    image?: string | null;
    site_name?: string | null;
};

const props = defineProps<{
    url: string;
    variant?: 'default' | 'embed' | 'on-primary';
}>();

const preview = ref<LinkPreviewData | null>(null);
const loading = ref(true);
const failed = ref(false);

onMounted(async () => {
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(
            embedApiUrl(
                `/api/v1/player/link-preview?url=${encodeURIComponent(props.url)}`,
            ),
        );

        if (!response.ok) {
            failed.value = true;

            return;
        }

        const payload = (await response.json()) as { data?: LinkPreviewData | null };
        preview.value = payload.data ?? null;
        failed.value = preview.value === null;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <a
        v-if="preview && !failed"
        :href="preview.url"
        target="_blank"
        rel="noopener noreferrer"
        class="link-preview-card"
        :class="`link-preview-card--${variant ?? 'default'}`"
        @click.stop
    >
        <img
            v-if="preview.image"
            :src="preview.image"
            alt=""
            class="link-preview-card__image"
        >
        <div class="link-preview-card__body">
            <p v-if="preview.site_name" class="link-preview-card__site">
                {{ preview.site_name }}
            </p>
            <p v-if="preview.title" class="link-preview-card__title">
                {{ preview.title }}
            </p>
            <p v-if="preview.description" class="link-preview-card__desc">
                {{ preview.description }}
            </p>
        </div>
    </a>
    <div
        v-else-if="loading"
        class="link-preview-card link-preview-card--skeleton"
        :class="`link-preview-card--${variant ?? 'default'}`"
    >
        <div class="link-preview-card__skel-image" />
        <div class="link-preview-card__skel-lines">
            <div class="link-preview-card__skel-line link-preview-card__skel-line--short" />
            <div class="link-preview-card__skel-line" />
        </div>
    </div>
</template>

<style scoped>
.link-preview-card {
    display: flex;
    gap: 10px;
    margin-top: 8px;
    border-radius: 14px;
    overflow: hidden;
    border: 1px solid rgba(0, 0, 0, 0.08);
    background: #fff;
    text-decoration: none;
    color: inherit;
    max-width: 100%;
}

.link-preview-card--embed {
    border-color: rgba(255, 255, 255, 0.14);
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
}

.link-preview-card--on-primary {
    border-color: rgba(255, 255, 255, 0.28);
    background: rgba(255, 255, 255, 0.14);
    color: #fff;
}

.link-preview-card:hover {
    opacity: 0.95;
}

.link-preview-card__image {
    width: 88px;
    min-height: 72px;
    object-fit: cover;
    flex-shrink: 0;
    background: #f3f4f6;
}

.link-preview-card__body {
    padding: 8px 10px 8px 0;
    min-width: 0;
}

.link-preview-card__site {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    opacity: 0.65;
    margin: 0 0 2px;
}

.link-preview-card__title {
    font-size: 12px;
    font-weight: 700;
    line-height: 1.3;
    margin: 0 0 4px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.link-preview-card__desc {
    font-size: 11px;
    line-height: 1.35;
    opacity: 0.8;
    margin: 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.link-preview-card--skeleton {
    pointer-events: none;
}

.link-preview-card__skel-image {
    width: 88px;
    min-height: 72px;
    background: linear-gradient(90deg, #ece8e2 25%, #f5f3ef 50%, #ece8e2 75%);
    background-size: 200% 100%;
    animation: skel 1.2s ease-in-out infinite;
}

.link-preview-card__skel-lines {
    flex: 1;
    padding: 12px 10px 12px 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
    justify-content: center;
}

.link-preview-card__skel-line {
    height: 10px;
    border-radius: 999px;
    background: linear-gradient(90deg, #ece8e2 25%, #f5f3ef 50%, #ece8e2 75%);
    background-size: 200% 100%;
    animation: skel 1.2s ease-in-out infinite;
}

.link-preview-card__skel-line--short {
    width: 40%;
}

@keyframes skel {
    0% {
        background-position: 100% 0;
    }
    100% {
        background-position: -100% 0;
    }
}
</style>
