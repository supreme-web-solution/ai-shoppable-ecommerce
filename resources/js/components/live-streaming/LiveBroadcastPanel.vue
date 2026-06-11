<script setup lang="ts">
import { Camera, CameraOff, Loader2, Mic, MicOff, Radio, Square } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { startLiveBroadcast, type LiveBroadcastSession } from '@/lib/liveBroadcast';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    liveShowId: number;
    streamKey: string;
}>();

const emit = defineEmits<{
    liveChange: [isLive: boolean];
    previewStreamChange: [stream: MediaStream | null];
}>();

const previewRef = ref<HTMLVideoElement | null>(null);
const hasMediaStream = ref(false);
const videoEnabled = ref(true);
const micEnabled = ref(true);
const broadcasting = ref(false);
const starting = ref(false);
const previewStream = ref<MediaStream | null>(null);
const broadcastSession = ref<LiveBroadcastSession | null>(null);

async function attachPreview(stream: MediaStream): Promise<void> {
    previewStream.value = stream;
    hasMediaStream.value = true;
    videoEnabled.value = stream.getVideoTracks().some((track) => track.enabled);
    micEnabled.value = stream.getAudioTracks().some((track) => track.enabled);
    emit('previewStreamChange', stream);

    if (previewRef.value) {
        previewRef.value.srcObject = stream;
        await previewRef.value.play().catch(() => undefined);
    }
}

async function enableCamera(): Promise<void> {
    if (hasMediaStream.value && previewStream.value) {
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: 'user',
                width: { ideal: 1280 },
                height: { ideal: 720 },
            },
            audio: true,
        });

        await attachPreview(stream);
    } catch {
        toast.error('Could not access your camera or microphone. Check browser permissions.');
    }
}

function toggleMic(): void {
    if (!previewStream.value) {
        return;
    }

    micEnabled.value = !micEnabled.value;
    previewStream.value.getAudioTracks().forEach((track) => {
        track.enabled = micEnabled.value;
    });
}

function toggleCamera(): void {
    if (!previewStream.value) {
        return;
    }

    videoEnabled.value = !videoEnabled.value;
    previewStream.value.getVideoTracks().forEach((track) => {
        track.enabled = videoEnabled.value;
    });
}

async function startBroadcasting(): Promise<void> {
    if (broadcasting.value || starting.value) {
        return;
    }

    if (!props.streamKey.trim()) {
        toast.error('Stream is not ready yet. Save the cast and try again.');
        return;
    }

    if (!hasMediaStream.value || !previewStream.value) {
        await enableCamera();
    }

    if (!previewStream.value) {
        return;
    }

    starting.value = true;

    try {
        const session = await startLiveBroadcast({
            liveShowId: props.liveShowId,
            mediaStream: previewStream.value,
            onError: (message) => {
                toast.error(message);
                void endBroadcast({ keepCamera: true });
            },
        });

        broadcastSession.value = session;
        await attachPreview(session.mediaStream);

        broadcasting.value = true;
        emit('liveChange', true);
        toast.success('You are live.');
    } catch (error: unknown) {
        const message = error instanceof Error ? error.message : 'Could not start broadcasting.';
        toast.error(message);
    } finally {
        starting.value = false;
    }
}

async function endBroadcast(options: { keepCamera?: boolean } = {}): Promise<void> {
    broadcastSession.value?.stop();
    broadcastSession.value = null;
    broadcasting.value = false;
    emit('liveChange', false);

    if (options.keepCamera && previewStream.value) {
        hasMediaStream.value = true;
        await attachPreview(previewStream.value);

        return;
    }

    previewStream.value?.getTracks().forEach((track) => track.stop());
    previewStream.value = null;
    hasMediaStream.value = false;
    videoEnabled.value = true;
    micEnabled.value = true;
    emit('previewStreamChange', null);

    if (previewRef.value) {
        previewRef.value.srcObject = null;
    }
}

function stopBroadcast(): void {
    if (!broadcasting.value && !broadcastSession.value) {
        return;
    }

    void endBroadcast({ keepCamera: false });
}

watch(previewRef, async (element) => {
    if (element && previewStream.value) {
        element.srcObject = previewStream.value;
        await element.play().catch(() => undefined);
    }
});

onBeforeUnmount(() => {
    void endBroadcast({ keepCamera: false });
});

defineExpose({
    stopBroadcast,
});
</script>

<template>
    <div class="space-y-3">
        <div class="overflow-hidden rounded-xl border bg-black text-white">
            <div class="flex items-center justify-between border-b border-gray-800 bg-gray-950 px-3 py-2">
                <div class="flex items-center gap-2 text-sm font-medium">
                    <Camera class="size-4 text-[#E8563A]" />
                    Camera preview
                    <span
                        v-if="broadcasting"
                        class="rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold uppercase"
                    >
                        Live
                    </span>
                </div>
            </div>

            <div class="relative aspect-video w-full bg-gray-950">
                <video
                    ref="previewRef"
                    autoplay
                    muted
                    playsinline
                    class="h-full w-full object-cover"
                    :class="hasMediaStream && videoEnabled ? 'opacity-100' : 'opacity-0'"
                />

                <div
                    v-if="!hasMediaStream"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center"
                >
                    <CameraOff class="size-8 text-gray-500" />
                    <p class="text-sm font-medium">Enable your camera to preview before going live.</p>
                </div>

                <div
                    v-else-if="!videoEnabled"
                    class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center"
                >
                    <CameraOff class="size-8 text-gray-500" />
                    <p class="text-sm font-medium">
                        {{ broadcasting ? 'Camera is off — you are still live.' : 'Camera is off.' }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button
                v-if="!hasMediaStream"
                type="button"
                variant="outline"
                size="sm"
                :disabled="starting"
                @click="enableCamera"
            >
                <Camera class="mr-1.5 size-4" />
                Enable camera
            </Button>

            <Button
                v-else
                type="button"
                variant="outline"
                size="sm"
                :disabled="starting"
                @click="toggleCamera"
            >
                <CameraOff v-if="videoEnabled" class="mr-1.5 size-4" />
                <Camera v-else class="mr-1.5 size-4" />
                {{ videoEnabled ? 'Turn off camera' : 'Turn on camera' }}
            </Button>

            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="!hasMediaStream || starting"
                @click="toggleMic"
            >
                <MicOff v-if="!micEnabled" class="mr-1.5 size-4" />
                <Mic v-else class="mr-1.5 size-4" />
                {{ micEnabled ? 'Mute mic' : 'Unmute mic' }}
            </Button>

            <Button
                v-if="!broadcasting"
                type="button"
                size="sm"
                class="bg-[#E8563A] hover:bg-[#D44A2F]"
                :disabled="starting || !props.streamKey.trim()"
                @click="startBroadcasting"
            >
                <Loader2 v-if="starting" class="mr-1.5 size-4 animate-spin" />
                <Radio v-else class="mr-1.5 size-4" />
                {{ starting ? 'Starting…' : 'Start broadcasting' }}
            </Button>

            <Button
                v-else
                type="button"
                size="sm"
                variant="destructive"
                @click="stopBroadcast"
            >
                <Square class="mr-1.5 size-3.5 fill-current" />
                Stop broadcasting
            </Button>
        </div>
    </div>
</template>
