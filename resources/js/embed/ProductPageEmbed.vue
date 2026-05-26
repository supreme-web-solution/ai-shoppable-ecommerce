<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

type ProductTag = {
    id: number;
    cta_label?: string | null;
    product?: {
        id: number;
        title: string;
        price: string;
        sale_price?: string | null;
        image_url?: string | null;
    };
};

type VideoItem = {
    id: number;
    team_id: number;
    title: string;
    description?: string | null;
    playback_url?: string | null;
    thumbnail_url?: string | null;
    product_tags?: ProductTag[];
};

const props = defineProps<{
    embedSlug: string;
}>();

const loading = ref(true);
const video = ref<VideoItem | null>(null);
const sessionKey = `embed-${Math.random().toString(36).slice(2)}`;

const primaryTag = computed(() => video.value?.product_tags?.[0] ?? null);

async function loadVideo() {
    loading.value = true;

    try {
        const response = await fetch(
            `/api/v1/player/feed?embed_slug=${encodeURIComponent(props.embedSlug)}&per_page=1`,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Embed-Slug': props.embedSlug,
                },
            },
        );

        if (!response.ok) {
            throw new Error('Feed request failed');
        }

        const payload = (await response.json()) as { data?: VideoItem[] };
        video.value = payload.data?.[0] ?? null;
    } catch {
        video.value = null;
    } finally {
        loading.value = false;
    }
}

async function addToCart() {
    if (!video.value || !primaryTag.value?.product) {
        return;
    }

    await fetch('/api/v1/player/cart/items', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Embed-Slug': props.embedSlug,
        },
        body: JSON.stringify({
            team_id: video.value.team_id,
            session_key: sessionKey,
            product_id: primaryTag.value.product.id,
            quantity: 1,
        }),
    });
}

onMounted(loadVideo);
</script>

<template>
    <div class="mx-auto min-h-screen max-w-3xl bg-white text-zinc-900">
        <div v-if="loading" class="flex min-h-[320px] items-center justify-center text-sm text-zinc-500">
            Loading product video...
        </div>

        <div v-else-if="!video" class="flex min-h-[320px] items-center justify-center text-sm text-zinc-500">
            No product video available.
        </div>

        <template v-else>
            <div class="grid gap-0 md:grid-cols-2">
                <div class="bg-black">
                    <video
                        class="aspect-[9/16] w-full object-cover md:min-h-full"
                        :src="video.playback_url || ''"
                        :poster="video.thumbnail_url || ''"
                        controls
                        autoplay
                        muted
                        playsinline
                    />
                </div>

                <div class="flex flex-col justify-center gap-4 p-6">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-zinc-500">Shoppable video</p>
                        <h1 class="mt-1 text-2xl font-semibold">{{ video.title }}</h1>
                        <p class="mt-2 text-sm text-zinc-600">{{ video.description }}</p>
                    </div>

                    <div v-if="primaryTag?.product" class="rounded-xl border p-4">
                        <div class="flex gap-3">
                            <img
                                v-if="primaryTag.product.image_url"
                                :src="primaryTag.product.image_url"
                                :alt="primaryTag.product.title"
                                class="h-20 w-20 rounded-lg object-cover"
                            >
                            <div>
                                <p class="font-medium">{{ primaryTag.product.title }}</p>
                                <p class="text-lg font-semibold">
                                    {{ primaryTag.product.sale_price || primaryTag.product.price }}
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            class="mt-4 w-full rounded-lg bg-zinc-900 px-4 py-3 text-sm font-medium text-white"
                            @click="addToCart"
                        >
                            {{ primaryTag.cta_label || 'Add to cart' }}
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
