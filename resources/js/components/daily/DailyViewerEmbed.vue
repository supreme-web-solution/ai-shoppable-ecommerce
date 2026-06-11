<script setup lang="ts">
import DailyIframe from '@daily-co/daily-js';
import type { DailyCall, DailyParticipant } from '@daily-co/daily-js';
import { Loader2, Play } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    webinarId: number;
    userName?: string | null;
    active?: boolean;
}>();

const videoRef = ref<HTMLVideoElement | null>(null);
const loading = ref(false);
const errorText = ref('');
const waitingForHost = ref(false);
const needsUnmute = ref(false);
const userEnabledSound = ref(false);
let callObject: DailyCall | null = null;
let loadGeneration = 0;

async function waitForLayout(): Promise<void> {
    await nextTick();
    await new Promise<void>((resolve) => {
        requestAnimationFrame(() => resolve());
    });
}

async function fetchViewerToken(userName: string): Promise<{ token: string; room_url: string }> {
    const params = new URLSearchParams();

    if (userName.trim()) {
        params.set('user_name', userName.trim());
    }

    const query = params.toString();
    const url = `/api/v1/player/webinars/${props.webinarId}/daily-token${query ? `?${query}` : ''}`;

    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        const message =
            payload && typeof payload === 'object' && 'message' in payload
                ? String((payload as { message: string }).message)
                : `Could not join live room (${response.status})`;

        throw new Error(message);
    }

    return payload as { token: string; room_url: string };
}

function findHostParticipant(): DailyParticipant | null {
    if (!callObject) {
        return null;
    }

    const participants = callObject.participants();

    for (const participant of Object.values(participants)) {
        if (participant.owner) {
            return participant;
        }
    }

    for (const participant of Object.values(participants)) {
        if (!participant.local && participant.tracks?.video?.state === 'playable') {
            return participant;
        }
    }

    return null;
}

function attachHostVideo(): void {
    const video = videoRef.value;

    if (!video || !callObject) {
        return;
    }

    const host = findHostParticipant();
    const videoTrack =
        host?.tracks?.video?.state === 'playable'
            ? (host.tracks.video.persistentTrack ?? host.tracks.video.track ?? null)
            : null;
    const audioTrack =
        host?.tracks?.audio?.state === 'playable'
            ? (host.tracks.audio.persistentTrack ?? host.tracks.audio.track ?? null)
            : null;

    if (!videoTrack && !audioTrack) {
        waitingForHost.value = true;
        video.srcObject = null;
        needsUnmute.value = false;

        return;
    }

    waitingForHost.value = false;
    const stream = new MediaStream();

    if (videoTrack) {
        stream.addTrack(videoTrack);
    }

    if (audioTrack) {
        stream.addTrack(audioTrack);
    }

    video.srcObject = stream;
    void attemptPlayback();
}

async function attemptPlayback(): Promise<void> {
    const video = videoRef.value;

    if (!video) {
        return;
    }

    if (userEnabledSound.value) {
        video.muted = false;
        needsUnmute.value = false;
        await video.play().catch(() => undefined);

        return;
    }

    video.muted = false;

    try {
        await video.play();
        needsUnmute.value = false;
    } catch {
        video.muted = true;
        needsUnmute.value = Boolean(video.srcObject);
        await video.play().catch(() => undefined);
    }
}

function enableSound(): void {
    const video = videoRef.value;

    if (!video) {
        return;
    }

    userEnabledSound.value = true;
    video.muted = false;
    needsUnmute.value = false;
    void video.play().catch(() => undefined);
}

function destroyCall(): void {
    if (callObject) {
        void callObject.leave().catch(() => undefined);
        callObject.destroy();
        callObject = null;
    }

    if (videoRef.value) {
        videoRef.value.srcObject = null;
    }

    waitingForHost.value = false;
    needsUnmute.value = false;
    userEnabledSound.value = false;
}

async function loadViewerRoom(): Promise<void> {
    const generation = ++loadGeneration;

    if (!props.active || !props.webinarId) {
        destroyCall();
        loading.value = false;
        errorText.value = '';
        return;
    }

    loading.value = true;
    errorText.value = '';
    waitingForHost.value = false;
    destroyCall();

    try {
        const viewerName = props.userName?.trim() || 'Viewer';
        const { token, room_url: roomUrl } = await fetchViewerToken(viewerName);

        if (!roomUrl) {
            throw new Error('Live room is not ready yet.');
        }

        if (generation !== loadGeneration) {
            return;
        }

        await waitForLayout();

        callObject = DailyIframe.createCallObject({
            subscribeToTracksAutomatically: true,
        });

        callObject.on('joined-meeting', () => {
            attachHostVideo();
        });

        callObject.on('participant-joined', () => {
            attachHostVideo();
        });

        callObject.on('participant-updated', () => {
            attachHostVideo();
        });

        callObject.on('participant-left', () => {
            attachHostVideo();
        });

        callObject.on('track-started', () => {
            attachHostVideo();
        });

        callObject.on('track-stopped', () => {
            attachHostVideo();
        });

        callObject.on('error', (event) => {
            const message =
                event && typeof event === 'object' && 'errorMsg' in event
                    ? String((event as { errorMsg?: string }).errorMsg ?? 'Live stream error')
                    : 'Live stream error';

            errorText.value = message;
        });

        await callObject.join({
            url: roomUrl,
            token,
            userName: viewerName,
        });

        if (generation !== loadGeneration) {
            return;
        }

        attachHostVideo();
    } catch (error) {
        if (generation !== loadGeneration) {
            return;
        }

        destroyCall();
        errorText.value = error instanceof Error ? error.message : 'Could not join the live room.';
    } finally {
        if (generation === loadGeneration) {
            loading.value = false;
        }
    }
}

watch(
    () => [props.active, props.webinarId] as const,
    () => {
        void loadViewerRoom();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    destroyCall();
});
</script>

<template>
    <div class="absolute inset-0 overflow-hidden bg-black">
        <video
            ref="videoRef"
            autoplay
            playsinline
            class="absolute inset-0 h-full w-full object-contain"
        />

        <div
            v-if="loading"
            class="absolute inset-0 z-10 flex items-center justify-center bg-black/80"
        >
            <div class="text-center text-white">
                <Loader2 class="mx-auto mb-3 size-10 animate-spin text-[#E8563A]" />
                <p class="text-sm font-semibold">Loading live stream…</p>
            </div>
        </div>

        <div
            v-else-if="errorText"
            class="absolute inset-0 z-10 flex items-center justify-center bg-black/80 p-6 text-center text-sm text-white"
        >
            <div>
                <p class="font-semibold">Could not load live stream</p>
                <p class="mt-2 text-white/70">{{ errorText }}</p>
            </div>
        </div>

        <div
            v-else-if="waitingForHost"
            class="absolute inset-0 z-10 flex items-center justify-center bg-black/80 p-6 text-center text-sm text-white"
        >
            <div>
                <p class="font-semibold">Waiting for host</p>
                <p class="mt-2 text-white/70">The stream will appear when the host goes live.</p>
            </div>
        </div>

        <div
            v-if="needsUnmute && !waitingForHost && !loading && !errorText"
            class="absolute inset-x-0 bottom-0 z-20 flex justify-center p-4"
        >
            <Button
                type="button"
                class="bg-[#E8563A] hover:bg-[#D44A2F]"
                @click="enableSound"
            >
                <Play class="mr-2 size-4 fill-current" />
                Tap to enable sound
            </Button>
        </div>
    </div>
</template>
