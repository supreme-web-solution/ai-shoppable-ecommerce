<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Clock,
    Copy,
    Film,
    Layers3,
    Link2,
    Loader2,
    Package,
    Pencil,
    PlusCircle,
    Search,
    Share2,
    Tag,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminApi } from '@/composables/useAdminApi';
import EmbedDisplaySelect from '@/components/embed/EmbedDisplaySelect.vue';
import {
    type EmbedDisplayType,
    type EmbedItem,
    embedPreviewUrl,
    embedScriptCode,
    ensureEmbedForVideo,
    socialShareLinks,
    updateEmbedDisplayType,
} from '@/lib/videoEmbed';

type VideoItem = {
    id: number;
    title: string;
    description?: string | null;
    source: string;
    status: string;
    visibility: string;
    playback_url?: string | null;
    thumbnail_url?: string | null;
    product_tags?: unknown[];
    published_at?: string | null;
};

type PlaylistItem = {
    id: number;
    title: string;
    slug: string;
    is_active: boolean;
    is_public: boolean;
    videos?: { id: number; title: string }[];
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Shoppable Videos', href: '/content' },
        ],
    },
});

const { getList, postJson, patchJson, deleteResource, ensureTeam } = useAdminApi();

const loading = ref(false);
const saving = ref(false);
const errorText = ref('');
const search = ref('');
const videos = ref<VideoItem[]>([]);
const playlists = ref<PlaylistItem[]>([]);
const shareModalOpen = ref(false);
const shareLoading = ref(false);
const shareTypeSaving = ref(false);
const activeShareVideo = ref<VideoItem | null>(null);
const activeShareUrl = ref('');
const activeEmbedCode = ref('');
const shareEmbed = ref<EmbedItem | null>(null);
const shareEmbedType = ref<EmbedDisplayType>('vertical_feed');
const copiedToken = ref('');

const shareApi = { getList, postJson, patchJson };

/* ── playlist modal state ── */
const playlistModalOpen = ref(false);
const playlistModalVideoId = ref<number | null>(null);
const playlistModalVideoTitle = ref('');
const pendingPlaylistIds = ref<number[]>([]);
const savingPlaylists = ref(false);

const filteredVideos = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return videos.value;
    return videos.value.filter(
        (v) => v.title.toLowerCase().includes(q) || v.source.toLowerCase().includes(q),
    );
});

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'published') return 'default';
    if (status === 'processing') return 'secondary';
    if (status === 'failed') return 'destructive';
    return 'outline';
}

function statusIcon(status: string) {
    if (status === 'published') return CheckCircle2;
    if (status === 'processing') return Loader2;
    if (status === 'failed') return XCircle;
    return Clock;
}

function sourceLabel(source: string) {
    if (source === 'ai_generated') return 'AI';
    if (source === 'live_replay') return 'Replay';
    return 'Upload';
}

function formatDate(iso: string | null | undefined) {
    if (!iso) return null;
    return new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
}

async function loadVideos() {
    loading.value = true;
    errorText.value = '';
    try {
        await ensureTeam();
        const payload = await getList<VideoItem>('/api/v1/admin/videos');
        videos.value = payload.data ?? [];
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load videos.';
    } finally {
        loading.value = false;
    }
}

async function loadPlaylists() {
    try {
        await ensureTeam();
        const payload = await getList<PlaylistItem>('/api/v1/admin/playlists');
        playlists.value = payload.data ?? [];
    } catch {
        playlists.value = [];
    }
}

async function publishVideo(video: VideoItem) {
    saving.value = true;
    try {
        await patchJson(`/api/v1/admin/videos/${video.id}`, {
            status: 'published',
            published_at: new Date().toISOString(),
        });
        await loadVideos();
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not publish.';
    } finally {
        saving.value = false;
    }
}

async function unpublishVideo(video: VideoItem) {
    saving.value = true;
    try {
        await patchJson(`/api/v1/admin/videos/${video.id}`, {
            status: 'ready',
            published_at: null,
        });
        await loadVideos();
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not unpublish.';
    } finally {
        saving.value = false;
    }
}

async function removeVideo(video: VideoItem) {
    if (!window.confirm(`Delete "${video.title}"?`)) return;
    try {
        await deleteResource(`/api/v1/admin/videos/${video.id}`);
        await loadVideos();
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not delete.';
    }
}

/* ── Add-to-Playlist modal ── */
function openPlaylistModal(video: VideoItem) {
    playlistModalVideoId.value = video.id;
    playlistModalVideoTitle.value = video.title;
    // pre-tick playlists that already contain this video
    pendingPlaylistIds.value = playlists.value
        .filter((pl) => pl.videos?.some((v) => v.id === video.id))
        .map((pl) => pl.id);
    playlistModalOpen.value = true;
}

function togglePlaylistPending(playlistId: number) {
    const idx = pendingPlaylistIds.value.indexOf(playlistId);
    if (idx === -1) pendingPlaylistIds.value.push(playlistId);
    else pendingPlaylistIds.value.splice(idx, 1);
}

async function saveVideoPlaylists() {
    if (!playlistModalVideoId.value) return;
    savingPlaylists.value = true;
    errorText.value = '';
    try {
        const videoId = playlistModalVideoId.value;

        // For each playlist: determine whether the video should be in it
        await Promise.all(
            playlists.value.map(async (pl) => {
                const currentIds = (pl.videos ?? []).map((v) => v.id);
                const shouldBeIn = pendingPlaylistIds.value.includes(pl.id);
                const isIn = currentIds.includes(videoId);

                if (shouldBeIn === isIn) return; // no change

                const newIds = shouldBeIn
                    ? [...currentIds, videoId]
                    : currentIds.filter((id) => id !== videoId);

                await patchJson(`/api/v1/admin/playlists/${pl.id}`, { video_ids: newIds });
            }),
        );

        // Reload playlists so membership state is fresh
        await loadPlaylists();
        playlistModalOpen.value = false;
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not update playlists.';
    } finally {
        savingPlaylists.value = false;
    }
}

async function copyText(text: string, token: string) {
    await navigator.clipboard.writeText(text);
    copiedToken.value = token;
    window.setTimeout(() => {
        if (copiedToken.value === token) copiedToken.value = '';
    }, 1800);
}

async function openShareModal(video: VideoItem) {
    shareLoading.value = true;
    errorText.value = '';
    activeShareVideo.value = video;
    shareModalOpen.value = true;
    try {
        const embed = await ensureEmbedForVideo(shareApi, video.id, video.title);
        if (!embed) throw new Error('Could not generate embed.');
        shareEmbed.value = embed;
        shareEmbedType.value = (embed.type as EmbedDisplayType) || 'vertical_feed';
        activeShareUrl.value = embedPreviewUrl(embed);
        activeEmbedCode.value = embedScriptCode(embed, shareEmbedType.value);
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not prepare share links.';
        activeShareUrl.value = '';
        activeEmbedCode.value = '';
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
            activeEmbedCode.value = embedScriptCode(updated, type);
        } else {
            activeEmbedCode.value = embedScriptCode(shareEmbed.value, type);
        }
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not update embed display.';
        shareEmbedType.value = (shareEmbed.value.type as EmbedDisplayType) || 'vertical_feed';
    } finally {
        shareTypeSaving.value = false;
    }
}

onMounted(() => Promise.all([loadVideos(), loadPlaylists()]));
</script>

<template>
    <Head title="Shoppable Videos" />

    <div class="page-root flex min-h-0 min-w-0 flex-1 flex-col gap-6 overflow-x-hidden p-4 md:p-6">

        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2.5">
                    <div class="page-icon flex size-9 items-center justify-center rounded-xl">
                        <Film class="size-5 text-white" />
                    </div>
                    <h1 class="text-2xl font-extrabold tracking-tight">Shoppable Videos</h1>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    Upload or generate videos and attach products for in-video purchasing.
                </p>
            </div>
            <Link href="/content/create" class="cta-btn flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-bold text-white">
                <PlusCircle class="size-4" />
                New Shoppable Video
            </Link>
        </div>

        <!-- Error -->
        <div
            v-if="errorText"
            class="flex items-center gap-2 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            <XCircle class="size-4 shrink-0" />
            {{ errorText }}
        </div>

        <!-- Search + refresh -->
        <div class="flex items-center gap-3">
            <div class="relative max-w-sm flex-1">
                <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="search" placeholder="Search videos…" class="rounded-xl pl-9" />
            </div>
            <button type="button" :disabled="loading" class="ghost-btn flex size-9 items-center justify-center rounded-xl" @click="loadVideos">
                <svg :class="['size-4 text-muted-foreground', loading && 'animate-spin']" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
            </button>
        </div>

        <!-- Skeleton -->
        <div v-if="loading" class="space-y-3">
            <Skeleton v-for="n in 4" :key="n" class="h-24 rounded-2xl" />
        </div>

        <!-- Empty state -->
        <div
            v-else-if="filteredVideos.length === 0"
            class="flex flex-col items-center justify-center gap-5 rounded-2xl border border-dashed bg-white py-16 text-center shadow-card"
        >
            <div class="page-icon flex size-14 items-center justify-center rounded-2xl">
                <Film class="size-7 text-white" />
            </div>
            <div>
                <p class="font-bold">No shoppable videos yet</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ search ? 'No videos match your search.' : 'Upload a video or generate one with AI, then attach products.' }}
                </p>
            </div>
            <Link v-if="!search" href="/content/create" class="cta-btn flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-bold text-white">
                <PlusCircle class="size-4" />
                Create your first shoppable video
            </Link>
        </div>

        <!-- Video list -->
        <div v-else class="min-w-0 space-y-3">
            <div
                v-for="video in filteredVideos"
                :key="video.id"
                class="overflow-hidden rounded-2xl bg-white shadow-card transition-shadow hover:shadow-md"
            >
                <div class="flex max-w-full flex-col gap-3 p-4">
                    <div class="flex w-full min-w-0 gap-3">
                        <!-- Thumbnail -->
                        <div class="shrink-0">
                            <img
                                v-if="video.thumbnail_url"
                                :src="video.thumbnail_url"
                                alt=""
                                class="h-16 w-12 rounded-xl object-cover"
                            >
                            <div v-else class="flex h-16 w-12 items-center justify-center rounded-xl bg-gray-100">
                                <Film class="size-5 text-gray-400" />
                            </div>
                        </div>

                        <!-- Info (w-0 + flex-1 lets truncate work inside flex rows) -->
                        <div class="video-title-block w-0 min-w-0 flex-1">
                            <p
                                class="video-title truncate text-sm font-bold text-foreground md:text-base"
                                :title="video.title"
                            >
                                {{ video.title }}
                            </p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                <span :class="['status-pill', video.status === 'published' ? 'status-published' : video.status === 'processing' ? 'status-processing' : video.status === 'failed' ? 'status-failed' : 'status-default']">
                                    <component :is="statusIcon(video.status)" class="size-3 shrink-0" />
                                    {{ video.status }}
                                </span>
                                <span class="tag-pill">{{ sourceLabel(video.source) }}</span>
                                <span v-if="video.product_tags?.length" class="tag-pill">
                                    <Tag class="size-3 shrink-0" />
                                    {{ video.product_tags.length }} product{{ video.product_tags.length !== 1 ? 's' : '' }}
                                </span>
                                <span v-if="video.published_at" class="text-xs text-muted-foreground">
                                    Published {{ formatDate(video.published_at) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Actions (own row so long titles never push buttons off-screen) -->
                    <div class="flex flex-wrap items-center gap-1.5 border-t border-gray-100 pt-3 pl-[60px]">
                        <Link :href="`/content/${video.id}/edit`" class="action-btn">
                            <Pencil class="size-3.5" />
                            Edit
                        </Link>
                        <button type="button" class="action-btn" @click="openPlaylistModal(video)">
                            <Layers3 class="size-3.5" />
                            Playlist
                        </button>
                        <Link :href="`/content/${video.id}/edit#products`" class="action-btn">
                            <Package class="size-3.5" />
                            Products
                        </Link>
                        <button type="button" class="action-btn" @click="openShareModal(video)">
                            <Link2 class="size-3.5" />
                            Embed
                        </button>
                        <button type="button" class="action-btn" @click="openShareModal(video)">
                            <Share2 class="size-3.5" />
                            Share
                        </button>

                        <button
                            v-if="video.status !== 'published'"
                            type="button"
                            class="publish-btn"
                            :disabled="saving || video.status === 'processing'"
                            @click="publishVideo(video)"
                        >
                            Publish
                        </button>
                        <button
                            v-else
                            type="button"
                            class="action-btn"
                            :disabled="saving"
                            @click="unpublishVideo(video)"
                        >
                            Unpublish
                        </button>

                        <button type="button" class="delete-btn" @click="removeVideo(video)">
                            <Trash2 class="size-4" />
                        </button>
                    </div>
                </div>

                <!-- Playlists membership -->
                <div
                    v-if="playlists.filter((pl) => pl.videos?.some((v) => v.id === video.id)).length"
                    class="border-t border-gray-100 bg-gray-50 px-4 py-2"
                >
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs text-muted-foreground">In playlists:</span>
                        <span
                            v-for="pl in playlists.filter((pl) => pl.videos?.some((v) => v.id === video.id))"
                            :key="pl.id"
                            class="tag-pill"
                        >
                            {{ pl.title }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ Add-to-Playlist modal ═══════ -->
    <Dialog v-model:open="playlistModalOpen">
        <DialogContent class="sm:max-w-[480px]">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Layers3 class="size-4 text-blue-500" />
                    Add to Playlist
                </DialogTitle>
                <DialogDescription>
                    Choose which playlists should include "{{ playlistModalVideoTitle }}".
                </DialogDescription>
            </DialogHeader>

            <div v-if="playlists.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                No playlists yet.
                <Link href="/playlists" class="mt-1 block text-primary underline">
                    Create a playlist first →
                </Link>
            </div>

            <div v-else class="space-y-2">
                <button
                    v-for="pl in playlists"
                    :key="pl.id"
                    type="button"
                    :class="[
                        'w-full flex items-center justify-between gap-3 rounded-xl border p-3 text-left transition-colors',
                        pendingPlaylistIds.includes(pl.id)
                            ? 'border-primary/60 bg-primary/5'
                            : 'hover:bg-muted/50',
                    ]"
                    @click="togglePlaylistPending(pl.id)"
                >
                    <div>
                        <p class="text-sm font-medium">{{ pl.title }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ pl.videos?.length ?? 0 }} video{{ (pl.videos?.length ?? 0) !== 1 ? 's' : '' }} ·
                            {{ pl.is_public ? 'Public' : 'Private' }}
                        </p>
                    </div>
                    <div :class="[
                        'flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition-all',
                        pendingPlaylistIds.includes(pl.id)
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-muted-foreground/40',
                    ]">
                        <CheckCircle2
                            v-if="pendingPlaylistIds.includes(pl.id)"
                            class="size-3"
                        />
                    </div>
                </button>
            </div>

            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="playlistModalOpen = false">Cancel</Button>
                <Button :disabled="savingPlaylists || playlists.length === 0" @click="saveVideoPlaylists">
                    <Loader2 v-if="savingPlaylists" class="mr-2 size-4 animate-spin" />
                    {{ savingPlaylists ? 'Saving…' : 'Save' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- ═══════ Share & Embed modal ═══════ -->
    <Dialog v-model:open="shareModalOpen">
        <DialogContent class="sm:max-w-[560px]">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Share2 class="size-4 text-[#E8563A]" />
                    Share & Embed
                </DialogTitle>
                <DialogDescription>
                    {{ activeShareVideo?.title || 'Video' }} — CDN embed + social share links.
                </DialogDescription>
            </DialogHeader>

            <div v-if="shareLoading" class="space-y-2 py-4">
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
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Preview link</p>
                    <div class="flex items-center gap-2 rounded-xl border bg-muted/30 p-2">
                        <Input :model-value="activeShareUrl" readonly class="h-8 text-xs" />
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="!activeShareUrl"
                            @click="copyText(activeShareUrl, 'share-link')"
                        >
                            <Copy class="mr-1 size-3.5" />
                            {{ copiedToken === 'share-link' ? 'Copied' : 'Copy' }}
                        </Button>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">CDN embed code</p>
                    <div class="rounded-xl border bg-muted/30 p-2">
                        <pre class="max-h-28 overflow-auto whitespace-pre-wrap text-[11px]">{{ activeEmbedCode }}</pre>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="!activeEmbedCode"
                        @click="copyText(activeEmbedCode, 'embed-code')"
                    >
                        <Copy class="mr-1 size-3.5" />
                        {{ copiedToken === 'embed-code' ? 'Copied' : 'Copy embed code' }}
                    </Button>
                </div>

                <div class="space-y-1.5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Share to social media</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <a
                            v-for="link in socialShareLinks(activeShareUrl, activeShareVideo?.title || '')"
                            :key="link.key"
                            :href="link.url"
                            target="_blank"
                            rel="noreferrer"
                            class="action-btn justify-center"
                        >
                            {{ link.label }}
                        </a>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.page-root { background-color: #F2EFEA; }
.page-icon { background: linear-gradient(135deg, #E8563A, #ff8c42); box-shadow: 0 4px 12px rgba(232,86,58,0.35); }
.cta-btn { background: #E8563A; box-shadow: 0 4px 20px rgba(232,86,58,0.35); transition: all 0.2s; }
.cta-btn:hover { background: #D44A2F; box-shadow: 0 8px 28px rgba(232,86,58,0.45); transform: translateY(-1px); }
.ghost-btn { background: #fff; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.04); cursor: pointer; }
.ghost-btn:hover { background: #f9fafb; }
.shadow-card { box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 16px rgba(0,0,0,0.06); }
.action-btn { display: inline-flex; align-items: center; gap: 4px; padding: 5px 12px; border-radius: 9999px; border: 1px solid #e5e7eb; background: #fff; font-size: 12px; font-weight: 600; color: #374151; text-decoration: none; cursor: pointer; transition: all 0.15s; }
.action-btn:hover { border-color: #E8563A; color: #E8563A; background: rgba(232,86,58,0.04); }
.publish-btn { display: inline-flex; align-items: center; padding: 5px 14px; border-radius: 9999px; background: #E8563A; color: #fff; font-size: 12px; font-weight: 700; border: none; cursor: pointer; box-shadow: 0 2px 8px rgba(232,86,58,0.35); transition: all 0.15s; }
.publish-btn:hover { background: #D44A2F; }
.publish-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.delete-btn { display: inline-flex; align-items: center; padding: 5px 8px; border-radius: 9999px; background: transparent; color: #9ca3af; border: 1px solid transparent; font-size: 12px; cursor: pointer; transition: all 0.15s; }
.delete-btn:hover { border-color: #fecaca; background: #fef2f2; color: #ef4444; }
.status-pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; }
.status-published { background: rgba(16, 185, 129, 0.12); color: #059669; }
.status-processing { background: rgba(245,158,11,0.1); color: #d97706; }
.status-failed { background: rgba(239,68,68,0.1); color: #dc2626; }
.status-default { background: #f3f4f6; color: #6b7280; }
.tag-pill { display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 9999px; background: #f3f4f6; font-size: 11px; font-weight: 500; color: #6b7280; }
.video-title-block { overflow: hidden; max-width: 80%; }
.video-title { display: block; max-width: 100%; }
</style>
