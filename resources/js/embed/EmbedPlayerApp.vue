<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import ChatMessageBody from '@/components/chat/ChatMessageBody.vue';
import { embedApiUrl } from '@/embed/config';
import { createEmbedEcho } from '@/embed/reverb';
import EmbedActionRail from '@/embed/components/EmbedActionRail.vue';
import EmbedProductCarousel from '@/embed/components/EmbedProductCarousel.vue';
import TimedTagOverlay from '@/embed/TimedTagOverlay.vue';
import {
    isTagActiveAt,
    overlaySlotForAnchor,
    tagPosition,
    type OverlaySlot,
} from '@/lib/tagOverlay';
import type Echo from 'laravel-echo';

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
    position?: { x?: number; y?: number; anchor?: string } | null;
    overlay_kind?: 'product' | 'flash' | 'coupon' | string | null;
    coupon_code?: string | null;
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
    /** Set false to disable auto floating likes on embed */
    like_sim_enabled?: boolean;
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

type CommentItem = {
    id: number;
    parent_id?: number | null;
    body: string;
    created_at?: string;
    replies?: CommentItem[];
    metadata?: {
        sender_type?: 'host' | 'attendee' | 'ai' | 'system' | string;
        sender_name?: string;
        session_key?: string;
    };
};
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
type FloatingReaction = {
    id: number;
    left: number;
    scale: number;
    delayMs: number;
    durationMs: number;
};

const props = withDefaults(
    defineProps<{
        embedSlug: string;
        layout?: 'vertical' | 'carousel' | 'inline' | 'product_page';
    }>(),
    {
        layout: 'vertical',
    },
);

type PlaylistPlayback = {
    auto_advance_enabled: boolean;
    loops_per_video: number;
};

const feed = ref<VideoItem[]>([]);
const feedPage = ref(1);
const hasMoreFeed = ref(true);
const loadingMoreFeed = ref(false);
const playlistPlayback = ref<PlaylistPlayback | null>(null);
const currentVideoPlayCount = ref(0);
const currentIndex = ref(0);
const loading = ref(true);
const reactionCount = ref(0);
const viewerCount = ref(0);
const commentText = ref('');
const commentSending = ref(false);
const comments = ref<CommentItem[]>([]);
const viewerName = ref(
    typeof window !== 'undefined'
        ? window.localStorage.getItem('embed_viewer_name') || ''
        : '',
);
const currentTimeMs = ref(0);
const errorText = ref('');
const cart = ref<CartPayload | null>(null);
const cartOpen = ref(false);
const checkoutLoading = ref(false);
const checkoutSuccessText = ref('');
const commentPanelOpen = ref(false);
const replyToCommentId = ref<number | null>(null);
const selectedVariantId = ref<number | null>(null);
const variantByTagId = ref<Record<number, number | null>>({});
const floatingReactions = ref<FloatingReaction[]>([]);
const savedVideoIds = ref<number[]>([]);
const videoElement = ref<HTMLVideoElement | null>(null);
const isMuted = ref(true);
const liveShow = ref<LiveShowItem | null>(null);
const nowTickMs = ref(Date.now());
const activeProductIndex = ref(0);
const carouselStripRef = ref<HTMLElement | null>(null);
const productCarouselRef = ref<HTMLElement | null>(null);
const carouselSwipeStartX = ref<number | null>(null);
const carouselSwipeStartY = ref<number | null>(null);

const isCarouselLayout = computed(() => props.layout === 'carousel');
const isProductPageLayout = computed(() => props.layout === 'product_page');
const isVerticalFeedLayout = computed(
    () =>
        props.layout === 'vertical' &&
        !isCarouselLayout.value &&
        !isProductPageLayout.value,
);

/* ─── simulated viewer count ─── */
const simulatedViewerCount = ref(0);
let simulationInterval: number | null = null;
let likeSimTimeout: number | null = null;
const simulatedReactionBoost = ref(0);

const touchStartY = ref<number | null>(null);
const touchStartX = ref<number | null>(null);
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
const dismissedOverlayIds = ref<Set<number>>(new Set());

const visibleTimedOverlays = computed(() => {
    const at = currentTimeMs.value;

    return (currentVideo.value?.product_tags ?? []).filter(
        (tag) =>
            isTagActiveAt(tag, at) && !dismissedOverlayIds.value.has(tag.id),
    );
});

function dismissTimedOverlay(tagId: number) {
    dismissedOverlayIds.value = new Set([
        ...dismissedOverlayIds.value,
        tagId,
    ]);
}

const OVERLAY_SLOTS: OverlaySlot[] = ['top', 'middle', 'bottom'];

function timedOverlaysForSlot(slot: OverlaySlot): ProductTag[] {
    return visibleTimedOverlays.value.filter(
        (tag) =>
            overlaySlotForAnchor(tagPosition(tag).anchor) === slot,
    );
}
const currentTag = computed(() => {
    const pinned = pinnedTags.value;

    if (pinned.length > 0) {
        return pinned[activeProductIndex.value] ?? pinned[0] ?? null;
    }

    return activeTags.value[activeProductIndex.value] ?? activeTags.value[0] ?? null;
});
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

const autoAdvanceEnabled = computed(
    () => playlistPlayback.value?.auto_advance_enabled === true,
);

const canAutoScrollFeed = computed(
    () => autoAdvanceEnabled.value && feed.value.length > 1,
);

const loopsPerVideo = computed(() =>
    Math.max(1, playlistPlayback.value?.loops_per_video ?? 1),
);

const displayReactionCount = computed(() => reactionCount.value + simulatedReactionBoost.value);

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

function likeSimulationEnabled(meta?: VideoMetadata | null): boolean {
    return meta?.like_sim_enabled !== false;
}

function stopLikeSimulation() {
    if (likeSimTimeout !== null) {
        window.clearTimeout(likeSimTimeout);
        likeSimTimeout = null;
    }
}

function spawnFloatingReaction() {
    const r: FloatingReaction = {
        id: Date.now() + Math.floor(Math.random() * 1000),
        left: 74 + Math.floor(Math.random() * 20),
        scale: 0.75 + Math.random() * 0.55,
        delayMs: Math.floor(Math.random() * 180),
        durationMs: 1100 + Math.floor(Math.random() * 700),
    };
    floatingReactions.value.push(r);
    window.setTimeout(() => {
        floatingReactions.value = floatingReactions.value.filter(
            (x) => x.id !== r.id,
        );
    }, r.durationMs + r.delayMs + 80);
}

function spawnLikeBurst(count = 1) {
    for (let i = 0; i < count; i++) {
        window.setTimeout(() => spawnFloatingReaction(), i * 90 + Math.floor(Math.random() * 80));
    }

    simulatedReactionBoost.value += count;
}

function startLikeSimulation() {
    stopLikeSimulation();
    simulatedReactionBoost.value = 18 + Math.floor(Math.random() * 52);

    const tick = () => {
        const video = currentVideo.value;

        if (!video || !likeSimulationEnabled(video.metadata)) {
            likeSimTimeout = window.setTimeout(tick, 2000);

            return;
        }

        const roll = Math.random();
        const count =
            roll > 0.88 ? 4 + Math.floor(Math.random() * 3) : roll > 0.62 ? 2 : 1;
        spawnLikeBurst(count);

        likeSimTimeout = window.setTimeout(
            tick,
            280 + Math.floor(Math.random() * 1200),
        );
    };

    tick();
}

async function fetchJson<T>(url: string, options?: RequestInit): Promise<T> {
    const h = new Headers(options?.headers ?? {});
    h.set('Accept', 'application/json');
    h.set('X-Embed-Slug', props.embedSlug);
    const requestUrl = url.startsWith('http') ? url : embedApiUrl(url);
    const r = await fetch(requestUrl, { ...options, headers: h });

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
            playlist_playback?: PlaylistPlayback;
        }>(
            `/api/v1/player/feed?embed_slug=${encodeURIComponent(props.embedSlug)}&per_page=10&page=${page}`,
        );
        const items = payload.data ?? [];
        feed.value = append ? [...feed.value, ...items] : items;

        if (!append && payload.playlist_playback) {
            playlistPlayback.value = payload.playlist_playback;
        }
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

function displayViewerName(): string {
    const name = viewerName.value.trim();

    return name !== '' ? name : 'Viewer';
}

function persistViewerName() {
    const name = viewerName.value.trim();

    if (typeof window !== 'undefined' && name !== '') {
        window.localStorage.setItem('embed_viewer_name', name);
    }
}

function commentExists(commentId: number): boolean {
    return comments.value.some(
        (existing) =>
            existing.id === commentId ||
            (existing.replies ?? []).some((reply) => reply.id === commentId),
    );
}

function pushComment(comment: CommentItem | null | undefined) {
    if (!comment?.id || commentExists(comment.id)) {
        return;
    }

    const parentId = comment.parent_id ?? null;

    if (parentId) {
        const parent = comments.value.find((existing) => existing.id === parentId);

        if (parent) {
            parent.replies = [...(parent.replies ?? []), comment];

            return;
        }
    }

    comments.value.push({
        ...comment,
        replies: comment.replies ?? [],
    });
}

function removeCommentById(commentId: number) {
    comments.value = comments.value
        .filter((comment) => comment.id !== commentId)
        .map((comment) => ({
            ...comment,
            replies: (comment.replies ?? []).filter(
                (reply) => reply.id !== commentId,
            ),
        }));
}

function removeCommentsForSession(sessionKey: string) {
    comments.value = comments.value
        .filter(
            (comment) => comment.metadata?.session_key !== sessionKey,
        )
        .map((comment) => ({
            ...comment,
            replies: (comment.replies ?? []).filter(
                (reply) => reply.metadata?.session_key !== sessionKey,
            ),
        }));
}

function handleCommentModerated(payload: {
    comment_id?: number;
    action?: string;
    session_key?: string | null;
}) {
    if (payload.action === 'banned' && payload.session_key) {
        removeCommentsForSession(payload.session_key);

        return;
    }

    if (payload.comment_id) {
        removeCommentById(payload.comment_id);
    }
}

const displayComments = computed(() => {
    const flat: Array<CommentItem & { depth: number }> = [];

    for (const comment of comments.value) {
        flat.push({ ...comment, depth: 0 });

        for (const reply of comment.replies ?? []) {
            flat.push({ ...reply, depth: 1 });
        }
    }

    return flat.slice(-24).reverse();
});

const replyToComment = computed(() =>
    replyToCommentId.value
        ? comments.value.find((c) => c.id === replyToCommentId.value) ??
          comments.value
              .flatMap((c) => c.replies ?? [])
              .find((r) => r.id === replyToCommentId.value) ??
          null
        : null,
);

async function loadComments(videoId: number, teamId: number) {
    try {
        const payload = await fetchJson<{ data?: CommentItem[] }>(
            `/api/v1/player/comments?team_id=${teamId}&video_id=${videoId}&limit=50`,
        );
        comments.value = (payload.data ?? []).map((comment) => ({
            ...comment,
            replies: comment.replies ?? [],
        }));
    } catch {
        comments.value = [];
    }
}

async function sendComment() {
    const body = commentText.value.trim();
    if (!currentVideo.value || !body || commentSending.value) {
        return;
    }

    persistViewerName();
    commentSending.value = true;
    commentText.value = '';

    try {
        const p = await fetchJson<
            { data?: CommentItem; ai_replies?: CommentItem[] } | CommentItem
        >(
            '/api/v1/player/comments',
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    team_id: currentVideo.value.team_id,
                    video_id: currentVideo.value.id,
                    body,
                    parent_id: replyToCommentId.value,
                    session_key: sessionKey,
                    metadata: {
                        sender_name: displayViewerName(),
                        session_key: sessionKey,
                    },
                }),
            },
        );
        const c = asData<CommentItem>(p);

        pushComment(c);
        replyToCommentId.value = null;

        if (typeof p === 'object' && p && 'ai_replies' in p) {
            const aiReplies: CommentItem[] = Array.isArray((p as { ai_replies?: CommentItem[] }).ai_replies)
                ? (p as { ai_replies?: CommentItem[] }).ai_replies ?? []
                : [];

            for (const aiReply of aiReplies) {
                pushComment(aiReply);
            }
        }

        void postAnalytics('comment_submitted');
    } catch {
        if (!commentText.value) {
            commentText.value = body;
        }
        errorText.value = 'Could not post comment.';
    } finally {
        commentSending.value = false;
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
        const variantId = tagVariantId(currentTag.value);
        const p = await fetchJson<{ data?: CartPayload } | CartPayload>(
            '/api/v1/player/cart/items',
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    team_id: currentVideo.value.team_id,
                    session_key: sessionKey,
                    product_id: currentTag.value.product.id,
                    ...(variantId != null
                        ? { product_variant_id: variantId }
                        : {}),
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

function defaultVariantForTag(tag: ProductTag): number | null {
    const variants = tag.product?.variants ?? [];

    return (
        variants.find((v) => v.is_default)?.id ?? variants[0]?.id ?? null
    );
}

function variantBelongsToTag(tag: ProductTag, variantId: number | null): boolean {
    if (variantId == null) {
        return false;
    }

    return (tag.product?.variants ?? []).some((variant) => variant.id === variantId);
}

function tagVariantId(tag: ProductTag): number | null {
    const selected = tag.id in variantByTagId.value
        ? variantByTagId.value[tag.id]
        : null;

    if (variantBelongsToTag(tag, selected)) {
        return selected;
    }

    return defaultVariantForTag(tag);
}

function initVariantMap(tags: ProductTag[]) {
    const map: Record<number, number | null> = {};

    for (const tag of tags) {
        map[tag.id] = defaultVariantForTag(tag);
    }

    variantByTagId.value = map;
}

async function addTagToCart(tag: ProductTag, options?: { openCart?: boolean }) {
    if (!currentVideo.value || !tag.product) {
        return;
    }

    try {
        const variantId = tagVariantId(tag);
        const p = await fetchJson<{ data?: CartPayload } | CartPayload>(
            '/api/v1/player/cart/items',
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    team_id: currentVideo.value.team_id,
                    session_key: sessionKey,
                    product_id: tag.product.id,
                    ...(variantId != null
                        ? { product_variant_id: variantId }
                        : {}),
                    quantity: 1,
                }),
            },
        );
        cart.value = asData<CartPayload>(p);
        if (options?.openCart !== false) {
            cartOpen.value = true;
        }
        void postAnalytics('add_to_cart', { product_id: tag.product.id });
    } catch {
        errorText.value = 'Could not add to cart.';
    }
}

async function buyTagNow(tag: ProductTag) {
    if (!currentVideo.value || !tag.product || checkoutLoading.value) {
        return;
    }

    const idx = pinnedTags.value.findIndex((t) => t.id === tag.id);
    if (idx >= 0) {
        activeProductIndex.value = idx;
        selectedVariantId.value = tagVariantId(tag);
    }

    await addTagToCart(tag, { openCart: false });
    if (cart.value) {
        await checkoutCart();
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

        if ('checkout_url' in p && typeof p.checkout_url === 'string' && p.checkout_url !== '') {
            window.location.href = p.checkout_url;

            return;
        }

        const orderPayload =
            'checkout_url' in p
                ? null
                : (p as { data?: { order_number?: string }; order_number?: string });
        const order = asData<{ order_number?: string }>(orderPayload);

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
    advancePlaylistVideo();
}

function advancePlaylistVideo() {
    if (feed.value.length === 0) {
        return;
    }

    if (currentIndex.value < feed.value.length - 1) {
        currentIndex.value += 1;

        return;
    }

    if (hasMoreFeed.value) {
        void loadFeed(feedPage.value + 1, true).then(() => {
            if (currentIndex.value < feed.value.length - 1) {
                currentIndex.value += 1;
            } else if (feed.value.length > 0) {
                currentIndex.value = 0;
            }
        });

        return;
    }

    if (canAutoScrollFeed.value) {
        currentIndex.value = 0;
    }
}

function onVideoEnded() {
    if (!autoAdvanceEnabled.value) {
        return;
    }

    currentVideoPlayCount.value += 1;

    if (currentVideoPlayCount.value < loopsPerVideo.value) {
        if (videoElement.value) {
            videoElement.value.currentTime = 0;
            void videoElement.value.play().catch(() => {});
        }

        return;
    }

    currentVideoPlayCount.value = 0;

    if (canAutoScrollFeed.value) {
        advancePlaylistVideo();

        return;
    }

    if (videoElement.value) {
        videoElement.value.currentTime = 0;
        void videoElement.value.play().catch(() => {});
    }
}

function previousVideo() {
    if (canGoPrevious.value) {
        currentIndex.value -= 1;
    }
}

function goToVideo(index: number) {
    if (index < 0 || index >= feed.value.length) {
        return;
    }

    currentIndex.value = index;

    if (index >= feed.value.length - 2 && hasMoreFeed.value) {
        void loadFeed(feedPage.value + 1, true);
    }
}

function isProductCarouselTouch(event: TouchEvent): boolean {
    const target = event.target as HTMLElement | null;

    return Boolean(target?.closest('.product-carousel-wrap'));
}

function onTouchStart(e: TouchEvent) {
    if (props.layout === 'carousel' || props.layout === 'product_page') {
        return;
    }

    if (isProductCarouselTouch(e)) {
        return;
    }

    touchStartX.value = e.changedTouches[0]?.clientX ?? null;
    touchStartY.value = e.changedTouches[0]?.clientY ?? null;
}
function onTouchEnd(e: TouchEvent) {
    if (props.layout === 'carousel' || props.layout === 'product_page') {
        return;
    }

    if (isProductCarouselTouch(e)) {
        return;
    }

    touchEndY.value = e.changedTouches[0]?.clientY ?? null;
    const touchEndX = e.changedTouches[0]?.clientX ?? null;

    if (
        touchStartY.value === null
        || touchEndY.value === null
        || touchStartX.value === null
        || touchEndX === null
    ) {
        return;
    }

    const deltaY = touchStartY.value - touchEndY.value;
    const deltaX = touchStartX.value - touchEndX;

    if (Math.abs(deltaX) >= Math.abs(deltaY)) {
        return;
    }

    if (deltaY > 40) {
        nextVideo();
    } else if (deltaY < -40) {
        previousVideo();
    }
}

async function initializeRealtime() {
    if (echo) {
        return;
    }

    echo = await createEmbedEcho();
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
            pushComment(e.comment);
        })
        .listen(
            '.comment.moderated',
            (e: {
                comment_id?: number;
                action?: string;
                session_key?: string | null;
            }) => {
                handleCommentModerated(e);
            },
        );
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

watch(currentIndex, async () => {
    if (!isCarouselLayout.value) {
        return;
    }

    await nextTick();
    carouselStripRef.value
        ?.querySelector('.feed-carousel-thumb--active')
        ?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
});

watch(activeProductIndex, async () => {
    const tag = currentTag.value;

    if (tag) {
        selectedVariantId.value = tagVariantId(tag);
    }

    if (!isProductPageLayout.value) {
        await nextTick();
        scrollCarouselToIndex(activeProductIndex.value);
    }
});

function scrollCarouselToIndex(index: number): void {
    const track = productCarouselRef.value;

    if (!track) {
        return;
    }

    const cards = Array.from(track.querySelectorAll<HTMLElement>('.product-card'));
    const card = cards[index];

    if (!card) {
        return;
    }

    const trackWidth = track.clientWidth;
    const cardWidth = card.offsetWidth;
    const targetLeft = card.offsetLeft - Math.max(0, (trackWidth - cardWidth) / 2);

    track.scrollTo({ left: Math.max(0, targetLeft), behavior: 'smooth' });
}

/* no-op kept so the @scroll template binding resolves without warnings */
function onProductCarouselScroll(): void {}

function onCarouselTouchStart(e: TouchEvent): void {
    e.stopPropagation();
    carouselSwipeStartX.value = e.changedTouches[0]?.clientX ?? null;
    carouselSwipeStartY.value = e.changedTouches[0]?.clientY ?? null;
}

function onCarouselTouchEnd(e: TouchEvent): void {
    e.stopPropagation();

    const startX = carouselSwipeStartX.value;
    const startY = carouselSwipeStartY.value;
    carouselSwipeStartX.value = null;
    carouselSwipeStartY.value = null;

    if (startX === null || startY === null) {
        return;
    }

    const dx = (e.changedTouches[0]?.clientX ?? startX) - startX;
    const dy = (e.changedTouches[0]?.clientY ?? startY) - startY;

    if (Math.abs(dx) <= Math.abs(dy) || Math.abs(dx) < 30) {
        return;
    }

    if (dx < 0 && activeProductIndex.value < pinnedTags.value.length - 1) {
        activeProductIndex.value += 1;
    } else if (dx > 0 && activeProductIndex.value > 0) {
        activeProductIndex.value -= 1;
    }
}

watch(selectedVariantId, (variantId) => {
    const tag = currentTag.value;

    if (tag && variantBelongsToTag(tag, variantId)) {
        variantByTagId.value = {
            ...variantByTagId.value,
            [tag.id]: variantId,
        };
    }
});

watch(currentVideo, async (video) => {
    if (!video) {
        return;
    }

    reactionCount.value = 0;
    simulatedReactionBoost.value = 0;
    currentVideoPlayCount.value = 0;
    viewerCount.value = 0;
    comments.value = [];
    liveShow.value = null;
    activeProductIndex.value = 0;
    dismissedOverlayIds.value = new Set();
    await nextTick();
    if (productCarouselRef.value) {
        productCarouselRef.value.scrollLeft = 0;
    }
    const pinned = (video.product_tags ?? []).filter((t) => t.is_pinned);
    const productList =
        pinned.length > 0 ? pinned : (video.product_tags ?? []);
    initVariantMap(productList);
    const firstTag = productList[0] ?? null;
    selectedVariantId.value = firstTag ? tagVariantId(firstTag) : null;

    stopViewerSimulation();
    stopLikeSimulation();
    const meta = video.metadata;

    if (
        meta?.viewer_sim_enabled &&
        meta.viewer_sim_min != null &&
        meta.viewer_sim_max != null
    ) {
        startViewerSimulation(meta.viewer_sim_min, meta.viewer_sim_max);
    }

    if (likeSimulationEnabled(meta)) {
        startLikeSimulation();
    }

    if (currentIndex.value >= feed.value.length - 2 && hasMoreFeed.value) {
        void loadFeed(feedPage.value + 1, true);
    }

    await initializeRealtime();
    subscribeToVideo(video.id);
    startViewerHeartbeat(video);
    await loadComments(video.id, video.team_id);
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

        if (likeSimulationEnabled(meta)) {
            startLikeSimulation();
        }

        await initializeRealtime();
        subscribeToVideo(currentVideo.value.id);
        startViewerHeartbeat(currentVideo.value);
        await loadComments(currentVideo.value.id, currentVideo.value.team_id);
        await loadCart(currentVideo.value.team_id);
        await loadLiveShow(currentVideo.value.team_id, currentVideo.value.id);
        void postAnalytics('video_view');
    }
});

onBeforeUnmount(() => {
    stopViewerHeartbeat();
    stopViewerSimulation();
    stopLikeSimulation();

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
    <div
        class="player-root"
        :class="{
            'player-root--carousel': layout === 'carousel',
            'player-root--product-page': layout === 'product_page',
            'player-root--inline': layout === 'inline',
            'player-root--vertical': isVerticalFeedLayout,
        }"
    >
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
            <p class="text-sm text-white/40">No videos livestream yet</p>
        </div>

        <!-- ═══ PLAYER ═══ -->
        <template v-else>
            <!-- Video -->
            <div
                class="video-layer"
                :class="{ 'video-layer--product-page': isProductPageLayout }"
                @touchstart.passive="onTouchStart"
                @touchend.passive="onTouchEnd"
            >
                <div class="video-stage">
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
                    :loop="!autoAdvanceEnabled"
                    preload="auto"
                    @loadeddata="playCurrentVideo"
                    @ended="onVideoEnded"
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

                <!-- Rich timed overlays (flash / coupon / product hotspots) -->
                <div
                    class="timed-overlays-layer"
                    :class="{
                        'timed-overlays-layer--vertical': isVerticalFeedLayout,
                    }"
                    aria-live="polite"
                >
                    <template v-if="isVerticalFeedLayout">
                        <div
                            v-for="slot in OVERLAY_SLOTS"
                            :key="slot"
                            class="timed-overlays-slot"
                            :class="`timed-overlays-slot--${slot}`"
                        >
                            <TransitionGroup name="timed-overlay">
                                <TimedTagOverlay
                                    v-for="tag in timedOverlaysForSlot(slot)"
                                    :key="tag.id"
                                    :tag="tag"
                                    :current-time-ms="currentTimeMs"
                                    docked
                                    @dismiss="dismissTimedOverlay"
                                    @add-to-cart="addTagToCart"
                                />
                            </TransitionGroup>
                        </div>
                    </template>
                    <TransitionGroup v-else name="timed-overlay">
                        <TimedTagOverlay
                            v-for="tag in visibleTimedOverlays"
                            :key="tag.id"
                            :tag="tag"
                            :current-time-ms="currentTimeMs"
                            @dismiss="dismissTimedOverlay"
                            @add-to-cart="addTagToCart"
                        />
                    </TransitionGroup>
                </div>

                <EmbedActionRail
                    :live-show-badge-text="liveShowBadgeText"
                    :display-viewer-count="displayViewerCount"
                    :current-index="currentIndex"
                    :feed-length="feed.length"
                    :has-more-feed="hasMoreFeed"
                    :is-muted="isMuted"
                    :display-reaction-count="displayReactionCount"
                    :comment-count="comments.length"
                    :is-saved="isSaved"
                    :cart-item-count="cartItems.length"
                    :can-go-previous="canGoPrevious"
                    :can-go-next="canGoNext"
                    :is-carousel-layout="isCarouselLayout"
                    :is-product-page-layout="isProductPageLayout"
                    @toggle-audio="toggleAudio"
                    @react="sendReaction"
                    @toggle-comments="commentPanelOpen = !commentPanelOpen"
                    @share="shareVideo"
                    @save="saveVideo"
                    @toggle-cart="cartOpen = !cartOpen"
                    @previous-video="previousVideo"
                    @next-video="nextVideo"
                />

                <!-- ── FLOATING HEARTS ── -->
                <div
                    v-for="r in floatingReactions"
                    :key="r.id"
                    class="floating-heart"
                    :style="{
                        left: `${r.left}%`,
                        '--heart-scale': String(r.scale),
                        '--heart-delay': `${r.delayMs}ms`,
                        '--heart-duration': `${r.durationMs}ms`,
                    }"
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

                </div>

                <!-- ── BOTTOM INFO + PRODUCT CAROUSEL ── -->
                <div
                    class="bottom-area"
                    :class="{ 'commerce-panel': isProductPageLayout }"
                >
                    <div v-if="isProductPageLayout" class="commerce-panel-head">
                        <span class="commerce-panel-badge">Shoppable video</span>
                        <p class="commerce-panel-eyebrow">Watch &amp; shop</p>
                    </div>
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
                        @touchstart="onCarouselTouchStart"
                        @touchmove.stop.prevent
                        @touchend="onCarouselTouchEnd"
                    >
                        <!-- Scroll track -->
                        <div
                            ref="productCarouselRef"
                            class="product-carousel"
                            :class="{
                                'product-carousel--stacked':
                                    isProductPageLayout,
                            }"
                        >
                            <div
                                v-for="(tag, idx) in pinnedTags"
                                :key="tag.id"
                                role="button"
                                tabindex="0"
                                :class="[
                                    'product-card',
                                    isProductPageLayout
                                        ? 'product-card--page'
                                        : '',
                                    idx === activeProductIndex
                                        ? 'product-card--active'
                                        : '',
                                ]"
                                @click="
                                    isProductPageLayout
                                        ? undefined
                                        : (activeProductIndex = idx)
                                "
                                @keydown.enter.prevent="activeProductIndex = idx"
                                @keydown.space.prevent="activeProductIndex = idx"
                            >
                                <div
                                    class="product-card-main"
                                    :class="{
                                        'product-card-main--clickable':
                                            isProductPageLayout,
                                    }"
                                    @click="
                                        isProductPageLayout
                                            ? (activeProductIndex = idx)
                                            : undefined
                                    "
                                >
                                    <div class="product-img-wrap">
                                        <img
                                            v-if="tag.product?.image_url"
                                            :src="tag.product.image_url"
                                            :alt="tag.product?.title"
                                            class="product-img"
                                            draggable="false"
                                            @dragstart.prevent
                                        />
                                        <div
                                            v-else
                                            class="product-img-placeholder"
                                        >
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
                                                <circle
                                                    cx="8.5"
                                                    cy="8.5"
                                                    r="1.5"
                                                />
                                                <polyline
                                                    points="21 15 16 10 5 21"
                                                />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="product-info">
                                        <p class="product-name">
                                            {{ tag.product?.title }}
                                        </p>
                                        <div class="product-price-row">
                                            <span
                                                v-if="tag.product?.sale_price"
                                                class="product-sale-price"
                                                >{{
                                                    tag.product.sale_price
                                                }}</span
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
                                                >-{{
                                                    tag.discount_percent
                                                }}%</span
                                            >
                                        </div>
                                    </div>
                                    <button
                                        v-if="!isProductPageLayout"
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
                                </div>

                                <div
                                    v-if="
                                        isProductPageLayout && tag.product
                                    "
                                    class="product-card-actions"
                                    @click.stop
                                >
                                    <select
                                        v-if="
                                            (tag.product.variants?.length ??
                                                0) > 0
                                        "
                                        v-model="variantByTagId[tag.id]"
                                        class="variant-select variant-select--page"
                                    >
                                        <option
                                            v-for="v in tag.product.variants"
                                            :key="v.id"
                                            :value="v.id"
                                        >
                                            {{ v.title }} —
                                            {{ v.sale_price || v.price }}
                                        </option>
                                    </select>
                                    <div class="product-card-cta-row">
                                        <button
                                            type="button"
                                            class="btn-add-cart btn-add-cart--page"
                                            @click="addTagToCart(tag)"
                                        >
                                            {{
                                                tag.cta_label || 'Add to cart'
                                            }}
                                        </button>
                                        <button
                                            type="button"
                                            class="btn-buy-now btn-buy-now--page"
                                            :disabled="checkoutLoading"
                                            @click="buyTagNow(tag)"
                                        >
                                            Buy now
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Dot indicators (when >1) -->
                        <div
                            v-if="
                                !isProductPageLayout && pinnedTags.length > 1
                            "
                            class="carousel-dots"
                        >
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

                        <!-- CTA row for active product (vertical / carousel layouts) -->
                        <div
                            v-if="currentTag?.product && !isProductPageLayout"
                            class="cta-row"
                        >
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

            <aside
                v-if="isCarouselLayout && feed.length > 0"
                class="feed-carousel-strip"
                aria-label="Video playlist"
            >
                <div class="feed-carousel-strip-head">
                    <div>
                        <p class="feed-carousel-strip-label">In this playlist</p>
                        <p class="feed-carousel-strip-sub">
                            Tap a video to switch
                        </p>
                    </div>
                    <span class="feed-carousel-strip-count">
                        {{ currentIndex + 1 }} / {{ feed.length }}{{ hasMoreFeed ? '+' : '' }}
                    </span>
                </div>

                <div ref="carouselStripRef" class="feed-carousel-strip-scroll">
                    <button
                        v-for="(video, index) in feed"
                        :key="video.id"
                        type="button"
                        class="feed-carousel-thumb"
                        :class="{ 'feed-carousel-thumb--active': index === currentIndex }"
                        :aria-current="index === currentIndex ? 'true' : undefined"
                        @click="goToVideo(index)"
                    >
                        <div class="feed-carousel-thumb-media">
                            <img
                                v-if="video.thumbnail_url"
                                :src="video.thumbnail_url"
                                :alt="video.title"
                                class="feed-carousel-thumb-img"
                                loading="lazy"
                            >
                            <div v-else class="feed-carousel-thumb-fallback">
                                <span>{{ video.title[0]?.toUpperCase() || 'V' }}</span>
                            </div>
                            <span class="feed-carousel-thumb-index">{{ index + 1 }}</span>
                            <span
                                v-if="index === currentIndex"
                                class="feed-carousel-thumb-playing"
                            >
                                <span class="feed-carousel-thumb-playing-dot" />
                                Now playing
                            </span>
                        </div>
                        <span class="feed-carousel-thumb-title">{{ video.title }}</span>
                    </button>
                </div>
            </aside>

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
                        <p v-if="displayComments.length === 0" class="comment-empty">
                            No comments yet. Be first!
                        </p>
                        <div
                            v-for="c in displayComments"
                            :key="c.id"
                            class="comment-item"
                            :class="{ 'comment-item--reply': c.depth > 0 }"
                        >
                            <div
                                class="comment-avatar"
                                :class="{
                                    'comment-avatar-ai': c.metadata?.sender_type === 'ai',
                                    'comment-avatar-host': c.metadata?.sender_type === 'host',
                                }"
                            >
                                {{ (c.metadata?.sender_name || c.body[0] || '?')[0]?.toUpperCase() }}
                            </div>
                            <div class="comment-content">
                                <p
                                    v-if="c.metadata?.sender_name"
                                    class="comment-author"
                                >
                                    {{ c.metadata.sender_name }}
                                </p>
                                <p class="comment-body">
                                    <ChatMessageBody
                                        :text="c.body"
                                        variant="embed"
                                    />
                                </p>
                                <button
                                    v-if="c.depth === 0 && c.metadata?.sender_type === 'attendee'"
                                    type="button"
                                    class="comment-reply-btn"
                                    @click="replyToCommentId = c.id"
                                >
                                    Reply
                                </button>
                            </div>
                        </div>
                    </div>
                    <p v-if="replyToComment" class="comment-replying">
                        Replying to {{ replyToComment.metadata?.sender_name || 'viewer' }}
                        <button
                            type="button"
                            class="comment-reply-cancel"
                            @click="replyToCommentId = null"
                        >
                            Cancel
                        </button>
                    </p>
                    <div class="comment-name-row">
                        <input
                            v-model="viewerName"
                            class="comment-name-input"
                            placeholder="Your name (optional)"
                            maxlength="40"
                            :disabled="commentSending"
                        />
                    </div>
                    <div class="comment-input-row">
                        <input
                            v-model="commentText"
                            class="comment-input"
                            placeholder="Write a comment or paste a link…"
                            :disabled="commentSending"
                            @keyup.enter="sendComment"
                        />
                        <button
                            type="button"
                            class="comment-send"
                            :disabled="commentSending || !commentText.trim()"
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

:global(html),
:global(body) {
    overflow-x: hidden;
    overscroll-behavior: none;
}

:global(#embed-player-app) {
    min-height: 100dvh;
    display: flex;
    align-items: stretch;
    justify-content: center;
    overflow-x: hidden;
    overscroll-behavior: none;
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
    overscroll-behavior: none;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    box-shadow: 0 22px 80px rgba(0, 0, 0, 0.45);
}

.player-root--carousel {
    position: relative;
    z-index: 50;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: minmax(420px, 1fr) auto;
    grid-template-areas:
        'stage'
        'rail';
    width: 100%;
    max-width: min(1180px, 100%);
    height: auto;
    min-height: min(720px, 100dvh);
    max-height: none;
    overflow: hidden;
    border-radius: 28px;
    box-shadow:
        0 28px 90px rgba(0, 0, 0, 0.42),
        0 0 0 1px rgba(255, 255, 255, 0.08) inset;
}

.player-root--carousel .video-layer {
    grid-area: stage;
    flex: none;
    min-height: 420px;
    max-height: min(78vh, 760px);
}

.player-root--carousel .bottom-area {
    z-index: 38;
    padding-bottom: 18px;
}

.player-root--carousel .floating-heart {
    z-index: 44;
    bottom: 200px;
}

.player-root--carousel .popup-card {
    z-index: 42;
}

.player-root--carousel .panel {
    z-index: 120;
}

.player-root--product-page {
    position: relative;
    z-index: 50;
    width: 100%;
    max-width: min(1180px, 100%);
    height: auto;
    min-height: min(640px, 100dvh);
    max-height: none;
    overflow: hidden;
    border-radius: 28px;
    background: #f7f4ef;
    color: #111827;
    box-shadow:
        0 28px 90px rgba(0, 0, 0, 0.2),
        0 0 0 1px rgba(17, 24, 39, 0.06);
}

.video-layer--product-page {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: minmax(360px, 52vh) auto;
    grid-template-areas:
        'stage'
        'commerce';
    gap: 0;
    flex: none;
    min-height: 0;
    overflow: hidden;
    background: #050505;
}

.video-layer--product-page .video-stage {
    position: relative;
    grid-area: stage;
    min-height: 360px;
    overflow: hidden;
    background: #050505;
}

.video-layer--product-page .commerce-panel {
    position: relative;
    grid-area: commerce;
    z-index: 20;
    min-width: 0;
    max-width: 100%;
    box-sizing: border-box;
    overflow-x: hidden;
    padding: 18px 18px 20px;
    background:
        linear-gradient(180deg, #ffffff 0%, #faf8f5 100%);
    border-top: 1px solid rgba(17, 24, 39, 0.08);
    box-shadow: 0 -8px 32px rgba(0, 0, 0, 0.06);
}

.commerce-panel-head {
    margin-bottom: 14px;
}

.commerce-panel-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    background: rgba(232, 86, 58, 0.1);
    border: 1px solid rgba(232, 86, 58, 0.22);
    padding: 4px 10px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #e8563a;
}

.commerce-panel-eyebrow {
    margin: 8px 0 0;
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
}

.player-root--product-page .floating-heart {
    z-index: 44;
    bottom: 88px;
}

.player-root--product-page .panel {
    z-index: 120;
}

.player-root--product-page .commerce-panel .video-meta {
    margin-bottom: 14px;
    padding-right: 0;
}

.player-root--product-page .commerce-panel .video-title {
    font-size: 22px;
    font-weight: 800;
    line-height: 1.2;
    color: #111827;
    text-shadow: none;
    letter-spacing: -0.02em;
}

.player-root--product-page .commerce-panel .video-desc {
    margin-top: 8px;
    font-size: 14px;
    color: #6b7280;
    -webkit-line-clamp: 4;
}

.player-root--product-page .commerce-panel .product-carousel-wrap {
    min-width: 0;
    max-width: 100%;
    background: #fff;
    border: 1px solid #ece8e2;
    border-radius: 20px;
    padding: 14px;
    box-shadow: 0 10px 30px rgba(17, 24, 39, 0.06);
    backdrop-filter: none;
    overflow: hidden;
}

.player-root--product-page .commerce-panel .product-carousel--stacked {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: min(46vh, 420px);
    overflow-x: hidden;
    overflow-y: auto;
    padding-right: 2px;
    -webkit-overflow-scrolling: touch;
}

.player-root--product-page .commerce-panel .product-card--page {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
    min-width: 0;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    background: #faf8f5;
    border: 1px solid #ece8e2;
    border-radius: 16px;
    padding: 10px;
    cursor: default;
}

.player-root--product-page .commerce-panel .product-card-main {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.player-root--product-page .commerce-panel .product-card-main--clickable {
    cursor: pointer;
}

.player-root--product-page .commerce-panel .product-card-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
    min-width: 0;
}

.player-root--product-page .commerce-panel .variant-select--page {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    border-radius: 12px;
    padding: 9px 12px;
    font-size: 12px;
    flex: none;
}

.player-root--product-page .commerce-panel .product-card-cta-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 8px;
    width: 100%;
}

.player-root--product-page .commerce-panel .btn-add-cart--page,
.player-root--product-page .commerce-panel .btn-buy-now--page {
    min-width: 0;
    width: 100%;
    justify-content: center;
    border-radius: 12px;
    padding: 10px 8px;
    font-size: 12px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.player-root--product-page .commerce-panel .btn-add-cart--page {
    background: #fff;
    border: 1px solid #e5e7eb;
    color: #111827;
}

.player-root--product-page .commerce-panel .btn-add-cart--page:hover {
    background: #f9fafb;
}

.player-root--product-page .commerce-panel .product-card {
    min-width: 0;
    background: #faf8f5;
    border: 1px solid #ece8e2;
}

.player-root--product-page .commerce-panel .product-card--active {
    background: #fff7f4;
    border-color: #e8563a;
    box-shadow: 0 0 0 1px rgba(232, 86, 58, 0.15);
}

.player-root--product-page .commerce-panel .product-name,
.player-root--product-page .commerce-panel .product-price {
    color: #111827;
}

.player-root--product-page .commerce-panel .product-price-old {
    color: #9ca3af;
}

.player-root--product-page .commerce-panel .variant-select:not(
        .variant-select--page
    ) {
    background: #fff;
    border-color: #e5e7eb;
    color: #111827;
}

@media (min-width: 900px) {
    .video-layer--product-page {
        grid-template-columns: minmax(0, 1.15fr) minmax(320px, 400px);
        grid-template-rows: minmax(560px, 72vh);
        grid-template-areas: 'stage commerce';
    }

    .video-layer--product-page .video-stage {
        min-height: 560px;
    }

    .video-layer--product-page .commerce-panel {
        display: flex;
        flex-direction: column;
        justify-content: center;
        height: 100%;
        max-height: min(82vh, 820px);
        overflow-y: auto;
        border-top: none;
        border-left: 1px solid rgba(17, 24, 39, 0.08);
        box-shadow: -10px 0 36px rgba(0, 0, 0, 0.05);
    }

    .player-root--product-page .commerce-panel .product-carousel--stacked {
        max-height: min(52vh, 480px);
    }
}

.player-root--inline {
    width: 100%;
    height: 100%;
    max-width: none;
    min-height: 0;
    border: none;
    border-radius: 0;
    box-shadow: none;
}

.feed-carousel-strip {
    position: relative;
    z-index: 60;
    grid-area: rail;
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 14px 14px 16px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    background:
        linear-gradient(180deg, rgba(18, 18, 20, 0.98), rgba(8, 8, 10, 0.99)),
        #0a0a0c;
    box-shadow: 0 -12px 40px rgba(0, 0, 0, 0.35);
}

.feed-carousel-strip-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.feed-carousel-strip-label {
    margin: 0;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #fff;
}

.feed-carousel-strip-sub {
    margin: 2px 0 0;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.52);
}

.feed-carousel-strip-count {
    flex-shrink: 0;
    border-radius: 999px;
    background: rgba(232, 86, 58, 0.16);
    border: 1px solid rgba(232, 86, 58, 0.35);
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 800;
    color: #ffb49e;
}

.feed-carousel-strip-scroll {
    display: flex;
    gap: 12px;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 4px 2px 8px;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
    mask-image: linear-gradient(
        90deg,
        transparent,
        #000 24px,
        #000 calc(100% - 24px),
        transparent
    );
}

.feed-carousel-strip-scroll::-webkit-scrollbar {
    height: 8px;
}

.feed-carousel-strip-scroll::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.22);
    border-radius: 999px;
}

.feed-carousel-thumb {
    flex: 0 0 132px;
    scroll-snap-align: center;
    display: flex;
    flex-direction: column;
    gap: 8px;
    overflow: hidden;
    border: none;
    border-radius: 16px;
    background: transparent;
    padding: 0;
    text-align: left;
    cursor: pointer;
    opacity: 0.82;
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
}

.feed-carousel-thumb:hover {
    opacity: 1;
    transform: translateY(-2px);
}

.feed-carousel-thumb--active {
    opacity: 1;
}

.feed-carousel-thumb-media {
    position: relative;
    overflow: hidden;
    border-radius: 14px;
    border: 2px solid rgba(255, 255, 255, 0.12);
    background: rgba(255, 255, 255, 0.05);
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.28);
    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.feed-carousel-thumb--active .feed-carousel-thumb-media {
    border-color: #e8563a;
    box-shadow:
        0 0 0 1px rgba(232, 86, 58, 0.45),
        0 16px 36px rgba(232, 86, 58, 0.28);
}

.feed-carousel-thumb-img {
    display: block;
    width: 100%;
    aspect-ratio: 9 / 14;
    object-fit: cover;
}

.feed-carousel-thumb-fallback {
    display: flex;
    aspect-ratio: 9 / 14;
    align-items: center;
    justify-content: center;
    background: linear-gradient(145deg, rgba(232, 86, 58, 0.35), rgba(255, 140, 66, 0.2));
    font-size: 1.35rem;
    font-weight: 800;
    color: #fff;
}

.feed-carousel-thumb-index {
    position: absolute;
    top: 8px;
    left: 8px;
    z-index: 2;
    min-width: 22px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.62);
    backdrop-filter: blur(8px);
    padding: 2px 7px;
    font-size: 10px;
    font-weight: 800;
    color: #fff;
    text-align: center;
}

.feed-carousel-thumb-playing {
    position: absolute;
    right: 8px;
    bottom: 8px;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 999px;
    background: rgba(232, 86, 58, 0.92);
    padding: 4px 8px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #fff;
}

.feed-carousel-thumb-playing-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #fff;
    animation: pulse 1.2s infinite;
}

.feed-carousel-thumb-title {
    display: -webkit-box;
    padding: 0 2px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.35;
    color: rgba(255, 255, 255, 0.9);
    overflow: hidden;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

@media (min-width: 900px) {
    .player-root--carousel {
        grid-template-columns: minmax(0, 1fr) 248px;
        grid-template-rows: minmax(560px, 1fr);
        grid-template-areas: 'stage rail';
        min-height: min(680px, 92dvh);
    }

    .player-root--carousel .video-layer {
        min-height: 560px;
        max-height: min(82vh, 820px);
    }

    .player-root--carousel .floating-heart {
        bottom: 180px;
    }

    .feed-carousel-strip {
        height: 100%;
        border-top: none;
        border-left: 1px solid rgba(255, 255, 255, 0.1);
        box-shadow: -12px 0 40px rgba(0, 0, 0, 0.28);
        padding: 18px 14px;
    }

    .feed-carousel-strip-scroll {
        flex: 1;
        flex-direction: column;
        overflow-x: hidden;
        overflow-y: auto;
        scroll-snap-type: y mandatory;
        mask-image: linear-gradient(
            180deg,
            transparent,
            #000 18px,
            #000 calc(100% - 18px),
            transparent
        );
    }

    .feed-carousel-strip-scroll::-webkit-scrollbar {
        width: 8px;
        height: auto;
    }

    .feed-carousel-thumb {
        flex: 0 0 auto;
        width: 100%;
    }

    .feed-carousel-thumb-media {
        border-radius: 16px;
    }

    .feed-carousel-thumb-img,
    .feed-carousel-thumb-fallback {
        aspect-ratio: 16 / 11;
    }
}

@media (min-width: 700px) {
    :global(#embed-player-app) {
        align-items: center;
        padding: 18px;
    }

    .player-root:not(.player-root--inline) {
        height: min(900px, calc(100dvh - 36px));
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 34px;
    }

    .player-root--carousel:not(.player-root--inline),
    .player-root--product-page:not(.player-root--inline) {
        height: auto;
        min-height: min(720px, calc(100dvh - 36px));
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

.timed-overlays-layer {
    position: absolute;
    inset: 0;
    z-index: 18;
    pointer-events: none;
    overflow: hidden;
}

.timed-overlays-layer--vertical {
    display: grid;
    grid-template-rows: auto minmax(0, 1fr) auto;
    align-content: stretch;
    box-sizing: border-box;
    padding: 56px 64px 200px 10px;
}

.timed-overlays-slot {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
    min-width: 0;
    pointer-events: none;
}

.timed-overlays-slot--top {
    align-items: flex-end;
}

.timed-overlays-slot--middle {
    align-items: center;
    justify-content: center;
    padding: 6px 0;
    overflow: hidden;
}

.timed-overlays-slot--bottom {
    align-items: flex-start;
}

.player-root--vertical .timed-overlays-slot .timed-overlay {
    pointer-events: auto;
}

.timed-overlay-enter-active,
.timed-overlay-leave-active {
    transition:
        opacity 0.28s ease,
        transform 0.28s ease;
}

.timed-overlay-enter-from,
.timed-overlay-leave-to {
    opacity: 0;
    transform: translateY(10px) scale(0.96);
}

.timed-overlays-layer--vertical .timed-overlay-enter-from,
.timed-overlays-layer--vertical .timed-overlay-leave-to {
    transform: translateY(8px) scale(0.98);
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

/* ── Floating hearts (simulated + user likes) ── */
.floating-heart {
    position: absolute;
    bottom: 148px;
    pointer-events: none;
    z-index: 20;
    animation: float-up var(--heart-duration, 1.4s) ease-out var(--heart-delay, 0ms)
        forwards;
    transform: scale(var(--heart-scale, 1));
    filter: drop-shadow(0 2px 6px rgba(255, 77, 109, 0.45));
}
@keyframes float-up {
    0% {
        opacity: 0;
        transform: translateY(8px) scale(calc(var(--heart-scale, 1) * 0.6));
    }
    12% {
        opacity: 1;
    }
    100% {
        opacity: 0;
        transform: translateY(-150px) translateX(-12px)
            scale(calc(var(--heart-scale, 1) * 1.35));
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
    overflow: hidden;
    /* max-width: 80%; */
    text-overflow: ellipsis;
    /* white-space: nowrap; */
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    line-clamp: 3; 
    box-orient: vertical;
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
    /* touch-action and overscroll are managed by the JS swipe handler */
    touch-action: none;
}
.product-carousel {
    display: flex;
    gap: 8px;
    overflow-x: scroll;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 2px;
    touch-action: none;
    user-select: none;
}
.product-carousel::-webkit-scrollbar {
    display: none;
}
.product-card {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 200px;
    scroll-snap-align: center;
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
    touch-action: none;
    -webkit-user-drag: none;
    user-select: none;
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
.comment-item--reply {
    margin-left: 28px;
    padding-left: 8px;
    border-left: 2px solid rgba(232, 86, 58, 0.35);
}

.comment-reply-btn {
    margin-top: 4px;
    border: none;
    background: none;
    padding: 0;
    font-size: 11px;
    font-weight: 700;
    color: #e8563a;
    cursor: pointer;
}

.comment-replying {
    margin: 0 0 8px;
    padding: 8px 10px;
    border-radius: 12px;
    background: rgba(232, 86, 58, 0.08);
    font-size: 11px;
    color: #6b7280;
}

.comment-reply-cancel {
    margin-left: 8px;
    border: none;
    background: none;
    color: #e8563a;
    font-weight: 700;
    cursor: pointer;
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
.comment-avatar-ai {
    background: rgba(14, 165, 233, 0.14);
    color: #0284c7;
}
.comment-avatar-host {
    background: rgba(34, 197, 94, 0.14);
    color: #15803d;
}
.comment-content {
    min-width: 0;
}
.comment-author {
    margin: 0 0 2px;
    font-size: 10px;
    font-weight: 700;
    color: rgba(17, 24, 39, 0.55);
}
.comment-body {
    margin: 0;
    font-size: 12px;
    color: rgba(17, 24, 39, 0.82);
    line-height: 1.4;
}
.comment-name-row {
    margin-bottom: 8px;
}
.comment-name-input {
    width: 100%;
    background: #f8fafc;
    border: 1px solid rgba(17, 24, 39, 0.1);
    border-radius: 10px;
    padding: 8px 12px;
    font-size: 12px;
    color: #111827;
    outline: none;
}
.comment-name-input::placeholder {
    color: rgba(17, 24, 39, 0.38);
}
.comment-name-input:focus {
    border-color: rgba(232, 86, 58, 0.55);
}
.comment-name-input:disabled {
    opacity: 0.7;
    cursor: not-allowed;
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
.comment-input:disabled {
    opacity: 0.7;
    cursor: not-allowed;
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
.comment-send:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    box-shadow: none;
}
.comment-send:disabled:hover {
    background: #e8563a;
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
    min-width: 0;
    flex: 1;
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
