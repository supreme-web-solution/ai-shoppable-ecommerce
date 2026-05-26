<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

type VideoItem = {
    id: number;
    title: string;
    thumbnail_url?: string | null;
    playback_url?: string | null;
    description?: string | null;
};

const props = defineProps<{
    embedSlug: string;
}>();

const loading = ref(true);
const videos = ref<VideoItem[]>([]);
const activeIndex = ref(0);

const activeVideo = computed(() => videos.value[activeIndex.value] ?? null);

async function loadFeed() {
    loading.value = true;

    try {
        const response = await fetch(
            `/api/v1/player/feed?embed_slug=${encodeURIComponent(props.embedSlug)}&per_page=20`,
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
        videos.value = payload.data ?? [];
    } catch {
        videos.value = [];
    } finally {
        loading.value = false;
    }
}

function selectVideo(index: number) {
    activeIndex.value = index;
}

onMounted(loadFeed);
</script>

<template>
    <div class="mx-auto flex min-h-screen max-w-5xl flex-col bg-zinc-950 text-white">
        <div v-if="loading" class="flex flex-1 items-center justify-center text-sm text-white/70">
            Loading carousel...
        </div>

        <div v-else-if="!activeVideo" class="flex flex-1 items-center justify-center text-sm text-white/70">
            No videos published yet.
        </div>

        <template v-else>
            <div class="relative aspect-[9/16] w-full max-w-md self-center bg-black sm:aspect-video sm:max-w-none">
                <video
                    :key="activeVideo.id"
                    class="h-full w-full object-cover"
                    :src="activeVideo.playback_url || ''"
                    :poster="activeVideo.thumbnail_url || ''"
                    controls
                    autoplay
                    muted
                    playsinline
                />
            </div>

            <div class="border-t border-white/10 p-4">
                <h2 class="text-lg font-semibold">{{ activeVideo.title }}</h2>
                <p class="mt-1 line-clamp-2 text-sm text-white/70">{{ activeVideo.description }}</p>
            </div>

            <div class="flex gap-3 overflow-x-auto px-4 pb-4">
                <button
                    v-for="(video, index) in videos"
                    :key="video.id"
                    type="button"
                    class="min-w-[120px] shrink-0 overflow-hidden rounded-lg border-2 transition"
                    :class="index === activeIndex ? 'border-rose-500' : 'border-transparent opacity-70'"
                    @click="selectVideo(index)"
                >
                    <img
                        :src="video.thumbnail_url || ''"
                        :alt="video.title"
                        class="aspect-[9/16] w-full object-cover"
                    >
                    <p class="truncate px-2 py-1 text-xs">{{ video.title }}</p>
                </button>
            </div>
        </template>
    </div>
</template>
