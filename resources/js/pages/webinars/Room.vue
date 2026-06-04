<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    Bot,
    ExternalLink,
    Loader2,
    MessageSquare,
    Pin,
    Play,
    RotateCcw,
    Send,
    ShoppingBag,
    Users,
    Video,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type OfferAppearance = 'pin' | 'in_chat' | 'popup';

type WebinarOffer = {
    id: number;
    title: string;
    image_url?: string | null;
    price?: string | null;
    sale_price?: string | null;
    currency?: string;
    starts_at_ms: number;
    ends_at_ms?: number | null;
    appearance: OfferAppearance;
    checkout_url?: string | null;
    cta_url?: string | null;
};

type WebinarData = {
    id: number;
    title: string;
    host_name?: string | null;
    room_title?: string | null;
    video_url?: string | null;
    thumbnail_url?: string | null;
    chat_enabled?: boolean;
    video_duration_seconds?: number | null;
    featured_products?: WebinarOffer[];
};

type RoomMessage = {
    id: number;
    sender_type: 'host' | 'attendee' | 'ai' | 'system' | 'offer';
    sender_name?: string | null;
    live_show_registration_id?: number | null;
    message: string;
    is_pinned: boolean;
    created_at: string;
    offer?: WebinarOffer | null;
};

const page = usePage();
const webinarId = Number((page.props as Record<string, unknown>).webinarId ?? 0);
const registrationId = Number((page.props as Record<string, unknown>).registrationId ?? 0);

const loading = ref(false);
const joiningChat = ref(false);
const posting = ref(false);
const checkoutLoadingId = ref<number | null>(null);
const errorText = ref('');
const webinar = ref<WebinarData | null>(null);
const messages = ref<RoomMessage[]>([]);
const messageDraft = ref('');
const joinChatForm = ref({ full_name: '', email: '' });
const activeRegistrationId = ref(0);
const pollRef = ref<number | null>(null);
const videoRef = ref<HTMLVideoElement | null>(null);
const videoStarted = ref(false);
const videoEnded = ref(false);
const videoCurrentMs = ref(0);
const dismissedPopupIds = ref<number[]>([]);
const injectedOfferChatKeys = ref<string[]>([]);
const lastWatchReportAtMs = ref(-1);
const watchReportInFlight = ref(false);
const watchHalfReported = ref(false);
const watchEndReported = ref(false);
let offerMessageId = 1_000_000;

const DEFAULT_CHAT_WELCOME =
    "Welcome! Feel free to ask questions in the chat — we're glad you're here.";

const hostWelcomeMessage = computed((): RoomMessage | null => {
    if (!webinar.value?.chat_enabled) {
        return null;
    }

    return {
        id: 0,
        sender_type: 'host',
        sender_name: webinar.value.host_name?.trim() || 'Host',
        message: DEFAULT_CHAT_WELCOME,
        is_pinned: true,
        created_at: '1970-01-01T00:00:00.000Z',
    };
});

const pinnedHostMessage = computed(() =>
    messages.value.find(
        (message) => message.is_pinned && message.sender_type !== 'offer',
    ),
);

const canChat = computed(
    () => Boolean(webinar.value?.chat_enabled) && activeRegistrationId.value > 0,
);

function registrationStorageKey(id: number = webinarId): string {
    return `webinar_registration_${id}`;
}

function readStoredRegistrationId(): number {
    const raw = sessionStorage.getItem(registrationStorageKey());

    if (!raw) {
        return 0;
    }

    const parsed = Number(raw);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
}

function persistRegistrationId(id: number): void {
    if (id > 0) {
        activeRegistrationId.value = id;
        sessionStorage.setItem(registrationStorageKey(), String(id));
    }
}

function restoreRegistrationSession(): void {
    const fromProps = registrationId > 0 ? registrationId : readStoredRegistrationId();

    if (fromProps > 0) {
        persistRegistrationId(fromProps);

        if (registrationId <= 0) {
            const url = new URL(window.location.href);
            url.searchParams.set('registration', String(fromProps));
            window.history.replaceState({}, '', url.toString());
        }
    }
}

const activeOffers = computed(() => {
    const offers = webinar.value?.featured_products ?? [];
    const t = videoCurrentMs.value;

    return offers.filter((offer) => {
        const start = offer.starts_at_ms ?? 0;
        const end = offer.ends_at_ms;

        return t >= start && (end == null || t <= end);
    });
});

const pinnedOffers = computed(() =>
    activeOffers.value.filter((offer) => offer.appearance === 'pin'),
);

const popupOffers = computed(() =>
    activeOffers.value.filter(
        (offer) =>
            offer.appearance === 'popup' &&
            !dismissedPopupIds.value.includes(offer.id),
    ),
);

const displayMessages = computed(() => {
    const base = messages.value.filter((m) => m.sender_type !== 'offer');
    const welcome = hostWelcomeMessage.value;
    const hasWelcomeInThread =
        welcome !== null
        && base.some(
            (message) =>
                message.sender_type === 'host' && message.message === welcome.message,
        );
    const seeded = welcome && !hasWelcomeInThread ? [welcome] : [];

    return [...seeded, ...base, ...offerChatMessages.value].sort(
        (a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime(),
    );
});

const offerChatMessages = ref<RoomMessage[]>([]);

watch(activeOffers, (offers) => {
    for (const offer of offers) {
        if (offer.appearance !== 'in_chat') {
            continue;
        }

        const key = `offer-${offer.id}`;

        if (injectedOfferChatKeys.value.includes(key)) {
            continue;
        }

        injectedOfferChatKeys.value.push(key);
        offerChatMessages.value.push({
            id: offerMessageId++,
            sender_type: 'offer',
            sender_name: 'Featured offer',
            message: `Shop ${offer.title}`,
            is_pinned: false,
            created_at: new Date().toISOString(),
            offer,
        });
    }
});

async function apiFetch<T>(url: string, options: RequestInit = {}): Promise<T> {
    const headers = new Headers(options.headers ?? {});
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const response = await fetch(url, {
        ...options,
        headers,
        credentials: 'same-origin',
    });

    if (response.status === 204) {
        return undefined as T;
    }

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        const message =
            payload && typeof payload === 'object' && 'message' in payload
                ? String((payload as { message: string }).message)
                : `Request failed (${response.status})`;

        throw new Error(message);
    }

    return payload as T;
}

function isOwnMessage(message: RoomMessage): boolean {
    if (message.sender_type !== 'attendee') {
        return false;
    }

    const regId = activeRegistrationId.value;

    if (regId > 0 && message.live_show_registration_id != null) {
        return message.live_show_registration_id === regId;
    }

    return false;
}

function messageRowClass(message: RoomMessage): string {
    if (message.sender_type === 'offer') {
        return 'justify-center';
    }

    return isOwnMessage(message) ? 'justify-end' : 'justify-start';
}

function messageBubbleClass(message: RoomMessage): string {
    if (message.sender_type === 'offer') {
        return 'message-bubble message-bubble--offer';
    }

    if (message.sender_type === 'attendee') {
        return isOwnMessage(message)
            ? 'message-bubble message-bubble--own'
            : 'message-bubble message-bubble--incoming';
    }

    if (message.sender_type === 'ai') {
        return 'message-bubble message-bubble--ai';
    }

    if (message.sender_type === 'host') {
        return 'message-bubble message-bubble--host';
    }

    return 'message-bubble message-bubble--incoming';
}

function messageMetaClass(message: RoomMessage): string {
    return isOwnMessage(message) ? 'message-meta message-meta--own' : 'message-meta';
}

function formatMoney(offer: WebinarOffer): string {
    const value = offer.sale_price || offer.price;

    if (!value) {
        return '';
    }

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: offer.currency || 'USD',
    }).format(Number(value));
}

function syncVideoTimeline() {
    if (!videoRef.value) {
        return;
    }

    videoCurrentMs.value = Math.floor(videoRef.value.currentTime * 1000);
}

function webinarDurationMs(): number {
    const seconds = Number(webinar.value?.video_duration_seconds ?? 0);

    return Number.isFinite(seconds) && seconds > 0 ? Math.floor(seconds * 1000) : 0;
}

async function reportWatchProgress(positionMs: number, completed = false) {
    const durationMs = webinarDurationMs();

    if (durationMs <= 0 || activeRegistrationId.value <= 0 || watchReportInFlight.value) {
        return;
    }

    const halfMs = Math.floor(durationMs * 0.5);
    const intervalElapsed =
        lastWatchReportAtMs.value < 0 || positionMs - lastWatchReportAtMs.value >= 5000;
    const shouldReport =
        completed
        || (!watchHalfReported.value && positionMs >= halfMs)
        || (!watchEndReported.value && completed)
        || intervalElapsed;

    if (!shouldReport) {
        return;
    }

    watchReportInFlight.value = true;

    try {
        await apiFetch<{ data: { reached_half_at?: string | null; watched_to_end_at?: string | null } }>(
            `/api/v1/player/webinars/${webinarId}/watch-progress`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    registration_id: activeRegistrationId.value,
                    position_ms: positionMs,
                    completed,
                }),
            },
        );

        lastWatchReportAtMs.value = positionMs;

        if (positionMs >= halfMs) {
            watchHalfReported.value = true;
        }

        if (completed) {
            watchEndReported.value = true;
        }
    } catch {
        // Ignore progress reporting failures so playback is not interrupted.
    } finally {
        watchReportInFlight.value = false;
    }
}

function onVideoTimeUpdate() {
    syncVideoTimeline();
    void reportWatchProgress(videoCurrentMs.value);
}

function onVideoPlay() {
    videoStarted.value = true;
    videoEnded.value = false;
    syncVideoTimeline();
}

function onVideoEnded() {
    videoEnded.value = true;
    syncVideoTimeline();
    void reportWatchProgress(videoCurrentMs.value, true);
}

function resetOfferPlaybackState() {
    injectedOfferChatKeys.value = [];
    offerChatMessages.value = [];
    dismissedPopupIds.value = [];
}

async function startVideoPlayback() {
    const video = videoRef.value;

    if (!video) {
        return;
    }

    try {
        video.muted = false;
        await video.play();
        videoStarted.value = true;
        syncVideoTimeline();
    } catch {
        errorText.value = 'Could not start playback. Please try again.';
    }
}

async function replayVideo() {
    const video = videoRef.value;

    if (!video) {
        return;
    }

    videoEnded.value = false;
    resetOfferPlaybackState();
    lastWatchReportAtMs.value = -1;
    watchHalfReported.value = false;
    watchEndReported.value = false;
    video.currentTime = 0;
    videoCurrentMs.value = 0;

    try {
        video.muted = false;
        await video.play();
        videoStarted.value = true;
        syncVideoTimeline();
    } catch {
        errorText.value = 'Could not replay video. Please try again.';
    }
}

async function loadRoom() {
    loading.value = true;
    errorText.value = '';

    try {
        const [webinarPayload, messagesPayload] = await Promise.all([
            apiFetch<{ data: WebinarData }>(`/api/v1/player/webinars/${webinarId}`),
            apiFetch<{ data: RoomMessage[] }>(`/api/v1/player/webinars/${webinarId}/messages`),
        ]);
        webinar.value = webinarPayload.data;
        messages.value = messagesPayload.data ?? [];
    } catch (error) {
        errorText.value =
            error instanceof Error ? error.message : 'Could not load webinar room.';
    } finally {
        loading.value = false;
    }
}

async function pollMessages() {
    const lastId =
        messages.value.length > 0 ? messages.value[messages.value.length - 1].id : 0;

    try {
        const payload = await apiFetch<{ data: RoomMessage[] }>(
            `/api/v1/player/webinars/${webinarId}/messages?after_id=${lastId}`,
        );

        if (payload.data?.length) {
            messages.value = [...messages.value, ...payload.data];
        }
    } catch {
        /* keep room stable */
    }
}

async function joinChat() {
    if (!joinChatForm.value.full_name.trim() || !joinChatForm.value.email.trim()) {
        errorText.value = 'Enter your name and email to join the chat.';

        return;
    }

    joiningChat.value = true;
    errorText.value = '';

    try {
        const response = await apiFetch<{ data: { registration_id: number } }>(
            `/api/v1/player/webinars/${webinarId}/register`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    full_name: joinChatForm.value.full_name.trim(),
                    email: joinChatForm.value.email.trim(),
                }),
            },
        );

        persistRegistrationId(Number(response.data.registration_id));
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not join chat.';
    } finally {
        joiningChat.value = false;
    }
}

async function sendMessage() {
    if (!messageDraft.value.trim() || posting.value || !canChat.value) {
        return;
    }

    posting.value = true;

    try {
        const payload = await apiFetch<{ data: RoomMessage[] }>(
            `/api/v1/player/webinars/${webinarId}/messages`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    registration_id: activeRegistrationId.value,
                    message: messageDraft.value.trim(),
                }),
            },
        );

        if (payload.data?.length) {
            messages.value = [...messages.value, ...payload.data];
        }

        messageDraft.value = '';
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not send message.';
    } finally {
        posting.value = false;
    }
}

async function checkoutOffer(offer: WebinarOffer) {
    if (checkoutLoadingId.value !== null) {
        return;
    }

    const directUrl = (offer.cta_url || offer.checkout_url || '').trim();

    if (directUrl.startsWith('http://') || directUrl.startsWith('https://')) {
        window.open(directUrl, '_blank', 'noopener,noreferrer');

        return;
    }

    checkoutLoadingId.value = offer.id;
    errorText.value = '';

    try {
        const payload = await apiFetch<{ checkout_url?: string; message?: string }>(
            `/api/v1/player/webinars/${webinarId}/offers/${offer.id}/checkout`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    registration_id: activeRegistrationId.value > 0 ? activeRegistrationId.value : null,
                }),
            },
        );

        if (payload.checkout_url) {
            window.location.href = payload.checkout_url;
        }
    } catch (error) {
        errorText.value =
            error instanceof Error ? error.message : 'Could not start checkout.';
    } finally {
        checkoutLoadingId.value = null;
    }
}

function dismissPopup(offerId: number) {
    if (!dismissedPopupIds.value.includes(offerId)) {
        dismissedPopupIds.value.push(offerId);
    }
}

onMounted(async () => {
    restoreRegistrationSession();
    await loadRoom();
    pollRef.value = window.setInterval(pollMessages, 5000);
});

onBeforeUnmount(() => {
    if (pollRef.value !== null) {
        window.clearInterval(pollRef.value);
    }
});
</script>

<template>
    <Head title="Webinar Room" />

    <div class="room-root min-h-screen p-4 md:p-6">
        <div class="mx-auto w-full max-w-[1380px] space-y-4">
            <header class="room-header flex flex-wrap items-center justify-between gap-3 rounded-3xl p-4">
                <div class="flex items-center gap-3">
                    <div class="brand-icon flex size-11 items-center justify-center rounded-2xl">
                        <Video class="size-5 text-white" />
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.22em] text-[#E8563A]">
                            Live Commerce Room
                        </p>
                        <h1 class="text-xl font-black tracking-tight text-gray-900">
                            {{ webinar?.title || 'Webinar Room' }}
                        </h1>
                        <p class="text-sm text-gray-500">
                            Hosted by {{ webinar?.host_name || 'Host' }}
                        </p>
                    </div>
                </div>

                <div
                    class="live-pill inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-black text-white"
                >
                    <span class="relative flex size-2">
                        <span
                            class="absolute inline-flex size-full animate-ping rounded-full bg-white opacity-75"
                        />
                        <span class="relative inline-flex size-2 rounded-full bg-white" />
                    </span>
                    LIVE NOW
                </div>
            </header>

            <div class="grid gap-4 lg:grid-cols-[1.75fr_1fr]">
                <section class="video-card relative rounded-3xl p-3 md:p-4">
                    <div class="video-stage relative aspect-video overflow-hidden rounded-2xl bg-black">
                        <video
                            v-if="webinar?.video_url"
                            ref="videoRef"
                            :src="webinar.video_url"
                            playsinline
                            preload="metadata"
                            class="absolute inset-0 h-full w-full object-contain"
                            :poster="webinar.thumbnail_url || undefined"
                            @timeupdate="onVideoTimeUpdate"
                            @loadedmetadata="syncVideoTimeline"
                            @seeked="syncVideoTimeline"
                            @play="onVideoPlay"
                            @ended="onVideoEnded"
                        />
                        <div
                            v-else
                            class="empty-video absolute inset-0 flex items-center justify-center"
                        >
                            <div class="text-center">
                                <Video class="mx-auto mb-2 size-10 text-white/60" />
                                <p class="text-sm font-semibold text-white/70">
                                    Video will appear here
                                </p>
                            </div>
                        </div>

                        <div
                            class="pointer-events-none absolute inset-x-0 top-0 bg-linear-to-b from-black/70 to-transparent p-4"
                        >
                            <div
                                class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1.5 text-xs font-bold text-white backdrop-blur-md"
                            >
                                <Users class="size-3.5" />
                                Interactive live room
                            </div>
                        </div>

                        <div
                            v-if="webinar?.video_url && !videoStarted"
                            class="absolute inset-0 z-10 flex items-center justify-center bg-black/45 p-4"
                        >
                            <button
                                type="button"
                                class="video-play-btn flex flex-col items-center gap-3 rounded-2xl bg-white/95 px-8 py-6 text-center shadow-2xl transition-transform hover:scale-[1.02] active:scale-[0.98]"
                                @click="startVideoPlayback"
                            >
                                <span
                                    class="flex size-16 items-center justify-center rounded-full bg-[#E8563A] text-white shadow-lg"
                                >
                                    <Play class="ml-1 size-8 fill-current" />
                                </span>
                                <span class="text-sm font-bold text-gray-900">
                                    Click to start video
                                </span>
                            </button>
                        </div>

                        <div
                            v-if="webinar?.video_url && videoStarted && videoEnded"
                            class="absolute inset-0 z-30 flex items-center justify-center bg-black/75 p-4"
                        >
                            <div
                                class="video-ended-cover flex max-w-sm flex-col items-center gap-4 rounded-2xl bg-white/95 px-8 py-8 text-center shadow-2xl"
                            >
                                <span
                                    class="flex size-14 items-center justify-center rounded-full bg-gray-100 text-[#E8563A]"
                                >
                                    <Video class="size-7" />
                                </span>
                                <div>
                                    <p class="text-lg font-black text-gray-900">
                                        Video ended
                                    </p>
                                    <p class="mt-1 text-sm text-gray-500">
                                        Thanks for watching.
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    class="w-full bg-[#E8563A] hover:bg-[#D44A2F]"
                                    @click="replayVideo"
                                >
                                    <RotateCcw class="mr-2 size-4" />
                                    Replay
                                </Button>
                            </div>
                        </div>

                        <!-- Popup offers (overlay on video) -->
                        <div
                            v-for="offer in popupOffers"
                            :key="`popup-${offer.id}`"
                            class="offer-popup pointer-events-auto absolute inset-0 z-20 flex items-center justify-center bg-black/55 p-4"
                        >
                        <article class="offer-popup-card w-full max-w-sm rounded-2xl bg-white p-4 shadow-2xl">
                            <div class="mb-3 flex items-start justify-between gap-2">
                                <p class="text-xs font-bold uppercase tracking-wide text-[#E8563A]">
                                    Limited offer
                                </p>
                                <button
                                    type="button"
                                    class="rounded-full p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                                    aria-label="Dismiss"
                                    @click="dismissPopup(offer.id)"
                                >
                                    <X class="size-4" />
                                </button>
                            </div>
                            <div class="flex gap-3">
                                <div
                                    class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-gray-100"
                                >
                                    <img
                                        v-if="offer.image_url"
                                        :src="offer.image_url"
                                        :alt="offer.title"
                                        class="h-full w-full object-cover"
                                    >
                                    <ShoppingBag v-else class="size-6 text-gray-400" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="font-bold text-gray-900">{{ offer.title }}</p>
                                    <p class="mt-1 text-lg font-black text-[#E8563A]">
                                        {{ formatMoney(offer) }}
                                    </p>
                                </div>
                            </div>
                            <Button
                                class="mt-4 w-full bg-[#E8563A] hover:bg-[#D44A2F]"
                                :disabled="checkoutLoadingId === offer.id"
                                @click="checkoutOffer(offer)"
                            >
                                <Loader2
                                    v-if="checkoutLoadingId === offer.id"
                                    class="mr-2 size-4 animate-spin"
                                />
                                Shop now
                            </Button>
                        </article>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2 overflow-x-auto">
                            <button
                                v-for="emoji in ['👍', '❤️', '🔥', '👏']"
                                :key="emoji"
                                class="reaction-btn rounded-xl px-3 py-2 text-sm"
                                type="button"
                            >
                                {{ emoji }}
                            </button>
                        </div>
                        <p class="text-xs text-gray-500">
                            Special offers may appear during the session.
                        </p>
                    </div>
                </section>

                <aside class="chat-card flex min-h-[560px] flex-col rounded-3xl">
                    <div class="border-b border-[#F0EDE8] px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-black text-gray-900">
                                    {{ webinar?.room_title || 'In-call chat' }}
                                </p>
                                <p class="text-xs text-gray-500">
                                    {{ webinar?.host_name || 'Host' }}
                                </p>
                            </div>
                            <div
                                class="inline-flex items-center gap-1.5 rounded-full bg-[#E8563A]/10 px-2.5 py-1 text-xs font-bold text-[#E8563A]"
                            >
                                <Users class="size-3.5" />
                                LIVE
                            </div>
                        </div>
                    </div>

                    <div class="border-b border-[#F0EDE8] px-4 py-3">
                        <div
                            v-for="offer in pinnedOffers"
                            :key="`pin-${offer.id}`"
                            class="mb-2 rounded-xl border border-[#E8563A]/30 bg-[#E8563A]/5 p-3"
                        >
                            <div class="mb-2 flex items-center gap-1.5 text-xs font-bold text-[#E8563A]">
                                <Pin class="size-3.5" />
                                Pinned offer
                            </div>
                            <div class="flex gap-2">
                                <img
                                    v-if="offer.image_url"
                                    :src="offer.image_url"
                                    :alt="offer.title"
                                    class="h-12 w-12 rounded-lg object-cover"
                                >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-bold">{{ offer.title }}</p>
                                    <p class="text-sm font-black text-[#E8563A]">
                                        {{ formatMoney(offer) }}
                                    </p>
                                </div>
                            </div>
                            <Button
                                size="sm"
                                class="mt-2 w-full bg-[#E8563A] hover:bg-[#D44A2F]"
                                :disabled="checkoutLoadingId === offer.id"
                                @click="checkoutOffer(offer)"
                            >
                                <Loader2
                                    v-if="checkoutLoadingId === offer.id"
                                    class="mr-2 size-4 animate-spin"
                                />
                                Shop now
                            </Button>
                        </div>

                        <div
                            v-if="pinnedHostMessage"
                            class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                        >
                            <Pin class="mr-1 inline size-3.5 text-amber-600" />
                            <span class="font-bold">{{ pinnedHostMessage.sender_name || 'Pinned' }}:</span>
                            {{ pinnedHostMessage.message }}
                        </div>
                    </div>

                    <div class="message-list flex-1 space-y-2 overflow-y-auto px-4 py-3">
                        <div v-if="loading" class="space-y-2">
                            <div
                                v-for="n in 4"
                                :key="n"
                                class="h-14 rounded-xl border border-gray-100 bg-white/70"
                            />
                        </div>
                        <div
                            v-else-if="displayMessages.length === 0"
                            class="flex h-full flex-col items-center justify-center gap-2 text-center text-sm text-gray-500"
                        >
                            <MessageSquare class="size-8 text-[#E8563A]/50" />
                            <p>No messages yet.</p>
                        </div>
                        <div
                            v-for="message in displayMessages"
                            :key="`msg-${message.id}-${message.sender_type}`"
                            class="flex w-full"
                            :class="messageRowClass(message)"
                        >
                            <article
                                :class="messageBubbleClass(message)"
                            >
                            <div
                                v-if="message.sender_type === 'offer' && message.offer"
                                class="space-y-2"
                            >
                                <div class="flex items-center gap-2 text-xs font-bold text-[#E8563A]">
                                    <ShoppingBag class="size-3.5" />
                                    Featured offer
                                </div>
                                <div class="flex gap-2">
                                    <img
                                        v-if="message.offer.image_url"
                                        :src="message.offer.image_url"
                                        :alt="message.offer.title"
                                        class="h-14 w-14 rounded-lg object-cover"
                                    >
                                    <div>
                                        <p class="font-bold text-gray-900">{{ message.offer.title }}</p>
                                        <p class="text-sm font-black text-[#E8563A]">
                                            {{ formatMoney(message.offer) }}
                                        </p>
                                    </div>
                                </div>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="w-full border-[#E8563A]/40 text-[#E8563A]"
                                    :disabled="checkoutLoadingId === message.offer.id"
                                    @click="checkoutOffer(message.offer)"
                                >
                                    <ExternalLink class="mr-1.5 size-3.5" />
                                    {{ checkoutLoadingId === message.offer.id ? 'Opening…' : 'Shop now' }}
                                </Button>
                            </div>
                            <template v-else>
                                <div :class="messageMetaClass(message)">
                                    <MessageSquare v-if="!isOwnMessage(message)" class="size-3.5 shrink-0" />
                                    <span class="font-bold">
                                        {{
                                            message.sender_name ||
                                            (message.sender_type === 'ai'
                                                ? 'AI Assistant'
                                                : message.sender_type)
                                        }}
                                    </span>
                                    <Bot v-if="message.sender_type === 'ai'" class="size-3.5 shrink-0" />
                                </div>
                                <p class="message-text">{{ message.message }}</p>
                            </template>
                            </article>
                        </div>
                    </div>

                    <div class="border-t border-[#F0EDE8] bg-white p-3">
                        <div
                            v-if="errorText"
                            class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700"
                        >
                            {{ errorText }}
                        </div>
                        <div
                            v-if="webinar?.chat_enabled && !canChat"
                            class="space-y-2"
                        >
                            <Input
                                v-model="joinChatForm.full_name"
                                class="reply-input"
                                placeholder="Your name"
                                autocomplete="name"
                            />
                            <Input
                                v-model="joinChatForm.email"
                                type="email"
                                class="reply-input"
                                placeholder="Email"
                                autocomplete="email"
                                @keyup.enter="joinChat"
                            />
                            <Button
                                class="w-full bg-[#E8563A] hover:bg-[#D44A2F]"
                                :disabled="joiningChat"
                                @click="joinChat"
                            >
                                <Loader2 v-if="joiningChat" class="mr-2 size-4 animate-spin" />
                                Join chat
                            </Button>
                        </div>
                        <div
                            v-else-if="webinar?.chat_enabled"
                            class="flex items-center gap-2"
                        >
                            <Input
                                v-model="messageDraft"
                                class="reply-input"
                                placeholder="Send a message..."
                                @keyup.enter="sendMessage"
                            />
                            <Button
                                class="send-btn"
                                size="icon"
                                :disabled="posting"
                                @click="sendMessage"
                            >
                                <Loader2 v-if="posting" class="size-4 animate-spin" />
                                <Send v-else class="size-4" />
                            </Button>
                        </div>
                        <p
                            v-else
                            class="text-center text-xs text-gray-500"
                        >
                            Chat is disabled for this session.
                        </p>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</template>

<style scoped>
.room-root {
    background:
        radial-gradient(circle at 0% 0%, rgba(232, 86, 58, 0.12), transparent 28%),
        radial-gradient(circle at 100% 0%, rgba(255, 140, 66, 0.12), transparent 30%),
        #f2efea;
}

.room-header,
.video-card,
.chat-card {
    background: #fff;
    border: 1px solid #f0ede8;
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.04),
        0 8px 28px rgba(0, 0, 0, 0.08);
}

.brand-icon,
.live-pill {
    background: linear-gradient(135deg, #e8563a, #ff8c42);
    box-shadow: 0 4px 14px rgba(232, 86, 58, 0.35);
}

.empty-video {
    background: linear-gradient(135deg, #1f2937, #111827);
}

.video-play-btn {
    max-width: 100%;
}

.reaction-btn {
    background: #faf8f5;
    border: 1px solid #f0ede8;
    transition: all 0.15s;
}

.reaction-btn:hover {
    border-color: rgba(232, 86, 58, 0.35);
    background: rgba(232, 86, 58, 0.05);
}

.message-list {
    background:
        linear-gradient(rgba(255, 255, 255, 0.55) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.55) 1px, transparent 1px),
        #f8f5f0;
    background-size: 28px 28px;
}

.message-bubble {
    max-width: min(85%, 20rem);
    border-radius: 1rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    line-height: 1.45;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
}

.message-bubble--own {
    border: 1px solid rgba(232, 86, 58, 0.35);
    border-bottom-right-radius: 0.25rem;
    background: linear-gradient(135deg, #e8563a, #ff8c42);
    color: #fff;
}

.message-bubble--host {
    border: 1px solid rgba(232, 86, 58, 0.35);
    border-bottom-left-radius: 0.25rem;
    background: linear-gradient(135deg, #e8563a, #ff8c42);
    color: #fff;
}

.message-bubble--host .message-meta {
    color: rgba(255, 255, 255, 0.8);
}

.message-bubble--ai {
    border: 1px solid rgba(232, 86, 58, 0.2);
    border-bottom-left-radius: 0.25rem;
    background: #fff8f5;
    color: #1f2937;
}

.message-bubble--incoming {
    border: 1px solid #e5e7eb;
    border-bottom-left-radius: 0.25rem;
    background: #fff;
    color: #1f2937;
}

.message-bubble--offer {
    max-width: min(92%, 22rem);
    border: 1px solid rgba(232, 86, 58, 0.25);
    background: #fff8f5;
}

.message-meta {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    margin-bottom: 0.25rem;
    font-size: 0.75rem;
    color: #6b7280;
}

.message-meta--own {
    display: none;
}

.message-text {
    margin: 0;
    word-break: break-word;
}

.reply-input {
    height: 42px;
    border-color: #e5e7eb;
    background: #fafafa;
    border-radius: 9999px;
}

.reply-input:focus {
    border-color: #e8563a;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(232, 86, 58, 0.1);
}

.send-btn {
    background: #e8563a;
    color: #fff;
    border-radius: 9999px;
    box-shadow: 0 4px 14px rgba(232, 86, 58, 0.3);
}

.send-btn:hover:not(:disabled) {
    background: #d44a2f;
    transform: translateY(-1px);
}

.send-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.offer-popup-card {
    animation: popup-in 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes popup-in {
    from {
        opacity: 0;
        transform: scale(0.92) translateY(8px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}
</style>
