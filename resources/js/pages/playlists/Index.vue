<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Code2,
    Film,
    Globe,
    Layers3,
    Link2,
    Loader2,
    Lock,
    PlusCircle,
    Search,
    Trash2,
    Video,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
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
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import EmbedDisplaySelect from '@/components/embed/EmbedDisplaySelect.vue';
import { useAdminApi } from '@/composables/useAdminApi';
import {
    type EmbedDisplayType,
    type EmbedItem,
    embedDisplayLabel,
    embedScriptCode,
    ensureEmbedForPlaylist,
    findEmbedForPlaylist,
    normalizeEmbedDisplayType,
    replaceEmbedInList,
    updateEmbedDisplayType,
} from '@/lib/videoEmbed';

type VideoOption = {
    id: number;
    title: string;
    thumbnail_url?: string | null;
    status?: string;
};

type PlaylistSettings = {
    auto_advance_enabled?: boolean;
    loops_per_video?: number;
};

type PlaylistItem = {
    id: number;
    title: string;
    slug: string;
    description?: string | null;
    is_active: boolean;
    is_public: boolean;
    settings?: PlaylistSettings | null;
    videos?: VideoOption[];
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Playlists', href: '/playlists' },
        ],
    },
});

const { getList, postJson, patchJson, deleteResource, ensureTeam } = useAdminApi();

const loading = ref(false);
const saving = ref(false);
const copiedToken = ref('');
const errorText = ref('');
const search = ref('');
const playlists = ref<PlaylistItem[]>([]);
const videos = ref<VideoOption[]>([]);
const embeds = ref<EmbedItem[]>([]);
const embedTypeSavingId = ref<number | null>(null);

const embedApi = { getList, postJson, patchJson };

const PLAYLISTS_PER_PAGE = 12;
const playlistPage = ref(1);
const playlistMeta = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
});
let playlistSearchTimer: number | null = null;

/* ── create modal ── */
const createModalOpen = ref(false);
const createForm = ref({
    title: '',
    slug: '',
    description: '',
    video_ids: [] as number[],
});
const createVideoSearch = ref('');

/* ── manage content modal ── */
const contentModalOpen = ref(false);
const contentModalPlaylist = ref<PlaylistItem | null>(null);
const contentVideoIds = ref<number[]>([]);
const contentVideoSearch = ref('');
const playbackAutoAdvance = ref(false);
const playbackLoopsPerVideo = ref(1);

const playlistTotalPages = computed(() =>
    Math.max(1, playlistMeta.value.last_page),
);

const playlistRangeStart = computed(() => {
    if (playlistMeta.value.total === 0) {
        return 0;
    }

    return (playlistMeta.value.current_page - 1) * PLAYLISTS_PER_PAGE + 1;
});

const playlistRangeEnd = computed(() =>
    Math.min(playlistMeta.value.current_page * PLAYLISTS_PER_PAGE, playlistMeta.value.total),
);

const filteredVideosForCreate = computed(() => {
    const q = createVideoSearch.value.trim().toLowerCase();
    if (!q) return videos.value;
    return videos.value.filter((v) => v.title.toLowerCase().includes(q));
});

const filteredVideosForContent = computed(() => {
    const q = contentVideoSearch.value.trim().toLowerCase();
    if (!q) return videos.value;
    return videos.value.filter((v) => v.title.toLowerCase().includes(q));
});

const publicCountOnPage = computed(() => playlists.value.filter((p) => p.is_public).length);
const videosOnPage = computed(() =>
    playlists.value.reduce((sum, p) => sum + (p.videos?.length ?? 0), 0),
);

function slugify(value: string): string {
    return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

function unwrapResource<T extends { id: number }>(payload: unknown): T | null {
    if (payload && typeof payload === 'object' && 'data' in payload) {
        const data = (payload as { data?: unknown }).data;
        if (data && typeof data === 'object' && 'id' in data) return data as T;
    }
    if (payload && typeof payload === 'object' && 'id' in payload) return payload as T;
    return null;
}

function embedForPlaylist(playlistId: number): EmbedItem | undefined {
    return embeds.value.find((e) => e.playlist_id === playlistId);
}

async function resolvePlaylistEmbed(playlist: PlaylistItem): Promise<EmbedItem | null> {
    const cached = embedForPlaylist(playlist.id);
    if (cached) {
        return cached;
    }

    const fromApi = await findEmbedForPlaylist(embedApi, playlist.id);
    if (fromApi) {
        embeds.value = replaceEmbedInList(embeds.value, fromApi);

        return fromApi;
    }

    const created = await ensureEmbedForPlaylist(
        embedApi,
        playlist.id,
        playlist.title,
        playlist.slug || playlist.title,
    );

    if (created) {
        embeds.value = replaceEmbedInList(embeds.value, created);
    }

    return created;
}

function playlistEmbedType(playlistId: number): EmbedDisplayType {
    return normalizeEmbedDisplayType(embedForPlaylist(playlistId)?.type);
}

async function changePlaylistEmbedType(
    playlist: PlaylistItem,
    type: EmbedDisplayType,
) {
    embedTypeSavingId.value = playlist.id;
    errorText.value = '';

    let previous: EmbedItem | null = null;

    try {
        const embed = await resolvePlaylistEmbed(playlist);
        if (!embed) {
            throw new Error('Could not load embed.');
        }

        previous = { ...embed };
        embeds.value = replaceEmbedInList(embeds.value, {
            ...embed,
            type,
        });

        const updated = await updateEmbedDisplayType(embedApi, embed.id, type);
        if (updated) {
            embeds.value = replaceEmbedInList(embeds.value, updated);
        }
    } catch (err) {
        if (previous) {
            embeds.value = replaceEmbedInList(embeds.value, previous);
        }
        errorText.value = err instanceof Error ? err.message : 'Could not update embed display.';
    } finally {
        embedTypeSavingId.value = null;
    }
}

async function loadPlaylists(page = playlistPage.value) {
    const playlistPayload = await getList<PlaylistItem>('/api/v1/admin/playlists', {
        per_page: PLAYLISTS_PER_PAGE,
        page,
        ...(search.value.trim() ? { search: search.value.trim() } : {}),
    });

    playlists.value = playlistPayload.data ?? [];
    playlistMeta.value = {
        current_page: playlistPayload.meta?.current_page ?? page,
        last_page: playlistPayload.meta?.last_page ?? 1,
        total: playlistPayload.meta?.total ?? playlists.value.length,
    };
    playlistPage.value = playlistMeta.value.current_page;
}

async function loadData() {
    loading.value = true;
    errorText.value = '';
    try {
        await ensureTeam();
        const [videoPayload, embedPayload] = await Promise.all([
            getList<VideoOption>('/api/v1/admin/videos'),
            getList<EmbedItem>('/api/v1/admin/embeds', { per_page: 200 }),
        ]);
        videos.value = videoPayload.data ?? [];
        embeds.value = embedPayload.data ?? [];
        await loadPlaylists(playlistPage.value);
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load playlists.';
    } finally {
        loading.value = false;
    }
}

function goToPlaylistPage(page: number) {
    const next = Math.max(1, Math.min(page, playlistTotalPages.value));
    if (next === playlistPage.value) {
        return;
    }

    playlistPage.value = next;
    loading.value = true;
    errorText.value = '';
    loadPlaylists(next)
        .catch((err) => {
            errorText.value = err instanceof Error ? err.message : 'Could not load playlists.';
        })
        .finally(() => {
            loading.value = false;
        });
}

watch(search, () => {
    if (playlistSearchTimer !== null) {
        window.clearTimeout(playlistSearchTimer);
    }

    playlistSearchTimer = window.setTimeout(() => {
        playlistPage.value = 1;
        loading.value = true;
        loadPlaylists(1)
            .catch((err) => {
                errorText.value = err instanceof Error ? err.message : 'Could not load playlists.';
            })
            .finally(() => {
                loading.value = false;
            });
    }, 300);
});

function openCreateModal() {
    createForm.value = { title: '', slug: '', description: '', video_ids: [] };
    createVideoSearch.value = '';
    createModalOpen.value = true;
}

function toggleCreateVideo(videoId: number) {
    const idx = createForm.value.video_ids.indexOf(videoId);
    if (idx === -1) createForm.value.video_ids.push(videoId);
    else createForm.value.video_ids.splice(idx, 1);
}

async function createPlaylist() {
    if (!createForm.value.title.trim()) return;

    saving.value = true;
    errorText.value = '';
    try {
        const payload = await postJson('/api/v1/admin/playlists', {
            title: createForm.value.title.trim(),
            slug: createForm.value.slug || slugify(createForm.value.title),
            description: createForm.value.description.trim() || null,
            is_active: true,
            is_public: true,
            video_ids: createForm.value.video_ids,
        });

        const created = unwrapResource<PlaylistItem>(payload);
        if (created) await resolvePlaylistEmbed(created);

        createModalOpen.value = false;
        playlistPage.value = 1;
        await loadData();
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not create playlist.';
    } finally {
        saving.value = false;
    }
}

function openContentModal(playlist: PlaylistItem) {
    contentModalPlaylist.value = playlist;
    contentVideoIds.value = (playlist.videos ?? []).map((v) => v.id);
    contentVideoSearch.value = '';
    playbackAutoAdvance.value = Boolean(playlist.settings?.auto_advance_enabled);
    playbackLoopsPerVideo.value = Math.min(
        20,
        Math.max(1, Number(playlist.settings?.loops_per_video ?? 1)),
    );
    contentModalOpen.value = true;
}

function playlistAutoAdvanceEnabled(playlist: PlaylistItem): boolean {
    return Boolean(playlist.settings?.auto_advance_enabled);
}

function toggleContentVideo(videoId: number) {
    const idx = contentVideoIds.value.indexOf(videoId);
    if (idx === -1) contentVideoIds.value.push(videoId);
    else contentVideoIds.value.splice(idx, 1);
}

async function savePlaylistContent() {
    if (!contentModalPlaylist.value) return;

    saving.value = true;
    errorText.value = '';
    try {
        const existingSettings = contentModalPlaylist.value.settings ?? {};

        await patchJson(`/api/v1/admin/playlists/${contentModalPlaylist.value.id}`, {
            video_ids: contentVideoIds.value.map(Number),
            settings: {
                ...existingSettings,
                auto_advance_enabled: playbackAutoAdvance.value,
                loops_per_video: playbackAutoAdvance.value
                    ? Math.min(20, Math.max(1, playbackLoopsPerVideo.value))
                    : 1,
            },
        });
        contentModalOpen.value = false;
        contentModalPlaylist.value = null;
        await loadData();
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not update playlist content.';
    } finally {
        saving.value = false;
    }
}

async function removePlaylist(playlist: PlaylistItem) {
    if (!window.confirm(`Delete playlist "${playlist.title}"?`)) return;
    try {
        await deleteResource(`/api/v1/admin/playlists/${playlist.id}`);
        await loadData();
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not delete playlist.';
    }
}

async function togglePublic(playlist: PlaylistItem) {
    try {
        await patchJson(`/api/v1/admin/playlists/${playlist.id}`, {
            is_public: !playlist.is_public,
        });
        await loadData();
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not update visibility.';
    }
}

async function copyText(value: string, token: string) {
    await navigator.clipboard.writeText(value);
    copiedToken.value = token;
    window.setTimeout(() => {
        if (copiedToken.value === token) copiedToken.value = '';
    }, 2000);
}

async function copyEmbedLink(playlist: PlaylistItem) {
    errorText.value = '';
    try {
        const embed = await resolvePlaylistEmbed(playlist);
        if (!embed) throw new Error('Could not generate embed.');
        const url = embed.embed_url || `${window.location.origin}/embed/${embed.slug}`;
        await copyText(url, `link-${embed.id}`);
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not copy embed link.';
    }
}

async function copyEmbedCode(playlist: PlaylistItem) {
    errorText.value = '';
    try {
        const embed = await resolvePlaylistEmbed(playlist);
        if (!embed) throw new Error('Could not generate embed.');
        await copyText(embedScriptCode(embed), `code-${embed.id}`);
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not copy embed code.';
    }
}

onMounted(loadData);
</script>

<template>
    <Head title="Playlists" />

    <div class="playlists-root flex min-h-screen flex-1 flex-col gap-5 p-4 md:p-5">

        <!-- Header -->
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="flex items-center gap-2.5">
                    <div class="page-icon flex size-9 items-center justify-center rounded-xl">
                        <Layers3 class="size-5 text-white" />
                    </div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900">Playlists</h1>
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    Group shoppable videos into feeds · copy an embed link to put them anywhere.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    :disabled="loading"
                    class="ghost-icon flex size-9 items-center justify-center rounded-xl text-gray-500 transition-colors disabled:opacity-50"
                    :title="loading ? 'Loading…' : 'Refresh'"
                    @click="loadData"
                >
                    <svg :class="['size-4', loading && 'animate-spin']" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                </button>
                <Button class="cta-btn" @click="openCreateModal">
                    <PlusCircle class="mr-1.5 size-4" />
                    New playlist
                </Button>
            </div>
        </div>

        <!-- Error -->
        <div
            v-if="errorText"
            class="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            <XCircle class="size-4 shrink-0" />
            {{ errorText }}
        </div>

        <!-- Stats row -->
        <div v-if="!loading && playlistMeta.total > 0" class="grid gap-3 sm:grid-cols-3">
            <div class="stat-card flex items-center gap-4 rounded-2xl p-4">
                <div class="stat-icon flex size-10 items-center justify-center rounded-xl">
                    <Layers3 class="size-5 text-[#E8563A]" />
                </div>
                <div>
                    <p class="text-2xl font-black leading-none text-gray-900">{{ playlistMeta.total }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">Total playlists</p>
                </div>
            </div>
            <div class="stat-card flex items-center gap-4 rounded-2xl p-4">
                <div class="stat-icon flex size-10 items-center justify-center rounded-xl">
                    <Globe class="size-5 text-[#E8563A]" />
                </div>
                <div>
                    <p class="text-2xl font-black leading-none text-gray-900">{{ publicCountOnPage }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">Public on this page</p>
                </div>
            </div>
            <div class="stat-card flex items-center gap-4 rounded-2xl p-4">
                <div class="stat-icon flex size-10 items-center justify-center rounded-xl">
                    <Film class="size-5 text-[#E8563A]" />
                </div>
                <div>
                    <p class="text-2xl font-black leading-none text-gray-900">{{ videosOnPage }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">Videos on this page</p>
                </div>
            </div>
        </div>

        <!-- Search -->
        <div class="relative max-w-sm">
            <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
            <Input v-model="search" placeholder="Search playlists…" class="search-input pl-9" />
        </div>

        <!-- Loading skeletons -->
        <div v-if="loading" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Skeleton v-for="n in PLAYLISTS_PER_PAGE" :key="n" class="h-64 rounded-2xl" />
        </div>

        <!-- Empty -->
        <div
            v-else-if="playlists.length === 0"
            class="empty-card flex flex-col items-center justify-center gap-5 rounded-2xl border border-dashed py-20 text-center"
        >
            <div class="stat-icon flex size-16 items-center justify-center rounded-2xl">
                <Layers3 class="size-8 text-[#E8563A]" />
            </div>
            <div>
                <p class="text-base font-bold text-gray-900">{{ search ? 'No playlists match your search' : 'No playlists yet' }}</p>
                <p class="mt-1 text-sm text-gray-500">
                    Create a playlist to group videos and generate an embed feed for your site.
                </p>
            </div>
            <Button v-if="!search" class="cta-btn" @click="openCreateModal">
                <PlusCircle class="mr-1.5 size-4" />
                Create your first playlist
            </Button>
        </div>

        <!-- Playlist grid -->
        <div v-else class="space-y-5">
            <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="playlist in playlists"
                :key="playlist.id"
                class="playlist-card group flex flex-col overflow-hidden rounded-2xl transition-all hover:-translate-y-0.5"
            >
                <!-- Top accent + title -->
                <div class="relative border-b border-[#F0EDE8] p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="playlist-icon flex size-11 shrink-0 items-center justify-center rounded-xl">
                                <Layers3 class="size-5 text-[#E8563A]" />
                            </div>
                            <div class="min-w-0">
                                <p class="truncate font-bold leading-tight text-gray-900">{{ playlist.title }}</p>
                                <p class="mt-0.5 truncate text-xs text-gray-500">/{{ playlist.slug }}</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            :title="playlist.is_public ? 'Public — click to make private' : 'Private — click to make public'"
                            :class="[
                                'flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold transition-colors',
                                playlist.is_public
                                    ? 'bg-[#E8563A]/10 text-[#E8563A] hover:bg-[#E8563A]/15'
                                    : 'bg-gray-100 text-gray-500 hover:bg-gray-200',
                            ]"
                            @click="togglePublic(playlist)"
                        >
                            <Globe v-if="playlist.is_public" class="size-3" />
                            <Lock v-else class="size-3" />
                            {{ playlist.is_public ? 'Public' : 'Private' }}
                        </button>
                    </div>
                    <p v-if="playlist.description" class="mt-3 line-clamp-2 text-sm text-gray-500">
                        {{ playlist.description }}
                    </p>
                </div>

                <!-- Video preview strip -->
                <div class="flex-1 p-4">
                    <div v-if="(playlist.videos?.length ?? 0) > 0" class="flex gap-1.5 overflow-x-auto pb-1 scrollbar-none">
                        <div
                            v-for="video in (playlist.videos ?? []).slice(0, 6)"
                            :key="video.id"
                            class="relative h-16 w-11 shrink-0 overflow-hidden rounded-lg border border-gray-100 bg-gray-100 shadow-sm"
                        >
                            <img
                                v-if="video.thumbnail_url"
                                :src="video.thumbnail_url"
                                alt=""
                                class="h-full w-full object-cover"
                            >
                            <div v-else class="flex h-full w-full items-center justify-center">
                                <Film class="size-4 text-gray-400" />
                            </div>
                        </div>
                        <div
                            v-if="(playlist.videos?.length ?? 0) > 6"
                            class="flex h-16 w-11 shrink-0 items-center justify-center rounded-lg border border-gray-100 bg-gray-100 text-xs font-medium text-gray-500"
                        >
                            +{{ (playlist.videos?.length ?? 0) - 6 }}
                        </div>
                    </div>
                    <div v-else class="flex items-center justify-center rounded-xl border border-dashed border-gray-200 bg-gray-50 py-5 text-xs text-gray-500">
                        <Film class="mr-1.5 size-3.5" />
                        No videos yet
                    </div>

                    <div class="mt-2.5 flex flex-wrap items-center gap-2">
                        <div class="flex items-center gap-1 text-xs text-gray-500">
                            <Film class="size-3.5" />
                            {{ playlist.videos?.length ?? 0 }} video{{ (playlist.videos?.length ?? 0) !== 1 ? 's' : '' }}
                        </div>
                        <span
                            v-if="playlistAutoAdvanceEnabled(playlist)"
                            class="rounded-full bg-[#E8563A]/10 px-2 py-0.5 text-[10px] font-semibold text-[#E8563A]"
                        >
                            Auto-advance · {{ playlist.settings?.loops_per_video ?? 1 }}× each
                        </span>
                        <div v-if="embedForPlaylist(playlist.id)" class="flex min-w-0 flex-wrap items-center gap-2 text-xs text-gray-500">
                            <Link2 class="size-3.5 shrink-0" />
                            <span class="truncate">/embed/{{ embedForPlaylist(playlist.id)?.slug }}</span>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 font-semibold text-gray-600">
                                {{ embedDisplayLabel(embedForPlaylist(playlist.id)?.type) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Action bar -->
                <div class="border-t border-[#F0EDE8] bg-[#FAF8F5] px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Manage videos -->
                        <button
                            type="button"
                            class="action-btn flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold"
                            @click="openContentModal(playlist)"
                        >
                            <Film class="size-3.5 text-[#E8563A]" />
                            Manage videos
                        </button>

                        <EmbedDisplaySelect
                            :model-value="playlistEmbedType(playlist.id)"
                            compact
                            label="Embed display"
                            :disabled="embedTypeSavingId === playlist.id"
                            @update:model-value="(type) => changePlaylistEmbedType(playlist, type)"
                        />

                        <!-- Copy link -->
                        <button
                            type="button"
                            :class="[
                                'flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors',
                                embedForPlaylist(playlist.id) && copiedToken === `link-${embedForPlaylist(playlist.id)?.id}`
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700'
                                    : 'bg-white text-gray-600 hover:border-[#E8563A]/40 hover:text-[#E8563A]',
                            ]"
                            @click="copyEmbedLink(playlist)"
                        >
                            <template v-if="embedForPlaylist(playlist.id) && copiedToken === `link-${embedForPlaylist(playlist.id)?.id}`">
                                <Check class="size-3.5" />
                                Copied!
                            </template>
                            <template v-else>
                                <Link2 class="size-3.5 text-[#E8563A]" />
                                Copy link
                            </template>
                        </button>

                        <!-- Copy embed code -->
                        <button
                            type="button"
                            :class="[
                                'flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors',
                                embedForPlaylist(playlist.id) && copiedToken === `code-${embedForPlaylist(playlist.id)?.id}`
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700'
                                    : 'bg-white text-gray-600 hover:border-[#E8563A]/40 hover:text-[#E8563A]',
                            ]"
                            @click="copyEmbedCode(playlist)"
                        >
                            <template v-if="embedForPlaylist(playlist.id) && copiedToken === `code-${embedForPlaylist(playlist.id)?.id}`">
                                <Check class="size-3.5" />
                                Copied!
                            </template>
                            <template v-else>
                                <Code2 class="size-3.5 text-[#E8563A]" />
                                Embed code
                            </template>
                        </button>

                        <!-- Delete -->
                        <button
                            type="button"
                            class="ml-auto flex size-7 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-red-50 hover:text-red-500"
                            title="Delete playlist"
                            @click="removePlaylist(playlist)"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>
            </div>

            <div
                v-if="playlistTotalPages > 1"
                class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[#F0EDE8] bg-white px-4 py-3 shadow-sm"
            >
                <p class="text-sm text-gray-600">
                    Showing
                    <span class="font-semibold text-gray-900">{{ playlistRangeStart }}–{{ playlistRangeEnd }}</span>
                    of
                    <span class="font-semibold text-gray-900">{{ playlistMeta.total }}</span>
                </p>
                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                        :disabled="playlistPage <= 1 || loading"
                        @click="goToPlaylistPage(playlistPage - 1)"
                    >
                        <ChevronLeft class="mr-1 size-4" />
                        Previous
                    </Button>
                    <span class="min-w-[5.5rem] text-center text-xs font-medium text-gray-500">
                        Page {{ playlistPage }} / {{ playlistTotalPages }}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                        :disabled="playlistPage >= playlistTotalPages || loading"
                        @click="goToPlaylistPage(playlistPage + 1)"
                    >
                        Next
                        <ChevronRight class="ml-1 size-4" />
                    </Button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ Create playlist modal ═══════ -->
    <Dialog v-model:open="createModalOpen">
        <DialogContent class="flex max-h-[90vh] flex-col gap-0 p-0 sm:max-w-[560px]">
            <DialogHeader class="shrink-0 border-b px-6 py-5">
                <DialogTitle class="flex items-center gap-2.5">
                    <div class="stat-icon flex size-8 items-center justify-center rounded-lg">
                        <Layers3 class="size-4 text-[#E8563A]" />
                    </div>
                    Create playlist
                </DialogTitle>
                <DialogDescription>
                    Group shoppable videos into a feed. An embed link is generated automatically.
                </DialogDescription>
            </DialogHeader>

            <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                <div class="space-y-1.5">
                    <Label for="pl-title">Playlist name <span class="text-destructive">*</span></Label>
                    <Input
                        id="pl-title"
                        v-model="createForm.title"
                        placeholder="Summer Collection"
                        class="search-input"
                        @input="!createForm.slug && (createForm.slug = slugify(createForm.title))"
                    />
                </div>

                <div class="space-y-1.5">
                    <Label for="pl-slug">
                        URL slug
                        <span class="ml-1 text-xs font-normal text-muted-foreground">· auto-generated</span>
                    </Label>
                    <Input
                        id="pl-slug"
                        v-model="createForm.slug"
                        :placeholder="slugify(createForm.title || 'summer-collection')"
                        class="search-input font-mono text-sm"
                    />
                </div>

                <div class="space-y-1.5">
                    <Label for="pl-desc">Description <span class="text-xs font-normal text-muted-foreground">· optional</span></Label>
                    <textarea
                        id="pl-desc"
                        v-model="createForm.description"
                        rows="2"
                        class="field-textarea w-full resize-none rounded-xl border px-3 py-2.5 text-sm placeholder:text-muted-foreground focus-visible:outline-none"
                        placeholder="What is this playlist about?"
                    />
                </div>

                <div class="space-y-2">
                    <Label>Add videos</Label>
                    <div class="relative">
                        <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                        <Input v-model="createVideoSearch" placeholder="Search videos…" class="search-input pl-9" />
                    </div>

                    <div v-if="videos.length === 0" class="rounded-xl border border-dashed border-gray-200 bg-gray-50 py-6 text-center text-sm text-gray-500">
                        No videos yet.
                        <Link href="/content/create" class="mt-1 block font-semibold text-[#E8563A] hover:underline">
                            Create shoppable video →
                        </Link>
                    </div>

                    <div v-else class="max-h-52 space-y-1 overflow-y-auto rounded-xl border border-gray-100 bg-gray-50 p-1.5">
                        <button
                            v-for="video in filteredVideosForCreate"
                            :key="video.id"
                            type="button"
                            :class="[
                                'flex w-full items-center gap-3 rounded-lg p-2.5 text-left transition-colors',
                                createForm.video_ids.includes(video.id)
                                    ? 'bg-[#E8563A]/8 ring-1 ring-[#E8563A]/30'
                                    : 'hover:bg-white',
                            ]"
                            @click="toggleCreateVideo(video.id)"
                        >
                            <div class="flex h-10 w-8 shrink-0 overflow-hidden rounded-lg border bg-muted">
                                <img v-if="video.thumbnail_url" :src="video.thumbnail_url" class="h-full w-full object-cover">
                                <Film v-else class="m-auto size-4 text-muted-foreground" />
                            </div>
                            <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ video.title }}</span>
                            <div
                                :class="[
                                    'flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors',
                                    createForm.video_ids.includes(video.id)
                                        ? 'border-[#E8563A] bg-[#E8563A] text-white'
                                        : 'border-gray-300',
                                ]"
                            >
                                <Check v-if="createForm.video_ids.includes(video.id)" class="size-3" />
                            </div>
                        </button>
                    </div>

                    <p v-if="createForm.video_ids.length" class="text-xs text-muted-foreground">
                        {{ createForm.video_ids.length }} video{{ createForm.video_ids.length !== 1 ? 's' : '' }} selected
                    </p>
                </div>
            </div>

            <DialogFooter class="shrink-0 border-t px-6 py-4">
                <Button variant="ghost" @click="createModalOpen = false">Cancel</Button>
                <Button class="cta-btn" :disabled="saving || !createForm.title.trim()" @click="createPlaylist">
                    <Loader2 v-if="saving" class="mr-2 size-4 animate-spin" />
                    {{ saving ? 'Creating…' : 'Create playlist' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- ═══════ Manage videos modal ═══════ -->
    <Dialog v-model:open="contentModalOpen">
        <DialogContent class="flex max-h-[90vh] flex-col gap-0 p-0 sm:max-w-[560px]">
            <DialogHeader class="shrink-0 border-b px-6 py-5">
                <DialogTitle class="flex items-center gap-2.5">
                    <div class="stat-icon flex size-8 items-center justify-center rounded-lg">
                        <Film class="size-4 text-[#E8563A]" />
                    </div>
                    Manage videos
                </DialogTitle>
                <DialogDescription v-if="contentModalPlaylist">
                    Choose which videos appear in <strong>{{ contentModalPlaylist.title }}</strong>.
                </DialogDescription>
            </DialogHeader>

            <div class="shrink-0 border-b px-4 py-3">
                <div class="relative">
                    <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <Input v-model="contentVideoSearch" placeholder="Search videos…" class="search-input pl-9" />
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-4 py-3">
                <div class="mb-4 space-y-3 rounded-xl border border-[#F0EDE8] bg-[#FAF8F5] p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="space-y-1">
                            <p class="text-sm font-semibold text-gray-900">Embed auto-advance</p>
                            <p class="text-xs text-muted-foreground">
                                When enabled, the embed feed plays each video a set number of times, then scrolls to the
                                next. At the end of the playlist it starts again from the first video.
                            </p>
                        </div>
                        <label class="relative mt-0.5 inline-flex shrink-0 cursor-pointer items-center">
                            <input v-model="playbackAutoAdvance" type="checkbox" class="peer sr-only" />
                            <div class="h-6 w-11 rounded-full bg-muted transition-colors peer-checked:bg-[#E8563A] after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-all peer-checked:after:translate-x-5" />
                        </label>
                    </div>
                    <div v-if="playbackAutoAdvance" class="space-y-1.5">
                        <Label for="loops-per-video">Plays per video before next</Label>
                        <Input
                            id="loops-per-video"
                            v-model.number="playbackLoopsPerVideo"
                            type="number"
                            min="1"
                            max="20"
                            class="search-input max-w-[120px]"
                        />
                        <p class="text-[11px] text-muted-foreground">
                            Example: 2 means each video plays twice, then the feed advances (swipe up also works).
                        </p>
                    </div>
                </div>

                <div v-if="videos.length === 0" class="py-10 text-center text-sm text-muted-foreground">
                    No shoppable videos available.
                </div>
                <div v-else class="space-y-2">
                    <button
                        v-for="video in filteredVideosForContent"
                        :key="video.id"
                        type="button"
                        :class="[
                            'flex w-full items-center gap-3 rounded-xl border p-3 text-left transition-colors',
                            contentVideoIds.includes(video.id)
                                ? 'border-[#E8563A]/50 bg-[#E8563A]/5'
                                : 'hover:bg-gray-50',
                        ]"
                        @click="toggleContentVideo(video.id)"
                    >
                        <div class="flex h-12 w-9 shrink-0 overflow-hidden rounded-lg border bg-muted">
                            <img v-if="video.thumbnail_url" :src="video.thumbnail_url" class="h-full w-full object-cover">
                            <Film v-else class="m-auto size-4 text-muted-foreground" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ video.title }}</p>
                            <p v-if="video.status" class="mt-0.5 text-xs capitalize text-muted-foreground">{{ video.status }}</p>
                        </div>
                        <div
                            :class="[
                                'flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors',
                                contentVideoIds.includes(video.id)
                                    ? 'border-[#E8563A] bg-[#E8563A] text-white'
                                    : 'border-gray-300',
                            ]"
                        >
                            <Check v-if="contentVideoIds.includes(video.id)" class="size-3" />
                        </div>
                    </button>
                </div>
            </div>

            <DialogFooter class="shrink-0 border-t px-6 py-4">
                <div class="mr-auto flex items-center gap-1.5 text-sm text-muted-foreground">
                    <Film class="size-3.5" />
                    {{ contentVideoIds.length }} selected
                </div>
                <Button variant="ghost" @click="contentModalOpen = false">Cancel</Button>
                <Button class="cta-btn" :disabled="saving" @click="savePlaylistContent">
                    <Loader2 v-if="saving" class="mr-2 size-4 animate-spin" />
                    {{ saving ? 'Saving…' : 'Save playlist' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.playlists-root { background-color: #F2EFEA; }

.page-icon {
    background: linear-gradient(135deg, #E8563A, #ff8c42);
    box-shadow: 0 4px 12px rgba(232,86,58,0.35);
}

.cta-btn {
    background: #E8563A;
    color: #fff;
    box-shadow: 0 4px 16px rgba(232,86,58,0.30);
    transition: all 0.15s;
}
.cta-btn:hover:not(:disabled) {
    background: #D44A2F;
    box-shadow: 0 6px 20px rgba(232,86,58,0.40);
    transform: translateY(-1px);
}
.cta-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.ghost-icon,
.stat-card,
.playlist-card,
.empty-card {
    background: #fff;
    border: 1px solid #F0EDE8;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 16px rgba(0,0,0,0.06);
}

.ghost-icon:hover {
    background: #FAF8F5;
    color: #E8563A;
}

.stat-icon,
.playlist-icon {
    background: rgba(232,86,58,0.10);
    box-shadow: inset 0 0 0 1px rgba(232,86,58,0.12);
}

.playlist-card {
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 18px rgba(0,0,0,0.07);
}
.playlist-card:hover {
    box-shadow: 0 8px 26px rgba(0,0,0,0.10);
}

.action-btn {
    background: #fff;
    border: 1px solid #e5e7eb;
    color: #4b5563;
    transition: all 0.15s;
}
.action-btn:hover {
    border-color: rgba(232,86,58,0.40);
    color: #E8563A;
    background: rgba(232,86,58,0.04);
}

.search-input {
    border-color: #e5e7eb;
    background: #fff;
    border-radius: 12px;
}
.search-input:focus {
    border-color: #E8563A;
    box-shadow: 0 0 0 3px rgba(232,86,58,0.10);
}

.field-textarea {
    border-color: #e5e7eb;
    background: #fff;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.field-textarea:focus {
    border-color: #E8563A;
    box-shadow: 0 0 0 3px rgba(232,86,58,0.10);
}
</style>
