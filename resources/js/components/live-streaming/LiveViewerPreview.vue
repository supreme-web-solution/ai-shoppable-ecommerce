<script setup lang="ts">
import Hls from 'hls.js';
import { ExternalLink, Eye, Loader2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    hlsUrl?: string | null;
    roomUrl?: string | null;
    active?: boolean;
    publishing?: boolean;
    streamReady?: boolean;
    localStream?: MediaStream | null;
}>();

const videoRef = ref<HTMLVideoElement | null>(null);
let hls: Hls | null = null;

const statusHint = computed((): string => {
    if (props.streamReady) {
        return 'Live signal detected — viewers can watch in the room.';
    }

    if (props.publishing) {
        return 'Publishing your stream — the preview updates once playback is ready (usually 15–30 seconds).';
    }

    return 'Start broadcasting to preview what viewers will see.';
});

function destroyHls(): void {
    if (hls) {
        hls.destroy();
        hls = null;
    }
}

function attachLocalStream(stream: MediaStream | null | undefined): void {
    const element = videoRef.value;

    if (!element) {
        return;
    }

    if (stream) {
        element.srcObject = stream;
        element.muted = true;
        void element.play().catch(() => undefined);

        return;
    }

    if (!props.hlsUrl || !props.streamReady) {
        element.srcObject = null;
        element.removeAttribute('src');
    }
}

function attachHls(url: string): void {
    const element = videoRef.value;

    if (!element || !props.active) {
        return;
    }

    destroyHls();

    if (element.canPlayType('application/vnd.apple.mpegurl')) {
        element.src = url;
        void element.play().catch(() => undefined);

        return;
    }

    if (!Hls.isSupported()) {
        return;
    }

    hls = new Hls({ enableWorker: true, lowLatencyMode: true });
    hls.loadSource(url);
    hls.attachMedia(element);
    hls.on(Hls.Events.MANIFEST_PARSED, () => {
        void element.play().catch(() => undefined);
    });
}

watch(
    () => [props.localStream, props.hlsUrl, props.streamReady, props.active] as const,
    ([localStream, hlsUrl, streamReady, active]) => {
        if (!active) {
            destroyHls();

            return;
        }

        if (localStream) {
            destroyHls();
            attachLocalStream(localStream);

            return;
        }

        attachLocalStream(null);

        if (streamReady && hlsUrl) {
            attachHls(hlsUrl);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    destroyHls();

    if (videoRef.value) {
        videoRef.value.srcObject = null;
    }
});
</script>

<template>
    <div class="overflow-hidden rounded-xl border bg-black text-white">
        <div class="flex items-center justify-between border-b border-gray-800 bg-gray-950 px-3 py-2">
            <div class="flex items-center gap-2 text-sm font-medium">
                <Eye class="size-4 text-[#E8563A]" />
                Viewer preview
                <span
                    v-if="streamReady"
                    class="rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold uppercase"
                >
                    Live
                </span>
            </div>
            <a
                v-if="roomUrl"
                :href="roomUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-xs text-gray-400 hover:text-white"
            >
                Open room
                <ExternalLink class="size-3" />
            </a>
        </div>

        <div class="relative aspect-video w-full bg-gray-950">
            <video
                ref="videoRef"
                autoplay
                playsinline
                class="h-full w-full object-cover"
                :class="localStream || (streamReady && hlsUrl) ? 'opacity-100' : 'opacity-0'"
            />

            <div
                v-if="!localStream && !(streamReady && hlsUrl)"
                class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center"
            >
                <Loader2 v-if="publishing" class="size-7 animate-spin text-[#E8563A]" />
                <Eye v-else class="size-7 text-gray-500" />
                <p class="text-sm font-medium">{{ statusHint }}</p>
                <p class="max-w-xs text-xs text-gray-400">
                    Open the webinar room in a new tab to validate final viewer playback.
                </p>
            </div>
        </div>
    </div>
</template>
