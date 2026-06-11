<script setup lang="ts">
import DailyIframe from '@daily-co/daily-js';
import type { DailyCall } from '@daily-co/daily-js';
import { Camera, Loader2, Radio } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';

type PanelPhase = 'idle' | 'fetching' | 'active' | 'error';

const props = defineProps<{
    liveShowId: number;
    roomUrl?: string | null;
    hostName?: string | null;
    active?: boolean;
}>();

const emit = defineEmits<{
    joinedChange: [joined: boolean];
}>();

const containerRef = ref<HTMLDivElement | null>(null);
const phase = ref<PanelPhase>('idle');
const errorText = ref('');
const joined = ref(false);
let callFrame: DailyCall | null = null;

async function waitForLayout(): Promise<void> {
    await nextTick();
    await new Promise<void>((resolve) => {
        requestAnimationFrame(() => resolve());
    });
}

async function fetchHostToken(): Promise<{ token: string; room_url: string }> {
    const params = new URLSearchParams();

    if (props.hostName?.trim()) {
        params.set('user_name', props.hostName.trim());
    }

    const query = params.toString();
    const url = `/api/v1/admin/live-shows/${props.liveShowId}/daily/token${query ? `?${query}` : ''}`;

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
                : `Could not load host token (${response.status})`;

        throw new Error(message);
    }

    return payload as { token: string; room_url: string };
}

function clearContainer(): void {
    if (containerRef.value) {
        containerRef.value.innerHTML = '';
    }
}

function destroyFrame(): void {
    if (callFrame) {
        try {
            void callFrame.leave().catch(() => undefined);
            callFrame.destroy();
        } catch {
            // Frame may already be torn down.
        }

        callFrame = null;
    }

    clearContainer();
    joined.value = false;
    emit('joinedChange', false);
    phase.value = 'idle';
}

function handleJoinError(error: unknown): void {
    destroyFrame();
    phase.value = 'error';
    errorText.value = error instanceof Error ? error.message : 'Could not join the live room.';
    toast.error(errorText.value);
}

async function joinAsHost(): Promise<void> {
    if (!props.liveShowId || phase.value === 'fetching') {
        return;
    }

    phase.value = 'fetching';
    errorText.value = '';
    destroyFrame();
    phase.value = 'fetching';

    try {
        const { token, room_url: roomUrl } = await fetchHostToken();
        const joinUrl = (props.roomUrl ?? roomUrl ?? '').trim();

        if (!joinUrl) {
            throw new Error('Daily room URL is missing for this live cast. Save the cast and try again.');
        }

        await waitForLayout();

        const container = containerRef.value;

        if (!container) {
            throw new Error('Live room panel is not ready. Try again.');
        }

        clearContainer();

        callFrame = DailyIframe.createFrame(container, {
            showLeaveButton: true,
            showFullscreenButton: true,
            iframeStyle: {
                width: '100%',
                height: '100%',
                border: '0',
                borderRadius: '12px',
            },
        });

        callFrame.on('joined-meeting', () => {
            joined.value = true;
            emit('joinedChange', true);
        });

        callFrame.on('left-meeting', () => {
            joined.value = false;
            emit('joinedChange', false);
        });

        callFrame.on('error', (event) => {
            const message =
                event && typeof event === 'object' && 'errorMsg' in event
                    ? String((event as { errorMsg?: string }).errorMsg ?? 'Daily room error')
                    : 'Daily room error';

            phase.value = 'error';
            errorText.value = message;
            toast.error(message);
        });

        // Show the Daily room immediately (prejoin is disabled on the server).
        phase.value = 'active';

        await callFrame.join({
            url: joinUrl,
            token,
            userName: props.hostName?.trim() || 'Host',
        });
    } catch (error) {
        handleJoinError(error);
    }
}

watch(
    () => props.active,
    (active) => {
        if (!active) {
            destroyFrame();
            errorText.value = '';
        }
    },
);

onBeforeUnmount(() => {
    destroyFrame();
});

defineExpose({ joinAsHost });
</script>

<template>
    <div class="flex min-h-[420px] flex-col overflow-hidden rounded-xl border bg-black">
        <div class="flex items-center justify-between border-b border-gray-800 bg-gray-950 px-4 py-2.5">
            <div class="flex items-center gap-2 text-sm font-medium text-white">
                <Camera class="size-4 text-[#E8563A]" />
                Host live room
                <span
                    v-if="joined"
                    class="rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold uppercase"
                >
                    On air
                </span>
            </div>
            <Button
                v-if="phase === 'idle'"
                type="button"
                size="sm"
                class="bg-[#E8563A] hover:bg-[#D44A2F]"
                @click="joinAsHost"
            >
                <Radio class="mr-1.5 size-4" />
                Join as host
            </Button>
        </div>

        <div class="relative min-h-[360px] flex-1 bg-gray-950">
            <div ref="containerRef" class="absolute inset-0" />

            <div
                v-if="phase === 'idle'"
                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-4 bg-gray-950 p-8 text-center text-white"
            >
                <Radio class="size-10 text-[#E8563A]" />
                <div class="max-w-sm space-y-2">
                    <p class="text-base font-semibold">Ready to broadcast?</p>
                    <p class="text-sm text-white/70">
                        Click <strong>Join as host</strong> and allow camera/microphone to go on air.
                    </p>
                </div>
                <Button
                    type="button"
                    size="lg"
                    class="bg-[#E8563A] hover:bg-[#D44A2F]"
                    @click="joinAsHost"
                >
                    <Radio class="mr-2 size-4" />
                    Join as host
                </Button>
            </div>

            <div
                v-else-if="phase === 'fetching'"
                class="absolute inset-0 z-20 flex items-center justify-center bg-gray-950/90 text-white"
            >
                <Loader2 class="mr-2 size-5 animate-spin" />
                Connecting to live room…
            </div>

            <div
                v-else-if="phase === 'error'"
                class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-3 bg-gray-950 p-6 text-center text-sm text-white"
            >
                <p class="max-w-md">{{ errorText }}</p>
                <p
                    v-if="errorText.toLowerCase().includes('daily_api_key') || errorText.toLowerCase().includes('not configured')"
                    class="max-w-md text-xs text-white/60"
                >
                    On production, set <strong class="text-white/80">DAILY_API_KEY</strong> in your server
                    environment and redeploy (or run <code class="text-white/80">php artisan config:cache</code>).
                </p>
                <Button
                    type="button"
                    size="sm"
                    class="bg-[#E8563A] hover:bg-[#D44A2F]"
                    @click="joinAsHost"
                >
                    Try again
                </Button>
            </div>
        </div>

        <p
            v-if="joined"
            class="border-t border-gray-800 bg-gray-950 px-4 py-2 text-center text-xs text-white/60"
        >
            You are live. Share the viewer room link when ready.
        </p>
    </div>
</template>
