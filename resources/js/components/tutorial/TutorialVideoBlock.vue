<script setup lang="ts">
import { Play, Pause, Volume2, VolumeX } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    videoUrl?: string | null;
    posterUrl?: string | null;
    title: string;
    duration?: string;
    embedSlug?: string | null;
    embedType?: string;
    embedHeight?: number;
    embedScriptUrl?: string;
    lessonId: string;
}>();

const videoEl = ref<HTMLVideoElement | null>(null);
const playing = ref(false);
const muted = ref(true);
const showControls = ref(false);
const embedMounted = ref(false);

const hasVideo = computed(() => Boolean(props.videoUrl));
const hasEmbed = computed(() => Boolean(props.embedSlug && props.embedScriptUrl));
const embedTargetId = computed(() => `tutorial-embed-${props.lessonId}`);

function togglePlay() {
    const el = videoEl.value;

    if (!el) {
return;
}

    if (el.paused) {
        void el.play();
    } else {
        el.pause();
    }
}

function onPlay() {
    playing.value = true;
}

function onPause() {
    playing.value = false;
}

function toggleMute() {
    const el = videoEl.value;

    if (!el) {
return;
}

    el.muted = !el.muted;
    muted.value = el.muted;
}

function mountEmbed() {
    if (!hasEmbed.value || embedMounted.value) {
return;
}

    const target = document.getElementById(embedTargetId.value);

    if (!target) {
return;
}

    const existing = target.querySelector('script[data-supreme-mounted]');

    if (existing) {
        embedMounted.value = true;

        return;
    }

    const script = document.createElement('script');
    script.src = props.embedScriptUrl!;
    script.async = true;
    script.setAttribute('data-embed', props.embedSlug!);
    script.setAttribute('data-type', props.embedType ?? 'vertical_feed');
    script.setAttribute('data-height', String(props.embedHeight ?? 520));
    script.setAttribute('data-target', `#${embedTargetId.value}`);
    target.appendChild(script);
    embedMounted.value = true;
}

onMounted(() => {
    if (hasEmbed.value && !hasVideo.value) {
        requestAnimationFrame(mountEmbed);
    }
});

onBeforeUnmount(() => {
    const target = document.getElementById(embedTargetId.value);
    target?.replaceChildren();
});
</script>

<template>
    <div
        class="video-block group relative overflow-hidden rounded-2xl bg-gray-900 ring-1 ring-black/10"
        @mouseenter="showControls = true"
        @mouseleave="showControls = false"
    >
        <!-- HTML5 video -->
        <template v-if="hasVideo">
            <video
                ref="videoEl"
                class="aspect-video w-full bg-black object-contain"
                :src="videoUrl!"
                :poster="posterUrl ?? undefined"
                playsinline
                preload="metadata"
                :muted="muted"
                @play="onPlay"
                @pause="onPause"
                @click="togglePlay"
            />
            <div
                class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/50 via-transparent to-black/20"
            />
            <button
                v-if="!playing"
                type="button"
                class="pointer-events-auto absolute inset-0 flex items-center justify-center"
                aria-label="Play video"
                @click.stop="togglePlay"
            >
                <span class="flex size-16 items-center justify-center rounded-full bg-white/95 shadow-xl transition group-hover:scale-105">
                    <Play class="ml-1 size-7 text-[#E8563A]" fill="currentColor" />
                </span>
            </button>
            <div
                class="absolute bottom-0 left-0 right-0 flex items-center justify-between gap-2 px-3 py-2 transition-opacity"
                :class="showControls || !playing ? 'opacity-100' : 'opacity-0'"
            >
                <p class="truncate text-xs font-medium text-white/90">{{ title }}</p>
                <div class="flex items-center gap-1">
                    <span v-if="duration" class="rounded-md bg-black/40 px-2 py-0.5 text-[10px] font-semibold text-white">
                        {{ duration }}
                    </span>
                    <button
                        type="button"
                        class="pointer-events-auto flex size-8 items-center justify-center rounded-lg bg-black/40 text-white hover:bg-black/60"
                        :aria-label="playing ? 'Pause' : 'Play'"
                        @click.stop="togglePlay"
                    >
                        <Pause v-if="playing" class="size-3.5" />
                        <Play v-else class="size-3.5" fill="currentColor" />
                    </button>
                    <button
                        type="button"
                        class="pointer-events-auto flex size-8 items-center justify-center rounded-lg bg-black/40 text-white hover:bg-black/60"
                        :aria-label="muted ? 'Unmute' : 'Mute'"
                        @click.stop="toggleMute"
                    >
                        <VolumeX v-if="muted" class="size-3.5" />
                        <Volume2 v-else class="size-3.5" />
                    </button>
                </div>
            </div>
        </template>

        <!-- Live product embed demo -->
        <template v-else-if="hasEmbed">
            <div
                :id="embedTargetId"
                class="min-h-[320px] w-full bg-[#ece8e2] md:min-h-0"
                :style="{ minHeight: `${embedHeight ?? 520}px` }"
            />
        </template>

        <!-- Placeholder -->
        <template v-else>
            <div class="flex aspect-video w-full flex-col items-center justify-center gap-3 bg-gradient-to-br from-gray-800 to-gray-900 px-6 text-center">
                <div class="flex size-14 items-center justify-center rounded-2xl bg-white/10">
                    <Play class="size-6 text-white/60" />
                </div>
                <p class="text-sm font-semibold text-white">{{ title }}</p>
                <p class="max-w-xs text-xs text-white/50">
                    Add a video URL in <code class="rounded bg-white/10 px-1">config/tutorial.php</code>
                    or set <code class="rounded bg-white/10 px-1">TUTORIAL_VIDEO_*</code> in your .env file.
                </p>
                <span v-if="duration" class="text-[10px] font-medium uppercase tracking-wider text-white/40">
                    {{ duration }}
                </span>
            </div>
        </template>
    </div>
</template>
