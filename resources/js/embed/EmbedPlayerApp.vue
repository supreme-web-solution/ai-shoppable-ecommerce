<script setup lang="ts">
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

type ProductVariant = {
    id: number;
    title: string;
    price: string;
    sale_price?: string | null;
    is_default?: boolean;
};

type ProductTag = {
    id: number;
    starts_at_ms?: number | null;
    ends_at_ms?: number | null;
    cta_label?: string | null;
    discount_percent?: string | number | null;
    is_pinned?: boolean;
    product?: {
        id: number;
        title: string;
        price: string;
        sale_price?: string | null;
        image_url?: string | null;
        variants?: ProductVariant[];
    };
};

type VideoMetadata = {
    viewer_sim_enabled?: boolean;
    viewer_sim_min?: number;
    viewer_sim_max?: number;
};

type VideoItem = {
    id: number;
    team_id: number;
    title: string;
    description?: string | null;
    playback_url?: string | null;
    thumbnail_url?: string | null;
    product_tags?: ProductTag[];
    metadata?: VideoMetadata | null;
};

type CommentItem = { id: number; body: string; created_at?: string };
type CartItem = {
    id: number;
    product_id: number;
    quantity: number;
    line_total: string;
    product?: { id: number; title: string };
};
type CartPayload = {
    id: number;
    team_id: number;
    total_amount: string;
    items?: CartItem[];
};
type LiveShowItem = {
    id: number;
    title: string;
    status: string;
    state: string;
    starts_at?: string | null;
    countdown_seconds?: number;
};
type FloatingReaction = { id: number; left: number };

const props = defineProps<{ embedSlug: string }>();

const feed = ref<VideoItem[]>([]);
const feedPage = ref(1);
const hasMoreFeed = ref(true);
const loadingMoreFeed = ref(false);
const currentIndex = ref(0);
const loading = ref(true);
const reactionCount = ref(0);
const viewerCount = ref(0);
const commentText = ref('');
const comments = ref<CommentItem[]>([]);
const currentTimeMs = ref(0);
const errorText = ref('');
const cart = ref<CartPayload | null>(null);
const cartOpen = ref(false);
const checkoutLoading = ref(false);
const checkoutSuccessText = ref('');
const commentPanelOpen = ref(false);
const selectedVariantId = ref<number | null>(null);
const floatingReactions = ref<FloatingReaction[]>([]);
const savedVideoIds = ref<number[]>([]);
const videoElement = ref<HTMLVideoElement | null>(null);
const isMuted = ref(true);
const liveShow = ref<LiveShowItem | null>(null);
const nowTickMs = ref(Date.now());
const activeProductIndex = ref(0);

/* ─── simulated viewer count ─── */
const simulatedViewerCount = ref(0);
let simulationInterval: number | null = null;

const touchStartY = ref<number | null>(null);
const touchEndY = ref<number | null>(null);
const sessionKey = getOrCreateSessionKey();

const currentVideo = computed(() => feed.value[currentIndex.value] ?? null);
const activeTags = computed(() => {
    const tags = currentVideo.value?.product_tags ?? [];
    const at = currentTimeMs.value;

    return tags.filter((tag) => {
        if (tag.is_pinned) {
            return true;
        }

        const s = tag.starts_at_ms ?? 0;
        const e = tag.ends_at_ms ?? Number.MAX_SAFE_INTEGER;

        return at >= s && at <= e;
    });
});
const pinnedTags = computed(() =>
    (currentVideo.value?.product_tags ?? []).filter((t) => t.is_pinned),
);
const popupTag = computed(() => {
    const at = currentTimeMs.value;

    return (
        (currentVideo.value?.product_tags ?? []).find((tag) => {
            if (tag.is_pinned) {
                return false;
            }

            return (
                at >= (tag.starts_at_ms ?? 0) &&
                at <= (tag.ends_at_ms ?? Number.MAX_SAFE_INTEGER)
            );
        }) ?? null
    );
});
const currentTag = computed(
    () =>
        activeTags.value[activeProductIndex.value] ??
        activeTags.value[0] ??
        null,
);
const productVariants = computed(
    () => currentTag.value?.product?.variants ?? [],
);
const isSaved = computed(() =>
    currentVideo.value
        ? savedVideoIds.value.includes(currentVideo.value.id)
        : false,
);
const cartItems = computed(() => cart.value?.items ?? []);
const canGoPrevious = computed(() => currentIndex.value > 0);
const canGoNext = computed(
    () => currentIndex.value < feed.value.length - 1 || hasMoreFeed.value,
);

const displayViewerCount = computed(() => {
    const meta = currentVideo.value?.metadata;

    if (
        meta?.viewer_sim_enabled &&
        meta.viewer_sim_min != null &&
        meta.viewer_sim_max != null
    ) {
        return simulatedViewerCount.value;
    }

    return viewerCount.value;
});

const liveShowBadgeText = computed(() => {
    if (!liveShow.value) {
        return null;
    }

    if (liveShow.value.state === 'live') {
        return 'LIVE';
    }

    if (liveShow.value.state === 'scheduled') {
        const secs = liveShowCountdown.value;

        return `In ${Math.floor(secs / 60)}:${String(secs % 60).padStart(2, '0')}`;
    }

    return null;
});

const liveShowCountdown = computed(() => {
    if (
        !liveShow.value ||
        liveShow.value.state !== 'scheduled' ||
        !liveShow.value.starts_at
    ) {
        return 0;
    }

    const t = Date.parse(liveShow.value.starts_at);

    return isNaN(t)
        ? (liveShow.value.countdown_seconds ?? 0)
        : Math.max(Math.floor((t - nowTickMs.value) / 1000), 0);
});

let echo: Echo<'reverb'> | null = null;
let currentChannel: string | null = null;
let viewerPingInterval: number | null = null;
let clockInterval: number | null = null;

function getOrCreateSessionKey(): string {
    const g = `embed-${Math.random().toString(36).slice(2)}`;

    if (typeof window === 'undefined') {
        return g;
    }

    const e = window.localStorage.getItem('embed_session_key');

    if (e) {
        return e;
    }

    window.localStorage.setItem('embed_session_key', g);

    return g;
}

function startViewerSimulation(min: number, max: number) {
    stopViewerSimulation();
    simulatedViewerCount.value = Math.round(min + Math.random() * (max - min));
    simulationInterval = window.setInterval(
        () => {
            const delta = Math.floor(Math.random() * 9) - 4;
            simulatedViewerCount.value = Math.max(
                min,
                Math.min(max, simulatedViewerCount.value + delta),
            );
        },
        1800 + Math.floor(Math.random() * 1200),
    );
}

function stopViewerSimulation() {
    if (simulationInterval !== null) {
        window.clearInterval(simulationInterval);
        simulationInterval = null;
    }
}

async function fetchJson<T>(url: string, options?: RequestInit): Promise<T> {
    const h = new Headers(options?.headers ?? {});
    h.set('Accept', 'application/json');
    h.set('X-Embed-Slug', props.embedSlug);
    const r = await fetch(url, { ...options, headers: h });

    if (!r.ok) {
        throw new Error(`Request failed (${r.status})`);
    }

    return (await r.json()) as T;
}

function asData<T>(p: T | { data?: T } | null | undefined): T | null {
    if (!p) {
        return null;
    }

    if (typeof p === 'object' && 'data' in (p as object)) {
        return (p as { data?: T }).data ?? null;
    }

    return p as T;
}

async function loadFeed(page = 1, append = false) {
    if (page === 1) {
        loading.value = true;
    } else {
        loadingMoreFeed.value = true;
    }

    errorText.value = '';

    try {
        const payload = await fetchJson<{
            data?: VideoItem[];
            meta?: { current_page: number; last_page: number };
        }>(
            `/api/v1/player/feed?embed_slug=${encodeURIComponent(props.embedSlug)}&per_page=10&page=${page}`,
        );
        const items = payload.data ?? [];
        feed.value = append ? [...feed.value, ...items] : items;
        feedPage.value = payload.meta?.current_page ?? page;
        hasMoreFeed.value =
            (payload.meta?.current_page ?? page) <
            (payload.meta?.last_page ?? page);
    } catch {
        if (!append) {
            errorText.value = 'Could not load feed.';
        }
    } finally {
        loading.value = false;
        loadingMoreFeed.value = false;
    }
}

async function postAnalytics(
    eventName: string,
    payload: Record<string, unknown> = {},
) {
    if (!currentVideo.value) {
        return;
    }

    try {
        await fetchJson('/api/v1/analytics/events', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                team_id: currentVideo.value.team_id,
                video_id: currentVideo.value.id,
                event_name: eventName,
                source: 'embed_player',
                platform: 'web_embed',
                session_key: sessionKey,
                occurred_at: new Date().toISOString(),
                payload,
            }),
        });
    } catch {
        /* non-blocking */
    }
}

async function sendViewerPing(teamId: number, videoId: number) {
    const p = await fetchJson<{ viewer_count?: number }>(
        '/api/v1/player/viewer-ping',
        {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                team_id: teamId,
                video_id: videoId,
                session_key: sessionKey,
            }),
        },
    );
    viewerCount.value = p.viewer_count ?? viewerCount.value;
}

function stopViewerHeartbeat() {
    if (viewerPingInterval !== null) {
        window.clearInterval(viewerPingInterval);
        viewerPingInterval = null;
    }
}

function startViewerHeartbeat(video: VideoItem) {
    stopViewerHeartbeat();
    sendViewerPing(video.team_id, video.id).catch(() => {});
    viewerPingInterval = window.setInterval(
        () => sendViewerPing(video.team_id, video.id).catch(() => {}),
        30000,
    );
}

function spawnFloatingReaction() {
    const r: FloatingReaction = {
        id: Date.now() + Math.floor(Math.random() * 1000),
        left: 20 + Math.floor(Math.random() * 60),
    };
    floatingReactions.value.push(r);
    window.setTimeout(() => {
        floatingReactions.value = floatingReactions.value.filter(
            (x) => x.id !== r.id,
        );
    }, 1400);
}

async function sendReaction() {
    if (!currentVideo.value) {
        return;
    }

    spawnFloatingReaction();

    try {
        const p = await fetchJson<{ count?: number }>(
            '/api/v1/player/reactions',
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    team_id: currentVideo.value.team_id,
                    video_id: currentVideo.value.id,
                    emoji: 'like',
                    session_id: sessionKey,
                }),
            },
        );
        reactionCount.value = p.count ?? reactionCount.value + 1;
        void postAnalytics('reaction', { emoji: 'like' });
    } catch {
        errorText.value = 'Could not send reaction.';
    }
}

async function sendComment() {
    if (!currentVideo.value || !commentText.value.trim()) {
        return;
    }

    try {
        const p = await fetchJson<{ data?: CommentItem } | CommentItem>(
            '/api/v1/player/comments',
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    team_id: currentVideo.value.team_id,
                    video_id: currentVideo.value.id,
                    body: commentText.value.trim(),
                }),
            },
        );
        const c = asData<CommentItem>(p);

        if (c) {
            comments.value.unshift(c);
        }

        commentText.value = '';
        void postAnalytics('comment_submitted');
    } catch {
        errorText.value = 'Could not post comment.';
    }
}

async function loadCart(teamId: number) {
    try {
        const p = await fetchJson<{ data?: CartPayload } | CartPayload>(
            `/api/v1/player/cart?team_id=${teamId}&session_key=${encodeURIComponent(sessionKey)}`,
        );
        cart.value = asData<CartPayload>(p);
    } catch {
        /* keep usable */
    }
}

async function loadLiveShow(teamId: number, videoId: number) {
    try {
        const p = await fetchJson<{ data?: LiveShowItem | null }>(
            `/api/v1/player/live-show?team_id=${teamId}&video_id=${videoId}`,
        );
        liveShow.value = p.data ?? null;
    } catch {
        liveShow.value = null;
    }
}

async function addToCart() {
    if (!currentVideo.value || !currentTag.value?.product) {
        return;
    }

    try {
        const p = await fetchJson<{ data?: CartPayload } | CartPayload>(
            '/api/v1/player/cart/items',
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    team_id: currentVideo.value.team_id,
                    session_key: sessionKey,
                    product_id: currentTag.value.product.id,
                    product_variant_id: selectedVariantId.value,
                    quantity: 1,
                }),
            },
        );
        cart.value = asData<CartPayload>(p);
        cartOpen.value = true;
        void postAnalytics('add_to_cart', {
            product_id: currentTag.value.product.id,
        });
    } catch {
        errorText.value = 'Could not add to cart.';
    }
}

async function addTagToCart(tag: ProductTag) {
    if (!currentVideo.value || !tag.product) {
        return;
    }

    try {
        const p = await fetchJson<{ data?: CartPayload } | CartPayload>(
            '/api/v1/player/cart/items',
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    team_id: currentVideo.value.team_id,
                    session_key: sessionKey,
                    product_id: tag.product.id,
                    quantity: 1,
                }),
            },
        );
        cart.value = asData<CartPayload>(p);
        cartOpen.value = true;
    } catch {
        errorText.value = 'Could not add to cart.';
    }
}

async function shareVideo() {
    if (!currentVideo.value) {
        return;
    }

    const url = `${window.location.origin}/embed/${props.embedSlug}?video=${currentVideo.value.id}`;

    try {
        if (navigator.share) {
            await navigator.share({ title: currentVideo.value.title, url });
        } else {
            await navigator.clipboard.writeText(url);
        }

        void postAnalytics('share', { video_id: currentVideo.value.id });
    } catch {
        /* cancelled */
    }
}

function saveVideo() {
    if (!currentVideo.value) {
        return;
    }

    const id = currentVideo.value.id;
    savedVideoIds.value = savedVideoIds.value.includes(id)
        ? savedVideoIds.value.filter((x) => x !== id)
        : [...savedVideoIds.value, id];
    window.localStorage.setItem(
        'embed_saved_videos',
        JSON.stringify(savedVideoIds.value),
    );
    void postAnalytics('save', { video_id: id });
}

async function removeCartItem(itemId: number) {
    if (!currentVideo.value) {
        return;
    }

    try {
        const p = await fetchJson<{ data?: CartPayload } | CartPayload>(
            `/api/v1/player/cart/items/${itemId}?team_id=${currentVideo.value.team_id}&session_key=${encodeURIComponent(sessionKey)}`,
            { method: 'DELETE' },
        );
        cart.value = asData<CartPayload>(p);
    } catch {
        errorText.value = 'Could not remove item.';
    }
}

async function checkoutCart() {
    if (!currentVideo.value || !cart.value || checkoutLoading.value) {
        return;
    }

    checkoutLoading.value = true;
    checkoutSuccessText.value = '';

    try {
        const p = await fetchJson<
            | { mode?: string; checkout_url?: string; provider?: string }
            | { data?: { order_number?: string }; order_number?: string }
        >('/api/v1/player/checkout', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                team_id: currentVideo.value.team_id,
                cart_id: cart.value.id,
                checkout_mode: 'hybrid',
            }),
        });

        if (p.checkout_url) {
            window.location.href = p.checkout_url;

            return;
        }

        const order = asData<{ order_number?: string }>(p);

        if (order?.order_number) {
            checkoutSuccessText.value = `Order ${order.order_number} confirmed. Thank you!`;
            cartOpen.value = false;
            await loadCart(currentVideo.value.team_id);

            return;
        }

        errorText.value = 'Could not complete checkout.';
    } catch {
        errorText.value = 'Could not start checkout.';
    } finally {
        checkoutLoading.value = false;
    }
}

function applyAudioState() {
    if (!videoElement.value) {
        return;
    }

    videoElement.value.muted = isMuted.value;
    videoElement.value.volume = isMuted.value ? 0 : 1;
}

function playCurrentVideo() {
    applyAudioState();
    void videoElement.value?.play().catch(() => {});
}

function toggleAudio() {
    isMuted.value = !isMuted.value;
    playCurrentVideo();
}

function nextVideo() {
    if (currentIndex.value < feed.value.length - 1) {
        currentIndex.value += 1;

        return;
    }

    if (hasMoreFeed.value) {
        void loadFeed(feedPage.value + 1, true).then(() => {
            if (currentIndex.value < feed.value.length - 1) {
                currentIndex.value += 1;
            }
        });
    }
}

function previousVideo() {
    if (canGoPrevious.value) {
        currentIndex.value -= 1;
    }
}

function onTouchStart(e: TouchEvent) {
    touchStartY.value = e.changedTouches[0]?.clientY ?? null;
}
function onTouchEnd(e: TouchEvent) {
    touchEndY.value = e.changedTouches[0]?.clientY ?? null;

    if (touchStartY.value === null || touchEndY.value === null) {
        return;
    }

    const d = touchStartY.value - touchEndY.value;

    if (d > 40) {
        nextVideo();
    } else if (d < -40) {
        previousVideo();
    }
}

function initializeRealtime() {
    if (echo || !import.meta.env.VITE_REVERB_APP_KEY) {
        return;
    }

    const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http';
    const host = import.meta.env.VITE_REVERB_HOST || window.location.hostname;
    const port = Number(import.meta.env.VITE_REVERB_PORT || 8080);
    const client = new Pusher(import.meta.env.VITE_REVERB_APP_KEY, {
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        cluster: 'mt1',
    });
    echo = new Echo({ broadcaster: 'reverb', client });
}

function subscribeToVideo(videoId: number) {
    if (!echo) {
        return;
    }

    if (currentChannel) {
        echo.leave(currentChannel);
    }

    currentChannel = `video.${videoId}`;
    echo.channel(currentChannel)
        .listen('.reaction.updated', (e: { count: number }) => {
            reactionCount.value = e.count;
        })
        .listen('.viewer.count.updated', (e: { viewer_count: number }) => {
            viewerCount.value = e.viewer_count;
        })
        .listen('.comment.created', (e: { comment?: CommentItem }) => {
            if (e.comment) {
                comments.value.unshift(e.comment);
            }
        });
}

async function resetVideoPlayback() {
    await nextTick();
    currentTimeMs.value = 0;

    if (videoElement.value) {
        videoElement.value.currentTime = 0;
        applyAudioState();
        void videoElement.value.play().catch(() => {});
    }
}

watch(currentVideo, async (video) => {
    if (!video) {
        return;
    }

    reactionCount.value = 0;
    viewerCount.value = 0;
    comments.value = [];
    liveShow.value = null;
    activeProductIndex.value = 0;
    selectedVariantId.value =
        video.product_tags?.[0]?.product?.variants?.find((v) => v.is_default)
            ?.id ??
        video.product_tags?.[0]?.product?.variants?.[0]?.id ??
        null;

    stopViewerSimulation();
    const meta = video.metadata;

    if (
        meta?.viewer_sim_enabled &&
        meta.viewer_sim_min != null &&
        meta.viewer_sim_max != null
    ) {
        startViewerSimulation(meta.viewer_sim_min, meta.viewer_sim_max);
    }

    if (currentIndex.value >= feed.value.length - 2 && hasMoreFeed.value) {
        void loadFeed(feedPage.value + 1, true);
    }

    initializeRealtime();
    subscribeToVideo(video.id);
    startViewerHeartbeat(video);
    await loadCart(video.team_id);
    await loadLiveShow(video.team_id, video.id);
    await resetVideoPlayback();
    void postAnalytics('video_view');
});

onMounted(async () => {
    const saved = window.localStorage.getItem('embed_saved_videos');

    if (saved) {
        try {
            savedVideoIds.value = JSON.parse(saved) as number[];
        } catch {
            savedVideoIds.value = [];
        }
    }

    clockInterval = window.setInterval(() => {
        nowTickMs.value = Date.now();
    }, 1000);
    await loadFeed();

    if (currentVideo.value) {
        const meta = currentVideo.value.metadata;

        if (
            meta?.viewer_sim_enabled &&
            meta.viewer_sim_min != null &&
            meta.viewer_sim_max != null
        ) {
            startViewerSimulation(meta.viewer_sim_min, meta.viewer_sim_max);
        }

        initializeRealtime();
        subscribeToVideo(currentVideo.value.id);
        startViewerHeartbeat(currentVideo.value);
        await loadCart(currentVideo.value.team_id);
        await loadLiveShow(currentVideo.value.team_id, currentVideo.value.id);
        void postAnalytics('video_view');
    }
});

onBeforeUnmount(() => {
    stopViewerHeartbeat();
    stopViewerSimulation();

    if (echo && currentChannel) {
        echo.leave(currentChannel);
    }

    if (echo) {
        echo.disconnect();
    }

    if (clockInterval !== null) {
        window.clearInterval(clockInterval);
        clockInterval = null;
    }
});
</script>

<template>
    <div class="player-root">
        <!-- ═══ LOADING ═══ -->
        <div v-if="loading" class="player-center">
            <div class="loader-ring"></div>
            <p class="mt-3 text-[13px] text-white/50">Loading feed…</p>
        </div>

        <!-- ═══ ERROR ═══ -->
        <div
            v-else-if="errorText && !currentVideo"
            class="player-center gap-3 px-6 text-center"
        >
            <svg
                width="32"
                height="32"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                class="text-red-400"
            >
                <circle cx="12" cy="12" r="10" />
                <line x1="12" y1="8" x2="12" y2="12" />
                <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
            <p class="text-sm text-red-400">{{ errorText }}</p>
        </div>

        <!-- ═══ EMPTY ═══ -->
        <div
            v-else-if="!currentVideo"
            class="player-center gap-3 px-6 text-center"
        >
            <svg
                width="40"
                height="40"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                class="text-white/25"
            >
                <rect x="2" y="2" width="20" height="20" rx="4" />
                <path d="M10 8l6 4-6 4V8z" fill="currentColor" stroke="none" />
            </svg>
            <p class="text-sm text-white/40">No videos published yet</p>
        </div>

        <!-- ═══ PLAYER ═══ -->
        <template v-else>
            <!-- Video -->
            <div
                class="video-layer"
                @touchstart.passive="onTouchStart"
                @touchend.passive="onTouchEnd"
            >
                <div
                    class="video-ambient"
                    :style="{
                        backgroundImage: currentVideo.thumbnail_url
                            ? `url('${currentVideo.thumbnail_url}')`
                            : undefined,
                    }"
                />
                <video
                    ref="videoElement"
                    class="player-video absolute inset-0 h-full w-full object-contain"
                    :src="currentVideo.playback_url || ''"
                    :poster="currentVideo.thumbnail_url || ''"
                    :muted="isMuted"
                    playsinline
                    autoplay
                    loop
                    preload="auto"
                    @loadeddata="playCurrentVideo"
                    @timeupdate="
                        currentTimeMs = Math.floor(
                            ($event.target as HTMLVideoElement).currentTime *
                                1000,
                        )
                    "
                    @click="
                        videoElement?.paused
                            ? videoElement.play()
                            : videoElement?.pause()
                    "
                />

                <!-- Gradient overlays -->
                <div class="overlay-top"></div>
                <div class="overlay-bottom"></div>

                <!-- ── TOP HUD ── -->
                <div class="hud-top">
                    <!-- Live badge -->
                    <div v-if="liveShowBadgeText" class="live-badge">
                        <span class="live-dot"></span>
                        {{ liveShowBadgeText }}
                    </div>
                    <div v-else class="flex-1" />

                    <!-- Viewer count -->
                    <div class="viewer-chip">
                        <svg
                            width="12"
                            height="12"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"
                            />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                        <span>{{ displayViewerCount.toLocaleString() }}</span>
                        <span class="opacity-50">·</span>
                        <span
                            >{{ currentIndex + 1 }}/{{ feed.length
                            }}{{ hasMoreFeed ? '+' : '' }}</span
                        >
                    </div>
                </div>

                <!-- ── FLOATING HEARTS ── -->
                <div
                    v-for="r in floatingReactions"
                    :key="r.id"
                    class="floating-heart"
                    :style="{ left: `${r.left}%` }"
                >
                    <svg
                        width="22"
                        height="22"
                        viewBox="0 0 24 24"
                        fill="#ff4d6d"
                        stroke="#ff4d6d"
                        stroke-width="1.5"
                    >
                        <path
                            d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"
                        />
                    </svg>
                </div>

                <!-- ── RIGHT ACTION RAIL ── -->
                <div class="action-rail">
                    <!-- Sound -->
                    <button type="button" class="rail-btn" @click="toggleAudio">
                        <span
                            class="rail-icon"
                            :class="!isMuted ? 'saved-active' : ''"
                        >
                            <svg
                                v-if="isMuted"
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <polygon
                                    points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"
                                />
                                <line x1="23" y1="9" x2="17" y2="15" />
                                <line x1="17" y1="9" x2="23" y2="15" />
                            </svg>
                            <svg
                                v-else
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <polygon
                                    points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"
                                />
                                <path d="M15.54 8.46a5 5 0 0 1 0 7.07" />
                                <path d="M19.07 4.93a10 10 0 0 1 0 14.14" />
                            </svg>
                        </span>
                        <span class="rail-label">{{
                            isMuted ? 'Muted' : 'Sound'
                        }}</span>
                    </button>

                    <!-- Heart / react -->
                    <button
                        type="button"
                        class="rail-btn"
                        @click="sendReaction"
                    >
                        <span class="rail-icon">
                            <svg
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path
                                    d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"
                                />
                            </svg>
                        </span>
                        <span class="rail-label">{{
                            reactionCount || ''
                        }}</span>
                    </button>

                    <!-- Comment -->
                    <button
                        type="button"
                        class="rail-btn"
                        @click="commentPanelOpen = !commentPanelOpen"
                    >
                        <span class="rail-icon">
                            <svg
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path
                                    d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"
                                />
                            </svg>
                        </span>
                        <span class="rail-label">{{
                            comments.length || ''
                        }}</span>
                    </button>

                    <!-- Share -->
                    <button type="button" class="rail-btn" @click="shareVideo">
                        <span class="rail-icon">
                            <svg
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <circle cx="18" cy="5" r="3" />
                                <circle cx="6" cy="12" r="3" />
                                <circle cx="18" cy="19" r="3" />
                                <line
                                    x1="8.59"
                                    y1="13.51"
                                    x2="15.42"
                                    y2="17.49"
                                />
                                <line
                                    x1="15.41"
                                    y1="6.51"
                                    x2="8.59"
                                    y2="10.49"
                                />
                            </svg>
                        </span>
                        <span class="rail-label">Share</span>
                    </button>

                    <!-- Save / Bookmark -->
                    <button type="button" class="rail-btn" @click="saveVideo">
                        <span
                            class="rail-icon"
                            :class="isSaved ? 'saved-active' : ''"
                        >
                            <svg
                                width="22"
                                height="22"
                                viewBox="0 0 24 24"
                                :fill="isSaved ? 'currentColor' : 'none'"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path
                                    d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"
                                />
                            </svg>
                        </span>
                        <span class="rail-label">{{
                            isSaved ? 'Saved' : 'Save'
                        }}</span>
                    </button>

                    <!-- Cart -->
                    <button
                        type="button"
                        class="rail-btn"
                        @click="cartOpen = !cartOpen"
                    >
                        <span class="rail-icon">
                            <svg
                                width="24"
                                height="24"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <circle cx="9" cy="21" r="1" />
                                <circle cx="20" cy="21" r="1" />
                                <path
                                    d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"
                                />
                            </svg>
                        </span>
                        <span v-if="cartItems.length" class="cart-badge">{{
                            cartItems.length
                        }}</span>
                        <span class="rail-label">Cart</span>
                    </button>

                    <!-- Navigation -->
                    <div class="rail-nav">
                        <button
                            type="button"
                            class="nav-btn"
                            :disabled="!canGoPrevious"
                            @click="previousVideo"
                        >
                            <svg
                                width="18"
                                height="18"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <polyline points="18 15 12 9 6 15" />
                            </svg>
                        </button>
                        <button
                            type="button"
                            class="nav-btn"
                            :disabled="!canGoNext"
                            @click="nextVideo"
                        >
                            <svg
                                width="18"
                                height="18"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <polyline points="6 9 12 15 18 9" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- ── POPUP PRODUCT (timed) ── -->
                <Transition name="popup">
                    <div v-if="popupTag?.product" class="popup-card">
                        <p class="popup-label">Limited offer</p>
                        <p class="popup-title">{{ popupTag.product.title }}</p>
                        <div class="popup-price-row">
                            <span class="popup-price">{{
                                popupTag.product.sale_price ||
                                popupTag.product.price
                            }}</span>
                            <span
                                v-if="popupTag.discount_percent"
                                class="popup-discount"
                                >-{{ popupTag.discount_percent }}%</span
                            >
                        </div>
                        <button
                            type="button"
                            class="popup-btn"
                            @click="addTagToCart(popupTag)"
                        >
                            {{ popupTag.cta_label || 'Buy now' }}
                        </button>
                    </div>
                </Transition>

                <!-- ── BOTTOM INFO + PRODUCT CAROUSEL ── -->
                <div class="bottom-area">
                    <!-- Title & description -->
                    <div class="video-meta">
                        <h2 class="video-title">{{ currentVideo.title }}</h2>
                        <p v-if="currentVideo.description" class="video-desc">
                            {{ currentVideo.description }}
                        </p>
                    </div>

                    <!-- Product carousel -->
                    <div
                        v-if="pinnedTags.length > 0"
                        class="product-carousel-wrap"
                    >
                        <!-- Scroll track -->
                        <div class="product-carousel">
                            <button
                                v-for="(tag, idx) in pinnedTags"
                                :key="tag.id"
                                type="button"
                                :class="[
                                    'product-card',
                                    idx === activeProductIndex
                                        ? 'product-card--active'
                                        : '',
                                ]"
                                @click="activeProductIndex = idx"
                            >
                                <!-- Product image -->
                                <div class="product-img-wrap">
                                    <img
                                        v-if="tag.product?.image_url"
                                        :src="tag.product.image_url"
                                        :alt="tag.product?.title"
                                        class="product-img"
                                    />
                                    <div v-else class="product-img-placeholder">
                                        <svg
                                            width="18"
                                            height="18"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.5"
                                            class="text-white/30"
                                        >
                                            <rect
                                                x="3"
                                                y="3"
                                                width="18"
                                                height="18"
                                                rx="2"
                                            />
                                            <circle cx="8.5" cy="8.5" r="1.5" />
                                            <polyline
                                                points="21 15 16 10 5 21"
                                            />
                                        </svg>
                                    </div>
                                </div>
                                <!-- Product info -->
                                <div class="product-info">
                                    <p class="product-name">
                                        {{ tag.product?.title }}
                                    </p>
                                    <div class="product-price-row">
                                        <span
                                            v-if="tag.product?.sale_price"
                                            class="product-sale-price"
                                            >{{ tag.product.sale_price }}</span
                                        >
                                        <span
                                            :class="
                                                tag.product?.sale_price
                                                    ? 'product-orig-price'
                                                    : 'product-price'
                                            "
                                        >
                                            {{ tag.product?.price }}
                                        </span>
                                        <span
                                            v-if="tag.discount_percent"
                                            class="product-badge"
                                            >-{{ tag.discount_percent }}%</span
                                        >
                                    </div>
                                </div>
                                <!-- Cart icon -->
                                <button
                                    type="button"
                                    class="product-cart-btn"
                                    @click.stop="addTagToCart(tag)"
                                >
                                    <svg
                                        width="14"
                                        height="14"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2.5"
                                    >
                                        <circle cx="9" cy="21" r="1" />
                                        <circle cx="20" cy="21" r="1" />
                                        <path
                                            d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"
                                        />
                                    </svg>
                                </button>
                            </button>
                        </div>

                        <!-- Dot indicators (when >1) -->
                        <div v-if="pinnedTags.length > 1" class="carousel-dots">
                            <span
                                v-for="(_, i) in pinnedTags"
                                :key="i"
                                :class="[
                                    'dot',
                                    i === activeProductIndex
                                        ? 'dot--active'
                                        : '',
                                ]"
                                @click="activeProductIndex = i"
                            />
                        </div>

                        <!-- CTA row for active product -->
                        <div v-if="currentTag?.product" class="cta-row">
                            <select
                                v-if="productVariants.length > 0"
                                v-model="selectedVariantId"
                                class="variant-select"
                            >
                                <option
                                    v-for="v in productVariants"
                                    :key="v.id"
                                    :value="v.id"
                                >
                                    {{ v.title }} —
                                    {{ v.sale_price || v.price }}
                                </option>
                            </select>
                            <button
                                type="button"
                                class="btn-add-cart"
                                @click="addToCart"
                            >
                                <svg
                                    width="14"
                                    height="14"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.5"
                                >
                                    <circle cx="9" cy="21" r="1" />
                                    <circle cx="20" cy="21" r="1" />
                                    <path
                                        d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"
                                    />
                                </svg>
                                {{ currentTag.cta_label || 'Add to cart' }}
                            </button>
                            <button
                                type="button"
                                class="btn-buy-now"
                                @click="checkoutCart"
                            >
                                Buy now
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ COMMENT PANEL ═══ -->
            <Transition name="slide-up">
                <div v-if="commentPanelOpen" class="panel">
                    <div class="panel-handle" />
                    <div class="panel-header">
                        <p class="panel-title">
                            <svg
                                width="15"
                                height="15"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"
                                />
                            </svg>
                            Comments
                        </p>
                        <button
                            type="button"
                            class="panel-close"
                            @click="commentPanelOpen = false"
                        >
                            <svg
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <line x1="18" y1="6" x2="6" y2="18" />
                                <line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </div>
                    <div class="comment-list">
                        <p v-if="comments.length === 0" class="comment-empty">
                            No comments yet. Be first!
                        </p>
                        <div
                            v-for="c in comments.slice(0, 12)"
                            :key="c.id"
                            class="comment-item"
                        >
                            <div class="comment-avatar">
                                {{ c.body[0]?.toUpperCase() }}
                            </div>
                            <p class="comment-body">{{ c.body }}</p>
                        </div>
                    </div>
                    <div class="comment-input-row">
                        <input
                            v-model="commentText"
                            class="comment-input"
                            placeholder="Write a comment…"
                            @keyup.enter="sendComment"
                        />
                        <button
                            type="button"
                            class="comment-send"
                            @click="sendComment"
                        >
                            <svg
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <line x1="22" y1="2" x2="11" y2="13" />
                                <polygon points="22 2 15 22 11 13 2 9 22 2" />
                            </svg>
                        </button>
                    </div>
                </div>
            </Transition>

            <!-- ═══ CART PANEL ═══ -->
            <Transition name="slide-up">
                <div v-if="cartOpen" class="panel">
                    <div class="panel-handle" />
                    <div class="panel-header">
                        <p class="panel-title">
                            <svg
                                width="15"
                                height="15"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <circle cx="9" cy="21" r="1" />
                                <circle cx="20" cy="21" r="1" />
                                <path
                                    d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"
                                />
                            </svg>
                            Your Cart
                        </p>
                        <button
                            type="button"
                            class="panel-close"
                            @click="cartOpen = false"
                        >
                            <svg
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <line x1="18" y1="6" x2="6" y2="18" />
                                <line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </div>

                    <p v-if="checkoutSuccessText" class="checkout-success">
                        {{ checkoutSuccessText }}
                    </p>

                    <p v-if="cartItems.length === 0" class="comment-empty">
                        Your cart is empty.
                    </p>

                    <div
                        v-for="item in cartItems"
                        :key="item.id"
                        class="cart-item"
                    >
                        <div class="cart-item-info">
                            <p class="cart-item-name">
                                {{ item.product?.title || 'Product' }}
                            </p>
                            <p class="cart-item-sub">
                                Qty {{ item.quantity }} · {{ item.line_total }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="cart-remove"
                            @click="removeCartItem(item.id)"
                        >
                            <svg
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <polyline points="3 6 5 6 21 6" />
                                <path d="M19 6l-1 14H6L5 6" />
                                <path d="M10 11v6" />
                                <path d="M14 11v6" />
                                <path d="M9 6V4h6v2" />
                            </svg>
                        </button>
                    </div>

                    <div class="cart-footer">
                        <p class="cart-total">
                            Total
                            <span>{{ cart?.total_amount || '0.00' }}</span>
                        </p>
                        <button
                            type="button"
                            class="btn-checkout"
                            :disabled="
                                checkoutLoading || cartItems.length === 0
                            "
                            @click="checkoutCart"
                        >
                            <svg
                                v-if="checkoutLoading"
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                class="spin"
                            >
                                <path d="M21 12a9 9 0 1 1-6.219-8.56" />
                            </svg>
                            {{ checkoutLoading ? 'Processing…' : 'Checkout' }}
                        </button>
                    </div>
                </div>
            </Transition>

            <!-- loading more -->
            <div v-if="loadingMoreFeed" class="load-more-bar">
                <div class="loader-ring loader-ring--sm" />
            </div>
        </template>
    </div>
</template>

<style scoped>
:global(body) {
    background:
        radial-gradient(
            circle at 12% 10%,
            rgba(232, 86, 58, 0.16),
            transparent 26%
        ),
        radial-gradient(
            circle at 88% 0%,
            rgba(255, 140, 66, 0.14),
            transparent 30%
        ),
        #f2efea;
}

:global(#embed-player-app) {
    min-height: 100dvh;
    display: flex;
    align-items: stretch;
    justify-content: center;
}

/* ── Root ── */
.player-root {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 460px;
    height: 100dvh;
    margin: 0 auto;
    background:
        radial-gradient(
            circle at 20% 0%,
            rgba(232, 86, 58, 0.28),
            transparent 28%
        ),
        radial-gradient(
            circle at 88% 10%,
            rgba(255, 140, 66, 0.18),
            transparent 32%
        ),
        linear-gradient(180deg, #16110f 0%, #050505 45%, #090807 100%);
    color: #fff;
    overflow: hidden;
    position: relative;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    box-shadow: 0 22px 80px rgba(0, 0, 0, 0.45);
}

@media (min-width: 700px) {
    :global(#embed-player-app) {
        align-items: center;
        padding: 18px;
    }

    .player-root {
        height: min(900px, calc(100dvh - 36px));
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 34px;
    }
}

.player-center {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

/* ── Video layer ── */
.video-layer {
    position: relative;
    flex: 1;
    overflow: hidden;
    isolation: isolate;
    background: #050505;
}
.video-ambient {
    position: absolute;
    inset: -26px;
    z-index: 0;
    background-position: center;
    background-size: cover;
    filter: blur(28px) saturate(1.2);
    opacity: 0.46;
    transform: scale(1.08);
}
.video-ambient::after {
    position: absolute;
    inset: 0;
    content: '';
    background:
        radial-gradient(
            circle at 50% 15%,
            rgba(232, 86, 58, 0.2),
            transparent 34%
        ),
        linear-gradient(180deg, rgba(0, 0, 0, 0.22), rgba(0, 0, 0, 0.72));
}
.player-video {
    z-index: 1;
}
.overlay-top {
    position: absolute;
    inset: 0;
    bottom: 50%;
    background: linear-gradient(
        to bottom,
        rgba(0, 0, 0, 0.68) 0%,
        rgba(0, 0, 0, 0.22) 48%,
        transparent 100%
    );
    pointer-events: none;
    z-index: 2;
}
.overlay-bottom {
    position: absolute;
    inset: 0;
    top: 40%;
    background: linear-gradient(
        to top,
        rgba(0, 0, 0, 0.92) 0%,
        rgba(0, 0, 0, 0.5) 48%,
        transparent 100%
    );
    pointer-events: none;
    z-index: 2;
}

/* ── HUD top ── */
.hud-top {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 14px 0;
}
.live-badge {
    display: flex;
    align-items: center;
    gap: 5px;
    background: linear-gradient(135deg, #e8563a, #ff4d42);
    box-shadow: 0 8px 24px rgba(232, 86, 58, 0.34);
    backdrop-filter: blur(10px);
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.live-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #fff;
    animation: pulse 1.2s infinite;
}
@keyframes pulse {
    0%,
    100% {
        opacity: 1;
        transform: scale(1);
    }
    50% {
        opacity: 0.4;
        transform: scale(0.7);
    }
}
.viewer-chip {
    display: flex;
    align-items: center;
    gap: 5px;
    background: rgba(255, 255, 255, 0.13);
    backdrop-filter: blur(14px);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 999px;
    padding: 5px 10px;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.9);
}

/* ── Action rail ── */
.action-rail {
    position: absolute;
    right: 12px;
    bottom: 182px;
    z-index: 10;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}
.rail-btn {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 16px;
    padding: 9px 8px 6px;
    cursor: pointer;
    transition:
        transform 0.15s,
        background 0.15s;
    min-width: 46px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}
.rail-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-1px) scale(1.04);
}
.rail-btn:active {
    transform: scale(0.96);
}
.rail-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    filter: drop-shadow(0 1px 5px rgba(0, 0, 0, 0.35));
}
.rail-label {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.8);
    white-space: nowrap;
}
.saved-active {
    color: #ffb35c;
}
.cart-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #e8563a;
    color: #fff;
    border-radius: 999px;
    font-size: 9px;
    font-weight: 700;
    padding: 1px 5px;
    line-height: 1.4;
}
.rail-nav {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 4px;
}
.nav-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.13);
    border: 1px solid rgba(255, 255, 255, 0.18);
    cursor: pointer;
    transition: background 0.15s;
    color: #fff;
}
.nav-btn:disabled {
    opacity: 0.25;
    cursor: default;
}
.nav-btn:not(:disabled):hover {
    background: rgba(232, 86, 58, 0.72);
}

/* ── Floating hearts ── */
.floating-heart {
    position: absolute;
    bottom: 160px;
    pointer-events: none;
    z-index: 20;
    animation: float-up 1.4s ease-out forwards;
}
@keyframes float-up {
    0% {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
    100% {
        opacity: 0;
        transform: translateY(-130px) scale(1.3);
    }
}

/* ── Popup timed product ── */
.popup-card {
    position: absolute;
    left: 50%;
    top: 35%;
    transform: translateX(-50%);
    width: 82%;
    z-index: 15;
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid rgba(255, 255, 255, 0.45);
    border-radius: 22px;
    padding: 16px;
    backdrop-filter: blur(18px);
    box-shadow: 0 18px 48px rgba(0, 0, 0, 0.42);
    color: #16110f;
}
.popup-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.09em;
    color: #e8563a;
    margin-bottom: 4px;
    font-weight: 800;
}
.popup-title {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 6px;
}
.popup-price-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}
.popup-price {
    font-size: 14px;
    color: rgba(17, 24, 39, 0.78);
}
.popup-discount {
    font-size: 11px;
    background: rgba(232, 86, 58, 0.1);
    color: #e8563a;
    border-radius: 999px;
    padding: 2px 7px;
    font-weight: 800;
}
.popup-btn {
    width: 100%;
    background: linear-gradient(135deg, #e8563a, #ff8c42);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 9px 0;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    transition: opacity 0.15s;
}
.popup-btn:hover {
    opacity: 0.92;
}
.popup-enter-active,
.popup-leave-active {
    transition:
        opacity 0.3s,
        transform 0.3s;
}
.popup-enter-from,
.popup-leave-to {
    opacity: 0;
    transform: translateX(-50%) scale(0.92);
}

/* ── Bottom area ── */
.bottom-area {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 8;
    padding: 0 12px 12px;
}
.video-meta {
    margin-bottom: 10px;
    padding-right: 62px;
}
.video-title {
    font-size: 15px;
    font-weight: 800;
    line-height: 1.3;
    margin-bottom: 3px;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.7);
    letter-spacing: -0.01em;
}
.video-desc {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
    line-height: 1.4;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

/* ── Product carousel ── */
.product-carousel-wrap {
    background: rgba(255, 255, 255, 0.13);
    backdrop-filter: blur(18px);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 22px;
    padding: 10px 10px 9px;
    box-shadow: 0 14px 40px rgba(0, 0, 0, 0.28);
}
.product-carousel {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 2px;
}
.product-carousel::-webkit-scrollbar {
    display: none;
}
.product-card {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 200px;
    scroll-snap-align: start;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 16px;
    padding: 8px;
    cursor: pointer;
    transition:
        background 0.15s,
        border-color 0.15s;
    text-align: left;
    flex-shrink: 0;
}
.product-card--active {
    background: rgba(255, 255, 255, 0.22);
    border-color: rgba(232, 86, 58, 0.68);
    box-shadow: inset 0 0 0 1px rgba(232, 86, 58, 0.22);
}
.product-card:hover {
    background: rgba(255, 255, 255, 0.18);
}
.product-img-wrap {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    overflow: hidden;
    flex-shrink: 0;
    background: rgba(255, 255, 255, 0.1);
}
.product-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.product-img-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.product-info {
    flex: 1;
    min-width: 0;
}
.product-name {
    font-size: 11px;
    font-weight: 600;
    line-height: 1.3;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    margin-bottom: 2px;
}
.product-price-row {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}
.product-price {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.9);
}
.product-sale-price {
    font-size: 11px;
    font-weight: 800;
    color: #ffb35c;
}
.product-orig-price {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.4);
    text-decoration: line-through;
}
.product-badge {
    font-size: 9px;
    background: rgba(232, 86, 58, 0.2);
    color: #ffd2c8;
    border-radius: 999px;
    padding: 1px 5px;
    font-weight: 800;
}
.product-cart-btn {
    width: 28px;
    height: 28px;
    border-radius: 10px;
    background: #e8563a;
    border: 1px solid rgba(255, 255, 255, 0.18);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    cursor: pointer;
    transition: background 0.15s;
    color: #fff;
}
.product-cart-btn:hover {
    background: #ff6b4c;
}

/* Dots */
.carousel-dots {
    display: flex;
    justify-content: center;
    gap: 5px;
    margin-top: 7px;
}
.dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    cursor: pointer;
    transition:
        background 0.2s,
        transform 0.2s;
}
.dot--active {
    background: #ff8c42;
    transform: scale(1.35);
}

/* CTA row */
.cta-row {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 8px;
}
.variant-select {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.18);
    color: #fff;
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 11px;
    flex: 1;
    outline: none;
}
.btn-add-cart {
    display: flex;
    align-items: center;
    gap: 5px;
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 999px;
    color: #fff;
    padding: 7px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s;
}
.btn-add-cart:hover {
    background: rgba(255, 255, 255, 0.24);
}
.btn-buy-now {
    background: linear-gradient(135deg, #e8563a, #ff8c42);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    white-space: nowrap;
    transition: opacity 0.15s;
}
.btn-buy-now:hover {
    opacity: 0.92;
}

/* ── Panels ── */
.panel {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 30;
    background: rgba(255, 255, 255, 0.96);
    border-top: 1px solid rgba(255, 255, 255, 0.5);
    border-radius: 24px 24px 0 0;
    backdrop-filter: blur(24px);
    padding: 0 14px 20px;
    max-height: 55vh;
    overflow-y: auto;
    color: #111827;
    box-shadow: 0 -18px 52px rgba(0, 0, 0, 0.36);
}
.panel-handle {
    width: 42px;
    height: 4px;
    border-radius: 2px;
    background: rgba(17, 24, 39, 0.18);
    margin: 10px auto 14px;
}
.panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.panel-title {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 800;
    color: #111827;
}
.panel-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 10px;
    background: rgba(17, 24, 39, 0.06);
    cursor: pointer;
    color: rgba(17, 24, 39, 0.64);
    border: none;
}
.panel-close:hover {
    background: rgba(232, 86, 58, 0.1);
    color: #e8563a;
}
.slide-up-enter-active,
.slide-up-leave-active {
    transition:
        transform 0.3s cubic-bezier(0.32, 0.72, 0, 1),
        opacity 0.25s;
}
.slide-up-enter-from,
.slide-up-leave-to {
    transform: translateY(100%);
    opacity: 0;
}

/* Comments */
.comment-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 12px;
    min-height: 40px;
    max-height: 30vh;
    overflow-y: auto;
}
.comment-empty {
    font-size: 12px;
    color: rgba(17, 24, 39, 0.45);
    text-align: center;
    padding: 16px 0;
}
.checkout-success {
    margin: 0 0 10px;
    padding: 10px 12px;
    border-radius: 8px;
    background: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.24);
    color: #047857;
    font-size: 12px;
    text-align: center;
}
.comment-item {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}
.comment-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(232, 86, 58, 0.12);
    color: #e8563a;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 800;
    flex-shrink: 0;
}
.comment-body {
    font-size: 12px;
    color: rgba(17, 24, 39, 0.82);
    line-height: 1.4;
    padding-top: 4px;
}
.comment-input-row {
    display: flex;
    gap: 8px;
    padding-top: 10px;
    border-top: 1px solid rgba(17, 24, 39, 0.08);
}
.comment-input {
    flex: 1;
    background: #f8fafc;
    border: 1px solid rgba(17, 24, 39, 0.1);
    border-radius: 999px;
    padding: 10px 14px;
    font-size: 13px;
    color: #111827;
    outline: none;
}
.comment-input::placeholder {
    color: rgba(17, 24, 39, 0.38);
}
.comment-input:focus {
    border-color: rgba(232, 86, 58, 0.55);
    box-shadow: 0 0 0 3px rgba(232, 86, 58, 0.1);
}
.comment-send {
    width: 40px;
    height: 40px;
    border-radius: 999px;
    background: #e8563a;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    flex-shrink: 0;
    box-shadow: 0 8px 22px rgba(232, 86, 58, 0.25);
}
.comment-send:hover {
    background: #d94c31;
}

/* Cart */
.cart-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid rgba(17, 24, 39, 0.08);
}
.cart-item-info {
}
.cart-item-name {
    font-size: 13px;
    font-weight: 500;
}
.cart-item-sub {
    font-size: 11px;
    color: rgba(17, 24, 39, 0.5);
    margin-top: 2px;
}
.cart-remove {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.2);
    color: #f87171;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}
.cart-remove:hover {
    background: rgba(239, 68, 68, 0.3);
}
.cart-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 12px;
}
.cart-total {
    font-size: 13px;
    color: rgba(17, 24, 39, 0.64);
}
.cart-total span {
    font-weight: 800;
    color: #111827;
}
.btn-checkout {
    display: flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #e8563a, #ff8c42);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 9px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.15s;
}
.btn-checkout:disabled {
    opacity: 0.5;
    cursor: default;
}
.btn-checkout:not(:disabled):hover {
    opacity: 0.9;
}

/* Loading */
.load-more-bar {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 8px;
    background: rgba(0, 0, 0, 0.6);
}
.loader-ring {
    width: 32px;
    height: 32px;
    border: 3px solid rgba(255, 255, 255, 0.15);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
.loader-ring--sm {
    width: 18px;
    height: 18px;
    border-width: 2px;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
.spin {
    animation: spin 0.8s linear infinite;
}
</style>
