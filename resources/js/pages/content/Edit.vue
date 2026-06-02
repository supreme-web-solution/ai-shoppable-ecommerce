<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowLeft,
    Check,
    Copy,
    Film,
    Globe,
    ImageOff,
    Link2,
    Lock,
    Loader2,
    MessageCircle,
    Package,
    Play,
    Share2,
    Plus,
    RefreshCw,
    Search,
    Trash2,
    Upload,
    Users,
    XCircle,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import ScrollableDialogContent from '@/components/ui/dialog/ScrollableDialogContent.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminApi } from '@/composables/useAdminApi';
import EmbedDisplaySelect from '@/components/embed/EmbedDisplaySelect.vue';
import SocialPublishPanel from '@/components/social/SocialPublishPanel.vue';
import {
    type EmbedDisplayType,
    type EmbedItem,
    embedPreviewUrl,
    embedScriptCode,
    canShareOrEmbedVideo,
    ensureEmbedForVideo,
    SHARE_EMBED_REQUIRES_PUBLISH_TITLE,
    socialShareLinks,
    updateEmbedDisplayType,
} from '@/lib/videoEmbed';

const props = defineProps<{ videoId: number }>();

const page = usePage();
const zernioEnabled = computed(() => Boolean(page.props.zernioEnabled));

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Shoppable Videos', href: '/content' },
            { title: 'Edit', href: '#' },
        ],
    },
});

type ProductOption = {
    id: number;
    title: string;
    image_url?: string | null;
    price: string;
    sale_price?: string | null;
    currency: string;
    description?: string | null;
};

type TagPositionDraft = {
    x?: number;
    y?: number;
    anchor?: string;
};

type TagDraft = {
    product_id: number;
    starts_at_ms: number;
    ends_at_ms: number;
    cta_label: string;
    discount_percent: number;
    is_pinned: boolean;
    overlay_kind: 'product' | 'flash' | 'coupon';
    coupon_code: string;
    position: TagPositionDraft | null;
    sort_order?: number;
};

type KnowledgeSourceDraft = {
    title: string;
    content: string;
};

const OVERLAY_KIND_OPTIONS = [
    { value: 'product', label: 'Product hotspot' },
    { value: 'flash', label: 'Flash discount' },
    { value: 'coupon', label: 'Coupon drop' },
] as const;

const OVERLAY_ANCHOR_OPTIONS = [
    { value: 'bottom-left', label: 'Bottom left' },
    { value: 'bottom-right', label: 'Bottom right' },
    { value: 'top-right', label: 'Top right' },
    { value: 'center', label: 'Center' },
] as const;

function defaultPositionForKind(kind: TagDraft['overlay_kind']): TagPositionDraft {
    if (kind === 'flash') {
        return { x: 4, y: 10, anchor: 'top-right' };
    }
    if (kind === 'coupon') {
        return { x: 50, y: 42, anchor: 'center' };
    }

    return { x: 4, y: 10, anchor: 'bottom-left' };
}

function onOverlayAnchorChange(tag: TagDraft) {
    const anchor = tag.position?.anchor ?? 'bottom-left';
    if (anchor === 'top-right' || anchor === 'top-left') {
        tag.position = { x: 4, y: 10, anchor };
    } else if (anchor === 'center') {
        tag.position = { x: 50, y: 42, anchor };
    } else {
        tag.position = { x: 4, y: 10, anchor };
    }
}

function onOverlayKindChange(tag: TagDraft) {
    if (tag.overlay_kind === 'flash' || tag.overlay_kind === 'coupon') {
        tag.is_pinned = false;
    }
    tag.position = defaultPositionForKind(tag.overlay_kind);
}

function onTagPinnedChange(tag: TagDraft) {
    if (tag.is_pinned && (tag.overlay_kind === 'flash' || tag.overlay_kind === 'coupon')) {
        tag.overlay_kind = 'product';
        tag.coupon_code = '';
        tag.position = defaultPositionForKind('product');
    }
}

const { getList, postJson, patchJson, apiFetch, uploadFile, ensureTeam } = useAdminApi();

const videoLoading = ref(true);
const saving = ref(false);
const replacingVideo = ref(false);
const errorText = ref('');
const infoText = ref('');
const thumbnailBroken = ref(false);
const playbackUrl = ref<string | null>(null);
const cloudinaryPublicId = ref<string | null>(null);
const previewVideoRef = ref<HTMLVideoElement | null>(null);
const previewVideoError = ref(false);
const userPausedPreview = ref(false);
const previewHasStarted = ref(false);
let pollTimer: ReturnType<typeof setInterval> | null = null;
const shareModalOpen = ref(false);
const shareLoading = ref(false);
const shareTypeSaving = ref(false);
const shareUrl = ref('');
const shopUrl = ref('');
const embedCode = ref('');
const shareEmbed = ref<EmbedItem | null>(null);
const shareEmbedType = ref<EmbedDisplayType>('vertical_feed');
const copiedToken = ref('');

const shareApi = { getList, postJson, patchJson };

const canShareOrEmbed = computed(() => canShareOrEmbedVideo(form.value.status));

const showPreviewPlayButton = computed(
    () => Boolean(playbackUrl.value) && !previewVideoError.value && (!previewHasStarted.value || userPausedPreview.value),
);

/** Prefer Cloudinary delivery URL with auto format/quality for browser playback. */
function optimizePlaybackUrl(url: string | null): string | null {
    if (!url || !url.includes('res.cloudinary.com') || !url.includes('/video/upload/')) {
        return url;
    }
    if (url.includes('/video/upload/f_auto')) {
        return url;
    }

    return url.replace('/video/upload/', '/video/upload/f_auto,q_auto/');
}

function setPlaybackUrl(url: string | null) {
    const next = url ? optimizePlaybackUrl(url) : null;
    if (next === playbackUrl.value) {
        return;
    }

    playbackUrl.value = next;
    previewVideoError.value = false;
    userPausedPreview.value = false;
    previewHasStarted.value = false;
}

async function playPreviewVideo() {
    if (userPausedPreview.value) {
        return;
    }

    previewVideoError.value = false;
    await nextTick();
    const el = previewVideoRef.value;
    if (!el || !playbackUrl.value) {
        return;
    }

    el.muted = true;
    try {
        await el.play();
        previewHasStarted.value = true;
    } catch {
        /* Autoplay blocked — user can tap play */
    }
}

function onPreviewCanPlay() {
    void playPreviewVideo();
}

function onPreviewPlaying() {
    previewHasStarted.value = true;
    userPausedPreview.value = false;
}

function onPreviewVideoError() {
    previewVideoError.value = true;
    previewHasStarted.value = false;
}

function startPreviewPlayback() {
    userPausedPreview.value = false;
    void playPreviewVideo();
}

function pausePreviewPlayback() {
    userPausedPreview.value = true;
    previewVideoRef.value?.pause();
}

function retryPreviewPlayback() {
    previewVideoError.value = false;
    userPausedPreview.value = false;
    previewHasStarted.value = false;
    previewVideoRef.value?.load();
    void playPreviewVideo();
}

/* ── video form ── */
const form = ref({
    title: '',
    description: '',
    thumbnail_url: '',
    visibility: 'public',
    status: 'draft',
});

/* ── viewer simulation ── */
const viewerSim = ref({
    enabled: false,
    min: 50,
    max: 500,
});

const visibilityOptions = [
    { value: 'public', label: 'Public', icon: Globe },
    { value: 'unlisted', label: 'Unlisted', icon: Link2 },
    { value: 'private', label: 'Private', icon: Lock },
] as const;
const aiAssistant = ref({
    enabled: false,
    knowledgeBaseText: '',
    knowledgeSources: [] as KnowledgeSourceDraft[],
});
const videoMetadata = ref<Record<string, unknown>>({});

const isProcessing = computed(() => form.value.status === 'processing');
const isFailed = computed(() => form.value.status === 'failed');
const hasPlayback = computed(() => Boolean(playbackUrl.value));

/* ── products ── */
const products = ref<ProductOption[]>([]);
const tagDrafts = ref<TagDraft[]>([]);
const tagSaving = ref(false);

/* ── product modal ── */
const productModalOpen = ref(false);
const productSearch = ref('');

const filteredProducts = computed(() => {
    const q = productSearch.value.trim().toLowerCase();
    if (!q) return products.value;
    return products.value.filter((p) => p.title.toLowerCase().includes(q));
});

const attachedProductIds = computed(() => tagDrafts.value.map((t) => t.product_id));

function formatPrice(currency: string, price: string | null | undefined): string {
    if (!price) return '';
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(Number(price));
}

function toggleProductInModal(productId: number) {
    const existing = tagDrafts.value.findIndex((t) => t.product_id === productId);
    if (existing !== -1) {
        tagDrafts.value.splice(existing, 1);
    } else {
        tagDrafts.value.push({
            product_id: productId,
            starts_at_ms: 0,
            ends_at_ms: 15000,
            cta_label: 'Shop now',
            discount_percent: 0,
            is_pinned: true,
            overlay_kind: 'product',
            coupon_code: '',
            position: defaultPositionForKind('product'),
            sort_order: tagDrafts.value.length,
        });
    }
}

function addKnowledgeSource() {
    if (aiAssistant.value.knowledgeSources.length >= 3) {
        return;
    }

    aiAssistant.value.knowledgeSources.push({
        title: '',
        content: '',
    });
}

function removeKnowledgeSource(index: number) {
    aiAssistant.value.knowledgeSources.splice(index, 1);
}

function removeTag(index: number) {
    tagDrafts.value.splice(index, 1);
}

/* ── load video ── */
async function loadVideo() {
    videoLoading.value = true;
    errorText.value = '';
    try {
        await ensureTeam();
        const payload = await apiFetch<{ data?: Record<string, unknown> } | Record<string, unknown>>(
            `/api/v1/admin/videos/${props.videoId}`,
        );
        const v = (payload as { data?: Record<string, unknown> }).data ?? payload as Record<string, unknown>;
        form.value.title = String(v.title ?? '');
        form.value.description = String(v.description ?? '');
        form.value.thumbnail_url = String(v.thumbnail_url ?? '');
        form.value.visibility = String(v.visibility ?? 'public');
        form.value.status = String(v.status ?? 'draft');
        setPlaybackUrl(v.playback_url ? String(v.playback_url) : null);
        cloudinaryPublicId.value = v.cloudinary_public_id ? String(v.cloudinary_public_id) : null;
        thumbnailBroken.value = false;

        // load viewer simulation from metadata
        const meta = v.metadata as Record<string, unknown> | null | undefined;
        videoMetadata.value = { ...(meta ?? {}) };
        if (meta?.viewer_sim_enabled) {
            viewerSim.value.enabled = Boolean(meta.viewer_sim_enabled);
            viewerSim.value.min = Number(meta.viewer_sim_min ?? 50);
            viewerSim.value.max = Number(meta.viewer_sim_max ?? 500);
        }
        aiAssistant.value.enabled = Boolean(meta?.ai_assistant_enabled);
        aiAssistant.value.knowledgeBaseText = String(meta?.knowledge_base_text ?? '');
        const rawKnowledgeSources = Array.isArray(meta?.knowledge_sources) ? meta.knowledge_sources : [];
        aiAssistant.value.knowledgeSources = rawKnowledgeSources
            .filter((source): source is Record<string, unknown> => source !== null && typeof source === 'object')
            .slice(0, 3)
            .map((source) => ({
                title: String(source.title ?? '').trim(),
                content: String(source.content ?? '').trim(),
            }))
            .filter((source) => source.title !== '' && source.content !== '');
        if (aiAssistant.value.knowledgeSources.length === 0 && aiAssistant.value.knowledgeBaseText.trim() !== '') {
            aiAssistant.value.knowledgeSources = [{
                title: 'Knowledge Hub',
                content: aiAssistant.value.knowledgeBaseText.trim(),
            }];
        }

        // load existing product tags
        const tagsPayload = await apiFetch<{ data: Array<{
            product_id: number;
            starts_at_ms?: number;
            ends_at_ms?: number;
            cta_label?: string;
            discount_percent?: number;
            is_pinned?: boolean;
            overlay_kind?: TagDraft['overlay_kind'];
            coupon_code?: string | null;
            position?: TagPositionDraft | null;
            sort_order?: number;
        }> }>(`/api/v1/admin/videos/${props.videoId}/product-tags`);

        tagDrafts.value = (tagsPayload.data ?? []).map((tag, i) => {
            const overlayKind =
                tag.overlay_kind === 'flash' ||
                tag.overlay_kind === 'coupon' ||
                tag.overlay_kind === 'product'
                    ? tag.overlay_kind
                    : 'product';

            return {
                product_id: tag.product_id,
                starts_at_ms: tag.starts_at_ms ?? 0,
                ends_at_ms: tag.ends_at_ms ?? 0,
                cta_label: tag.cta_label ?? 'Shop now',
                discount_percent: Number(tag.discount_percent ?? 0),
                is_pinned: Boolean(tag.is_pinned),
                overlay_kind: overlayKind,
                coupon_code: tag.coupon_code ?? '',
                position:
                    tag.position ?? defaultPositionForKind(overlayKind),
                sort_order: tag.sort_order ?? i,
            };
        });
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load video.';
    } finally {
        videoLoading.value = false;
    }
}

async function loadProducts() {
    try {
        const payload = await getList<ProductOption>('/api/v1/admin/products');
        products.value = payload.data ?? [];
    } catch {
        products.value = [];
    }
}

async function saveVideo() {
    saving.value = true;
    errorText.value = '';
    try {
        const nextMetadata: Record<string, unknown> = { ...videoMetadata.value };
        delete nextMetadata.viewer_sim_enabled;
        delete nextMetadata.viewer_sim_min;
        delete nextMetadata.viewer_sim_max;
        delete nextMetadata.ai_assistant_enabled;
        delete nextMetadata.knowledge_base_text;
        delete nextMetadata.knowledge_sources;

        if (viewerSim.value.enabled) {
            nextMetadata.viewer_sim_enabled = true;
            nextMetadata.viewer_sim_min = viewerSim.value.min;
            nextMetadata.viewer_sim_max = viewerSim.value.max;
        }

        if (aiAssistant.value.enabled) {
            const knowledgeSources = aiAssistant.value.knowledgeSources
                .slice(0, 3)
                .map((source) => ({
                    title: source.title.trim(),
                    content: source.content.trim(),
                }))
                .filter((source) => source.title !== '' && source.content !== '');

            nextMetadata.ai_assistant_enabled = true;
            nextMetadata.knowledge_sources = knowledgeSources;
            nextMetadata.knowledge_base_text = knowledgeSources[0]?.content ?? aiAssistant.value.knowledgeBaseText.trim();
        } else {
            nextMetadata.ai_assistant_enabled = false;
            nextMetadata.knowledge_sources = [];
        }

        await patchJson(`/api/v1/admin/videos/${props.videoId}`, {
            title: form.value.title,
            description: form.value.description || null,
            thumbnail_url: form.value.thumbnail_url || null,
            visibility: form.value.visibility,
            metadata: Object.keys(nextMetadata).length > 0 ? nextMetadata : null,
        });
        // also save tags
        await postJson(`/api/v1/admin/videos/${props.videoId}/product-tags/sync`, {
            tags: tagDrafts.value,
        });
        router.visit('/content');
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not save.';
    } finally {
        saving.value = false;
    }
}

async function publishVideo() {
    saving.value = true;
    try {
        await patchJson(`/api/v1/admin/videos/${props.videoId}`, {
            status: 'published',
            published_at: new Date().toISOString(),
        });
        form.value.status = 'published';
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not publish.';
    } finally {
        saving.value = false;
    }
}

async function unpublishVideo() {
    saving.value = true;
    try {
        await patchJson(`/api/v1/admin/videos/${props.videoId}`, {
            status: 'ready',
            published_at: null,
        });
        form.value.status = 'ready';
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not unpublish.';
    } finally {
        saving.value = false;
    }
}

const attachedProducts = computed(() =>
    tagDrafts.value.map((tag) => ({
        tag,
        product: products.value.find((p) => p.id === tag.product_id),
    })).filter(({ product }) => product !== undefined),
);

function startPollingIfNeeded() {
    stopPolling();
    if (form.value.status !== 'processing') return;

    pollTimer = setInterval(async () => {
        try {
            const payload = await apiFetch<{ data?: Record<string, unknown> } | Record<string, unknown>>(
                `/api/v1/admin/videos/${props.videoId}`,
            );
            const v = (payload as { data?: Record<string, unknown> }).data ?? payload as Record<string, unknown>;
            form.value.status = String(v.status ?? form.value.status);
            if (v.playback_url) {
                setPlaybackUrl(String(v.playback_url));
            }
            if (v.thumbnail_url && !form.value.thumbnail_url) {
                form.value.thumbnail_url = String(v.thumbnail_url);
            }
            if (v.cloudinary_public_id) cloudinaryPublicId.value = String(v.cloudinary_public_id);
            thumbnailBroken.value = false;

            if (form.value.status === 'ready' || form.value.status === 'published') {
                infoText.value = 'Video processed and ready to play.';
                stopPolling();
            } else if (form.value.status === 'failed') {
                infoText.value = 'Processing failed. Re-upload the video file below.';
                stopPolling();
            }
        } catch {
            /* keep polling */
        }
    }, 3000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function replaceVideoFile(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    replacingVideo.value = true;
    errorText.value = '';
    infoText.value = 'Uploading video and sending to Server…';
    try {
        await ensureTeam();
        const upload = await uploadFile('/api/v1/admin/videos/upload', file);
        await patchJson(`/api/v1/admin/videos/${props.videoId}`, {
            local_file_path: upload.local_file_path,
        });
        form.value.status = 'processing';
        setPlaybackUrl(null);
        cloudinaryPublicId.value = null;
        infoText.value = 'Video processing. This page will update automatically.';
        startPollingIfNeeded();
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not replace video.';
        infoText.value = '';
    } finally {
        replacingVideo.value = false;
        input.value = '';
    }
}

async function copyText(text: string, token: string) {
    await navigator.clipboard.writeText(text);
    copiedToken.value = token;
    window.setTimeout(() => {
        if (copiedToken.value === token) copiedToken.value = '';
    }, 1800);
}

async function openShareModal() {
    if (!canShareOrEmbed.value) {
        return;
    }

    shareModalOpen.value = true;
    shareLoading.value = true;
    errorText.value = '';
    try {
        const embed = await ensureEmbedForVideo(
            shareApi,
            props.videoId,
            form.value.title || `video-${props.videoId}`,
        );
        if (!embed) throw new Error('Could not generate embed.');
        shareEmbed.value = embed;
        shareEmbedType.value = (embed.type as EmbedDisplayType) || 'vertical_feed';
        shareUrl.value = embedPreviewUrl(embed);
        shopUrl.value = embed.shop_url ?? '';
        embedCode.value = embedScriptCode(embed, shareEmbedType.value);

        if (zernioEnabled.value) {
            try {
                const id = await ensureTeam();
                const shop = await apiFetch<{ shop_url: string }>(
                    `/api/v1/admin/zernio/shop-link?team_id=${id}&video_id=${props.videoId}`,
                );
                shopUrl.value = shop.shop_url;
            } catch {
                shopUrl.value = embed.shop_url ?? shareUrl.value.replace('/embed/', '/shop/');
            }
        }
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not prepare share links.';
        shareUrl.value = '';
        embedCode.value = '';
        shareEmbed.value = null;
    } finally {
        shareLoading.value = false;
    }
}

async function onShareEmbedTypeChange(type: EmbedDisplayType) {
    if (!shareEmbed.value) {
        return;
    }

    shareEmbedType.value = type;
    shareTypeSaving.value = true;

    try {
        const updated = await updateEmbedDisplayType(
            shareApi,
            shareEmbed.value.id,
            type,
        );

        if (updated) {
            shareEmbed.value = updated;
            embedCode.value = embedScriptCode(updated, type);
        } else {
            embedCode.value = embedScriptCode(shareEmbed.value, type);
        }
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not update embed display.';
        shareEmbedType.value = (shareEmbed.value.type as EmbedDisplayType) || 'vertical_feed';
    } finally {
        shareTypeSaving.value = false;
    }
}

onMounted(async () => {
    await Promise.all([loadVideo(), loadProducts()]);
    if (isProcessing.value) {
        infoText.value = 'Video is processing. This page will update automatically.';
        startPollingIfNeeded();
    } else if (!hasPlayback.value && form.value.status !== 'failed') {
        infoText.value = 'No playback URL yet. Re-upload the video.';
    }
});

onUnmounted(stopPolling);
</script>

<template>
    <Head title="Edit Shoppable Video" />

    <div class="edit-root flex min-h-screen flex-1 flex-col gap-5 p-4 md:p-5">

        <!-- ── Header ── -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Link href="/content" class="back-btn flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm font-medium text-gray-500 transition-colors hover:bg-white hover:text-gray-800">
                    <ArrowLeft class="size-4" />
                    Videos
                </Link>
                <div class="h-5 w-px bg-gray-200" />
                <div>
                    <h1 class="text-xl font-black text-gray-900">Edit Shoppable Video</h1>
                    <p class="text-xs text-gray-500">Update details, thumbnail &amp; tagged products.</p>
                </div>
            </div>
            <!-- Status + Publish actions -->
            <div class="flex items-center gap-2">
                <span :class="[
                    'status-badge',
                    form.status === 'published' ? 'status-published' :
                    form.status === 'processing' ? 'status-processing' :
                    form.status === 'failed' ? 'status-failed' : 'status-draft',
                ]">
                    {{ form.status }}
                </span>
                <button
                    type="button"
                    class="ghost-btn flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm font-semibold text-gray-600 disabled:cursor-not-allowed disabled:opacity-45"
                    :disabled="saving || !canShareOrEmbed"
                    :title="canShareOrEmbed ? undefined : SHARE_EMBED_REQUIRES_PUBLISH_TITLE"
                    @click="openShareModal"
                >
                    <Link2 class="size-3.5" />
                    Embed
                </button>
                <button
                    type="button"
                    class="ghost-btn flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-sm font-semibold text-gray-600 disabled:cursor-not-allowed disabled:opacity-45"
                    :disabled="saving || !canShareOrEmbed"
                    :title="canShareOrEmbed ? undefined : SHARE_EMBED_REQUIRES_PUBLISH_TITLE"
                    @click="openShareModal"
                >
                    <Share2 class="size-3.5" />
                    Share
                </button>
                <button
                    v-if="form.status !== 'published'"
                    type="button"
                    class="cta-btn rounded-xl px-4 py-1.5 text-sm font-bold text-white"
                    :disabled="saving || form.status === 'processing'"
                    @click="publishVideo"
                >
                    <Loader2 v-if="saving" class="mr-1.5 inline size-3.5 animate-spin" />
                    Publish
                </button>
                <button
                    v-else
                    type="button"
                    class="ghost-btn rounded-xl px-4 py-1.5 text-sm font-semibold text-gray-600"
                    :disabled="saving"
                    @click="unpublishVideo"
                >
                    Unpublish
                </button>
            </div>
        </div>

        <!-- ── Error banner ── -->
        <div
            v-if="errorText"
            class="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            <XCircle class="size-4 shrink-0" />
            {{ errorText }}
        </div>

        <!-- ── Info / processing banner ── -->
        <div
            v-if="infoText || isProcessing || isFailed"
            :class="[
                'flex items-start gap-2 rounded-xl border px-4 py-3 text-sm',
                isFailed
                    ? 'border-red-200 bg-red-50 text-red-700'
                    : 'border-amber-200 bg-amber-50 text-amber-800',
            ]"
        >
            <Loader2 v-if="isProcessing" class="mt-0.5 size-4 shrink-0 animate-spin" />
            <AlertCircle v-else class="mt-0.5 size-4 shrink-0" />
            <div>
                <p v-if="infoText">{{ infoText }}</p>
                <p v-else-if="isProcessing">Processing on Cloudinary…</p>
                <p v-else-if="isFailed">Upload failed. Try replacing the video file below.</p>
                <!-- <p v-if="cloudinaryPublicId" class="mt-0.5 text-xs opacity-70">Cloudinary: {{ cloudinaryPublicId }}</p> -->
            </div>
        </div>

        <!-- ── Loading skeleton ── -->
        <div v-if="videoLoading" class="grid gap-5 lg:grid-cols-3">
            <Skeleton class="h-96 rounded-2xl lg:col-span-2" />
            <Skeleton class="h-64 rounded-2xl" />
        </div>

        <!-- ── Main grid ── -->
        <div v-else class="grid gap-5 lg:grid-cols-3">

            <!-- Left column -->
            <div class="space-y-5 lg:col-span-2">

                <!-- Details card -->
                <div class="section-card">
                    <div class="section-card-header">
                        <div class="section-icon">
                            <Film class="size-4 text-[#E8563A]" />
                        </div>
                        <h2 class="section-title">Video details</h2>
                    </div>
                    <div class="space-y-4 p-5">
                        <div class="space-y-1.5">
                            <label class="field-label" for="e-title">Title</label>
                            <Input id="e-title" v-model="form.title" placeholder="Summer launch reel" class="field-input" />
                        </div>

                        <div class="space-y-1.5">
                            <label class="field-label" for="e-desc">Description</label>
                            <textarea
                                id="e-desc"
                                v-model="form.description"
                                rows="3"
                                class="field-textarea"
                                placeholder="Optional description…"
                            />
                        </div>

                        <!-- Visibility pills -->
                        <div class="space-y-1.5">
                            <label class="field-label">Visibility</label>
                            <div class="flex gap-2">
                                <button
                                    v-for="vis in visibilityOptions"
                                    :key="vis.value"
                                    type="button"
                                    :class="[
                                        'flex flex-1 items-center justify-center gap-1.5 rounded-xl border py-2 text-sm font-medium transition-all',
                                        form.visibility === vis.value
                                            ? 'border-[#E8563A] bg-[#E8563A]/10 text-[#E8563A] font-semibold'
                                            : 'border-gray-200 bg-white text-gray-500 hover:border-[#E8563A]/40',
                                    ]"
                                    @click="form.visibility = vis.value"
                                >
                                    <component :is="vis.icon" class="size-3.5 shrink-0" />
                                    {{ vis.label }}
                                </button>
                            </div>
                        </div>

                        <!-- Replace video file -->
                        <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-4 space-y-2">
                            <label class="field-label">Video file</label>
                            <!-- <p class="text-xs text-gray-500">Uploaded to local storage, then sent to Cloudinary.</p> -->
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-600 shadow-sm transition-colors hover:border-[#E8563A]/40 hover:text-[#E8563A]">
                                <Upload class="size-4" />
                                {{ replacingVideo ? 'Uploading…' : hasPlayback ? 'Replace video' : 'Upload video' }}
                                <input
                                    type="file"
                                    accept="video/mp4,video/quicktime,video/webm,video/x-msvideo"
                                    class="hidden"
                                    :disabled="replacingVideo"
                                    @change="replaceVideoFile"
                                >
                            </label>
                            <p v-if="playbackUrl" class="truncate text-xs text-gray-400">{{ playbackUrl }}</p>
                        </div>

                        <!-- Thumbnail -->
                        <div class="space-y-1.5">
                            <label class="field-label">Thumbnail</label>
                            <div class="flex items-start gap-3">
                                <div class="flex h-20 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-gray-200 bg-gray-100">
                                    <img
                                        v-if="form.thumbnail_url && !thumbnailBroken"
                                        :src="form.thumbnail_url"
                                        alt="Thumbnail"
                                        class="h-full w-full object-cover"
                                        @error="thumbnailBroken = true"
                                    >
                                    <Film v-else class="size-5 text-gray-400" />
                                </div>
                                <Input
                                    v-model="form.thumbnail_url"
                                    placeholder="Paste thumbnail URL (e.g. from Cloudinary)"
                                    class="field-input flex-1"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Viewer simulation card -->
                <div class="section-card">
                    <div class="section-card-header">
                        <div class="section-icon">
                            <Users class="size-4 text-[#E8563A]" />
                        </div>
                        <h2 class="section-title">Live viewer simulation</h2>
                    </div>
                    <div class="p-5 space-y-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-gray-800">Simulate live viewers</p>
                                <p class="mt-0.5 text-xs text-gray-500">Show an animated viewer count that drifts between your chosen numbers in the embed.</p>
                            </div>
                            <label class="relative mt-0.5 inline-flex shrink-0 cursor-pointer items-center">
                                <input v-model="viewerSim.enabled" type="checkbox" class="peer sr-only">
                                <div class="h-6 w-11 rounded-full bg-gray-200 transition-colors peer-checked:bg-[#E8563A] after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-all peer-checked:after:translate-x-5" />
                            </label>
                        </div>
                        <div v-if="viewerSim.enabled" class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5">
                                <label class="field-label">Min viewers</label>
                                <Input v-model.number="viewerSim.min" type="number" min="1" :max="viewerSim.max - 1" placeholder="50" class="field-input" />
                            </div>
                            <div class="space-y-1.5">
                                <label class="field-label">Max viewers</label>
                                <Input v-model.number="viewerSim.max" type="number" :min="viewerSim.min + 1" placeholder="500" class="field-input" />
                            </div>
                            <p class="col-span-2 text-xs text-gray-500">Count drifts between {{ viewerSim.min }} and {{ viewerSim.max }}.</p>
                        </div>
                    </div>
                </div>

                <!-- AI assistant for live chat -->
                <div class="section-card">
                    <div class="section-card-header">
                        <div class="section-icon">
                            <MessageCircle class="size-4 text-[#E8563A]" />
                        </div>
                        <h2 class="section-title">AI assistant for live chat</h2>
                    </div>
                    <div class="p-5 space-y-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-gray-800">Auto-reply in video comments</p>
                                <p class="mt-0.5 text-xs text-gray-500">When enabled, AI replies to viewer comments using this video's knowledge base.</p>
                            </div>
                            <label class="relative mt-0.5 inline-flex shrink-0 cursor-pointer items-center">
                                <input v-model="aiAssistant.enabled" type="checkbox" class="peer sr-only">
                                <div class="h-6 w-11 rounded-full bg-gray-200 transition-colors peer-checked:bg-[#E8563A] after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-all peer-checked:after:translate-x-5" />
                            </label>
                        </div>
                        <div v-if="aiAssistant.enabled" class="space-y-3">
                            <div class="flex items-center justify-between">
                                <label class="field-label">Knowledge sources (max 3)</label>
                                <button
                                    type="button"
                                    class="ghost-btn inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold"
                                    :disabled="aiAssistant.knowledgeSources.length >= 3"
                                    @click="addKnowledgeSource"
                                >
                                    <Plus class="size-3.5" />
                                    Add source
                                </button>
                            </div>
                            <div
                                v-for="(source, sourceIndex) in aiAssistant.knowledgeSources"
                                :key="sourceIndex"
                                class="rounded-xl border border-gray-200 bg-white p-3 space-y-2"
                            >
                                <div class="flex items-center gap-2">
                                    <Input
                                        v-model="source.title"
                                        class="h-8 text-xs"
                                        placeholder="Source title (e.g. Shipping policy)"
                                    />
                                    <button
                                        type="button"
                                        class="flex size-7 items-center justify-center rounded-lg border border-red-100 bg-red-50 text-red-400 transition-colors hover:bg-red-100 hover:text-red-600"
                                        @click="removeKnowledgeSource(sourceIndex)"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </button>
                                </div>
                                <textarea
                                    v-model="source.content"
                                    rows="4"
                                    class="field-textarea"
                                    placeholder="Paste concise facts, FAQs, and policies for this source."
                                />
                            </div>
                            <div v-if="aiAssistant.knowledgeSources.length === 0" class="rounded-xl border border-dashed border-gray-200 p-3 text-xs text-gray-500">
                                Add at least one source so AI can answer with grounded information.
                            </div>
                            <p class="text-xs text-gray-500">
                                AI chunks these sources, creates embeddings, and retrieves best matches per viewer message.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Products card -->
                <div id="products" class="section-card">
                    <div class="section-card-header">
                        <div class="section-icon">
                            <Package class="size-4 text-[#E8563A]" />
                        </div>
                        <div class="flex flex-1 items-center justify-between">
                            <h2 class="section-title">Products during playback</h2>
                            <button
                                type="button"
                                class="ghost-btn flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold"
                                @click="productModalOpen = true; productSearch = ''"
                            >
                                <Plus class="size-3.5" />
                                Attach
                            </button>
                        </div>
                    </div>
                    <p class="px-5 pb-2 text-xs text-gray-500">These products appear below the video for viewers to purchase.</p>
                    <div class="px-5 pb-5">
                        <div v-if="attachedProducts.length === 0" class="rounded-xl border border-dashed border-gray-200 py-8 text-center">
                            <Package class="mx-auto mb-2 size-6 text-gray-300" />
                            <p class="text-sm text-gray-500">No products attached yet.</p>
                        </div>

                        <div v-else class="space-y-3">
                            <div
                                v-for="({ tag, product }, index) in attachedProducts"
                                :key="index"
                                class="rounded-xl border border-gray-100 bg-gray-50/60 p-3"
                            >
                                <div class="mb-3 flex items-center gap-3">
                                    <div class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-gray-200 bg-white">
                                        <img v-if="product!.image_url" :src="product!.image_url" class="h-full w-full object-cover">
                                        <ImageOff v-else class="size-4 text-gray-400" />
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-sm font-semibold text-gray-800">{{ product!.title }}</p>
                                        <p class="text-xs font-bold text-[#E8563A]">
                                            {{ formatPrice(product!.currency, product!.sale_price || product!.price) }}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="flex size-7 items-center justify-center rounded-lg border border-red-100 bg-red-50 text-red-400 transition-colors hover:bg-red-100 hover:text-red-600"
                                        @click="removeTag(index)"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </button>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <label class="field-label mb-1 block">Overlay type</label>
                                        <select
                                            v-model="tag.overlay_kind"
                                            class="h-8 w-full rounded-lg border border-gray-200 bg-white px-2 text-xs"
                                            @change="onOverlayKindChange(tag)"
                                        >
                                            <option
                                                v-for="opt in OVERLAY_KIND_OPTIONS"
                                                :key="opt.value"
                                                :value="opt.value"
                                            >
                                                {{ opt.label }}
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="field-label mb-1 block">CTA label</label>
                                        <Input v-model="tag.cta_label" class="h-8 text-xs" />
                                    </div>
                                    <div>
                                        <label class="field-label mb-1 block">Discount %</label>
                                        <Input v-model.number="tag.discount_percent" type="number" min="0" max="100" class="h-8 text-xs" />
                                    </div>
                                    <div v-if="tag.overlay_kind === 'coupon'">
                                        <label class="field-label mb-1 block">Coupon code</label>
                                        <Input v-model="tag.coupon_code" class="h-8 text-xs font-mono uppercase" placeholder="SAVE20" />
                                    </div>
                                    <div>
                                        <label class="field-label mb-1 block">Show from (ms)</label>
                                        <Input v-model.number="tag.starts_at_ms" type="number" min="0" class="h-8 text-xs" :disabled="tag.is_pinned" />
                                    </div>
                                    <div>
                                        <label class="field-label mb-1 block">Show until (ms)</label>
                                        <Input v-model.number="tag.ends_at_ms" type="number" min="0" class="h-8 text-xs" :disabled="tag.is_pinned" />
                                    </div>
                                    <div v-if="!tag.is_pinned">
                                        <label class="field-label mb-1 block">On-screen position</label>
                                        <select
                                            v-model="tag.position!.anchor"
                                            class="h-8 w-full rounded-lg border border-gray-200 bg-white px-2 text-xs"
                                            @change="onOverlayAnchorChange(tag)"
                                        >
                                            <option
                                                v-for="opt in OVERLAY_ANCHOR_OPTIONS"
                                                :key="opt.value"
                                                :value="opt.value"
                                            >
                                                {{ opt.label }}
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                <p v-if="!tag.is_pinned" class="mt-2 text-[11px] text-gray-500">
                                    In the vertical feed, overlays snap to the top, center, or bottom zone (clear of the side buttons and product list).
                                </p>
                                <label class="mt-2 flex cursor-pointer items-center gap-2 text-xs text-gray-600">
                                    <input
                                        v-model="tag.is_pinned"
                                        type="checkbox"
                                        class="accent-[#E8563A]"
                                        @change="onTagPinnedChange(tag)"
                                    >
                                    Always pinned in product list (not a timed overlay)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Save / Cancel -->
                <div class="flex items-center gap-3 pb-4">
                    <button
                        type="button"
                        class="cta-btn flex items-center gap-2 rounded-xl px-6 py-2.5 text-sm font-bold text-white"
                        :disabled="saving"
                        @click="saveVideo"
                    >
                        <Loader2 v-if="saving" class="size-4 animate-spin" />
                        {{ saving ? 'Saving…' : 'Save changes' }}
                    </button>
                    <Link href="/content" class="ghost-btn rounded-xl px-4 py-2.5 text-sm font-semibold text-gray-600">
                        Cancel
                    </Link>
                </div>
            </div>

            <!-- Right column: preview -->
            <div class="space-y-4">
                <div class="section-card p-5">
                    <p class="mb-4 text-xs font-bold uppercase tracking-widest text-gray-400">9:16 Live Preview</p>

                    <!-- Phone shell -->
                    <div class="mx-auto" style="width: 180px">
                        <div
                            class="relative overflow-hidden rounded-[28px] border-[5px] border-gray-800 bg-black shadow-2xl"
                            style="height: 320px"
                        >
                            <div class="absolute left-1/2 top-2 z-10 h-2.5 w-12 -translate-x-1/2 rounded-full bg-white/20" />

                            <!-- Thumbnail fallback -->
                            <img
                                v-if="!playbackUrl && form.thumbnail_url && !thumbnailBroken"
                                :src="form.thumbnail_url"
                                alt=""
                                class="absolute inset-0 z-0 h-full w-full object-cover"
                                @error="thumbnailBroken = true"
                            >

                            <!-- Video -->
                            <video
                                v-if="playbackUrl && !previewVideoError"
                                ref="previewVideoRef"
                                :src="playbackUrl"
                                class="absolute inset-0 z-1 h-full w-full object-cover"
                                playsinline muted loop autoplay preload="auto"
                                @canplay="onPreviewCanPlay"
                                @playing="onPreviewPlaying"
                                @error="onPreviewVideoError"
                            />

                            <!-- Play overlay -->
                            <button
                                v-if="showPreviewPlayButton"
                                type="button"
                                class="absolute inset-0 z-2 flex items-center justify-center bg-black/30"
                                @click.stop="startPreviewPlayback"
                            >
                                <span class="flex size-12 items-center justify-center rounded-full bg-[#E8563A] shadow-lg">
                                    <Play class="ml-0.5 size-5 text-white" />
                                </span>
                            </button>

                            <!-- Pause control -->
                            <button
                                v-else-if="playbackUrl && !previewVideoError && previewHasStarted"
                                type="button"
                                class="absolute right-2 top-8 z-2 rounded-full bg-black/50 px-2 py-0.5 text-[9px] text-white"
                                @click.stop="pausePreviewPlayback"
                            >
                                Pause
                            </button>

                            <!-- Error state -->
                            <div
                                v-if="previewVideoError"
                                class="absolute inset-0 z-1 flex flex-col items-center justify-center gap-1 bg-black/80 px-2 text-center"
                            >
                                <XCircle class="size-6 text-red-400" />
                                <p class="text-[10px] text-white/70">Video failed to load</p>
                                <button type="button" class="text-[10px] text-[#E8563A] underline" @click="retryPreviewPlayback">Retry</button>
                            </div>

                            <!-- Empty state -->
                            <div
                                v-else-if="!playbackUrl && (!form.thumbnail_url || thumbnailBroken)"
                                class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-white/30"
                            >
                                <Loader2 v-if="isProcessing" class="size-8 animate-spin" />
                                <Film v-else class="size-8" />
                                <p class="text-[10px]">{{ isProcessing ? 'Processing…' : 'No video yet' }}</p>
                            </div>

                            <!-- Product overlay -->
                            <div class="pointer-events-none absolute inset-x-0 bottom-0 z-3 bg-linear-to-t from-black/90 via-black/50 to-transparent p-2.5 pt-8">
                                <template v-if="attachedProducts.length">
                                    <div
                                        v-for="({ tag, product }) in attachedProducts.slice(0, 2)"
                                        :key="tag.product_id"
                                        class="mb-1.5 flex items-center gap-2 rounded-xl bg-white/15 px-2 py-1.5 backdrop-blur-sm"
                                    >
                                        <div class="flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-md bg-white/20">
                                            <img v-if="product!.image_url" :src="product!.image_url" class="h-full w-full object-cover">
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-[9px] font-semibold text-white">{{ product!.title }}</p>
                                            <p class="text-[8px] text-white/70">{{ formatPrice(product!.currency, product!.sale_price || product!.price) }}</p>
                                        </div>
                                        <span class="shrink-0 rounded-full bg-[#E8563A] px-1.5 py-0.5 text-[8px] font-bold text-white">Buy</span>
                                    </div>
                                </template>
                                <p v-else class="text-center text-[9px] text-white/40">Attach products above</p>
                            </div>
                        </div>
                    </div>

                    <!-- Refresh -->
                    <button
                        type="button"
                        class="ghost-btn mx-auto mt-4 flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold text-gray-500"
                        :disabled="videoLoading"
                        @click="loadVideo"
                    >
                        <RefreshCw class="size-3" />
                        Refresh preview
                    </button>
                    <p class="mt-2 text-center text-[10px] text-gray-400">
                        Tap <strong>Play</strong> if it doesn't autoplay.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ Share & Embed modal ═══════ -->
    <Dialog v-model:open="shareModalOpen">
        <ScrollableDialogContent class="sm:max-w-[560px]" body-class="py-3">
            <template #header>
                <DialogHeader class="space-y-0 p-0">
                    <DialogTitle class="flex items-center gap-2">
                        <Share2 class="size-4 text-[#E8563A]" />
                        Share & Embed
                    </DialogTitle>
                    <DialogDescription>
                        {{ form.title || `Video #${props.videoId}` }} — CDN embed and social share links.
                    </DialogDescription>
                </DialogHeader>
            </template>

            <div v-if="shareLoading" class="space-y-2">
                <Skeleton class="h-9 w-full rounded-xl" />
                <Skeleton class="h-20 w-full rounded-xl" />
                <Skeleton class="h-20 w-full rounded-xl" />
            </div>

            <div v-else class="space-y-4">
                <EmbedDisplaySelect
                    :model-value="shareEmbedType"
                    :disabled="shareTypeSaving || !shareEmbed"
                    @update:model-value="onShareEmbedTypeChange"
                />

                <div class="space-y-1.5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Preview link</p>
                    <div class="flex items-center gap-2 rounded-xl border bg-gray-50 p-2">
                        <Input :model-value="shareUrl" readonly class="h-8 text-xs" />
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="!shareUrl"
                            @click="copyText(shareUrl, 'share-link')"
                        >
                            <Copy class="mr-1 size-3.5" />
                            {{ copiedToken === 'share-link' ? 'Copied' : 'Copy' }}
                        </Button>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">CDN embed code</p>
                    <div class="rounded-xl border bg-gray-50 p-2">
                        <pre class="max-h-28 overflow-auto whitespace-pre-wrap text-[11px]">{{ embedCode }}</pre>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="!embedCode"
                        @click="copyText(embedCode, 'embed-code')"
                    >
                        <Copy class="mr-1 size-3.5" />
                        {{ copiedToken === 'embed-code' ? 'Copied' : 'Copy embed code' }}
                    </Button>
                </div>

                <div v-if="zernioEnabled && shopUrl" class="space-y-1.5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Mobile shop link (social)</p>
                    <div class="flex items-center gap-2 rounded-xl border bg-gray-50 p-2">
                        <Input :model-value="shopUrl" readonly class="h-8 text-xs" />
                        <Button
                            size="sm"
                            variant="outline"
                            @click="copyText(shopUrl, 'shop-link')"
                        >
                            <Copy class="mr-1 size-3.5" />
                            {{ copiedToken === 'shop-link' ? 'Copied' : 'Copy' }}
                        </Button>
                    </div>
                    <p class="text-[11px] text-gray-500">
                        Use this in Instagram/Facebook captions — opens full-screen vertical player with products and checkout.
                    </p>
                </div>

                <SocialPublishPanel
                    v-if="zernioEnabled"
                    :video-id="videoId"
                    :title="form.title"
                    :shop-url="shopUrl"
                />

                <div class="space-y-1.5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Share to social media (manual)</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <a
                            v-for="link in socialShareLinks(zernioEnabled && shopUrl ? shopUrl : shareUrl, form.title)"
                            :key="link.key"
                            :href="link.url"
                            target="_blank"
                            rel="noreferrer"
                            class="ghost-btn flex items-center justify-center rounded-xl px-3 py-2 text-sm font-semibold text-gray-600"
                        >
                            {{ link.label }}
                        </a>
                    </div>
                </div>
            </div>
        </ScrollableDialogContent>
    </Dialog>

    <!-- ═══════ Product modal ═══════ -->
    <Dialog v-model:open="productModalOpen">
        <DialogContent class="flex max-h-[min(90dvh,calc(100vh-2rem))] flex-col gap-0 overflow-hidden p-0 sm:max-w-[480px]">
            <DialogHeader class="shrink-0 border-b px-6 py-4">
                <DialogTitle class="flex items-center gap-2">
                    <Package class="size-4 text-[#E8563A]" />
                    Attach Products
                </DialogTitle>
                <DialogDescription>Click a product to attach or detach it from this video.</DialogDescription>
            </DialogHeader>
            <div class="shrink-0 border-b px-4 py-3">
                <div class="relative">
                    <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <Input v-model="productSearch" placeholder="Search products…" class="pl-9" />
                </div>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-3">
                <div v-if="filteredProducts.length === 0" class="py-8 text-center text-sm text-gray-500">
                    No products found.
                    <Link href="/products" class="mt-1 block font-semibold text-[#E8563A]">Add products →</Link>
                </div>
                <div v-else class="space-y-2">
                    <button
                        v-for="product in filteredProducts"
                        :key="product.id"
                        type="button"
                        :class="[
                            'w-full flex items-center gap-3 rounded-xl border p-3 text-left transition-all',
                            attachedProductIds.includes(product.id)
                                ? 'border-[#E8563A]/40 bg-[#E8563A]/5'
                                : 'border-gray-100 hover:bg-gray-50',
                        ]"
                        @click="toggleProductInModal(product.id)"
                    >
                        <div class="shrink-0">
                            <img v-if="product.image_url" :src="product.image_url" class="h-12 w-12 rounded-xl border object-cover">
                            <div v-else class="flex h-12 w-12 items-center justify-center rounded-xl border border-gray-200 bg-gray-100">
                                <ImageOff class="size-5 text-gray-400" />
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-800">{{ product.title }}</p>
                            <p v-if="product.description" class="mt-0.5 line-clamp-1 text-xs text-gray-500">{{ product.description }}</p>
                            <p class="mt-0.5 text-sm font-bold text-[#E8563A]">
                                {{ formatPrice(product.currency, product.sale_price || product.price) }}
                                <span v-if="product.sale_price" class="ml-1 text-xs font-normal text-gray-400 line-through">
                                    {{ formatPrice(product.currency, product.price) }}
                                </span>
                            </p>
                        </div>
                        <div :class="[
                            'flex size-6 shrink-0 items-center justify-center rounded-full border-2 transition-all',
                            attachedProductIds.includes(product.id)
                                ? 'border-[#E8563A] bg-[#E8563A] text-white'
                                : 'border-gray-300',
                        ]">
                            <Check v-if="attachedProductIds.includes(product.id)" class="size-3.5" />
                        </div>
                    </button>
                </div>
            </div>
            <DialogFooter class="shrink-0 border-t px-6 py-4">
                <p class="mr-auto text-sm text-gray-500">{{ attachedProductIds.length }} attached</p>
                <button type="button" class="cta-btn rounded-xl px-5 py-2 text-sm font-bold text-white" @click="productModalOpen = false">Done</button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.edit-root { background-color: #F2EFEA; }

/* Cards */
.section-card {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 16px rgba(0,0,0,0.06);
    overflow: hidden;
}
.section-card-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 20px;
    border-bottom: 1px solid #F0EDE8;
}
.section-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 9px;
    background: rgba(232,86,58,0.10);
    flex-shrink: 0;
}
.section-title {
    font-size: 14px;
    font-weight: 700;
    color: #111827;
}

/* Form elements */
.field-label {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
}
.field-input {
    border-color: #e5e7eb;
    background: #fafafa;
    border-radius: 10px;
}
.field-input:focus { border-color: #E8563A; box-shadow: 0 0 0 3px rgba(232,86,58,0.10); }
.field-textarea {
    width: 100%;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #fafafa;
    padding: 8px 12px;
    font-size: 13px;
    color: #111827;
    resize: vertical;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.field-textarea:focus { border-color: #E8563A; box-shadow: 0 0 0 3px rgba(232,86,58,0.10); background: #fff; }
.field-textarea::placeholder { color: #9ca3af; }

/* Buttons */
.cta-btn {
    background: #E8563A;
    box-shadow: 0 4px 14px rgba(232,86,58,0.30);
    transition: all 0.15s;
    border: none;
    cursor: pointer;
}
.cta-btn:hover:not(:disabled) {
    background: #D44A2F;
    box-shadow: 0 6px 18px rgba(232,86,58,0.40);
    transform: translateY(-1px);
}
.cta-btn:disabled { opacity: 0.55; cursor: not-allowed; }

.ghost-btn {
    background: #fff;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
}
.ghost-btn:hover:not(:disabled) { background: #f9fafb; border-color: #d1d5db; }
.ghost-btn:disabled { opacity: 0.55; cursor: not-allowed; }

/* Status badges */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 10px;
    border-radius: 9999px;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
}
.status-published { background: rgba(232,86,58,0.10); color: #E8563A; }
.status-processing { background: rgba(245,158,11,0.12); color: #d97706; }
.status-failed { background: rgba(239,68,68,0.10); color: #dc2626; }
.status-draft { background: #f3f4f6; color: #6b7280; }
</style>
