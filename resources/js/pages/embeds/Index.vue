<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAdminApi } from '@/composables/useAdminApi';

type PlaylistOption = { id: number; title: string };
type VideoOption = { id: number; title: string };
type EmbedItem = {
    id: number;
    name: string;
    slug: string;
    type: string;
    is_active: boolean;
    playlist_id?: number | null;
    video_id?: number | null;
    embed_url?: string;
    iframe_code?: string;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Embeds', href: '/embeds' },
        ],
    },
});

const { getList, postJson, deleteResource, ensureTeam } = useAdminApi();

const loading = ref(false);
const saving = ref(false);
const copiedSlug = ref('');
const errorText = ref('');
const embeds = ref<EmbedItem[]>([]);
const playlists = ref<PlaylistOption[]>([]);
const videos = ref<VideoOption[]>([]);

const form = ref({
    name: '',
    slug: '',
    type: 'vertical_feed',
    playlist_id: null as number | null,
    video_id: null as number | null,
});

function slugify(value: string): string {
    return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

async function loadData() {
    loading.value = true;
    errorText.value = '';

    try {
        await ensureTeam();
        const [embedPayload, playlistPayload, videoPayload] = await Promise.all([
            getList<EmbedItem>('/api/v1/admin/embeds'),
            getList<PlaylistOption>('/api/v1/admin/playlists'),
            getList<VideoOption>('/api/v1/admin/videos'),
        ]);
        embeds.value = embedPayload.data ?? [];
        playlists.value = playlistPayload.data ?? [];
        videos.value = videoPayload.data ?? [];
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not load embeds.';
    } finally {
        loading.value = false;
    }
}

async function createEmbed() {
    saving.value = true;
    errorText.value = '';

    try {
        await postJson('/api/v1/admin/embeds', {
            name: form.value.name,
            slug: form.value.slug || slugify(form.value.name),
            type: form.value.type,
            playlist_id: form.value.playlist_id,
            video_id: form.value.video_id,
            is_active: true,
        });

        form.value = { name: '', slug: '', type: 'vertical_feed', playlist_id: null, video_id: null };
        await loadData();
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not create embed.';
    } finally {
        saving.value = false;
    }
}

async function removeEmbed(embed: EmbedItem) {
    if (!window.confirm(`Delete embed "${embed.name}"?`)) {
        return;
    }

    await deleteResource(`/api/v1/admin/embeds/${embed.id}`);
    await loadData();
}

async function copyEmbedCode(embed: EmbedItem) {
    const code = embed.iframe_code || `<iframe src="${embed.embed_url}" width="100%" height="700" frameborder="0"></iframe>`;
    await navigator.clipboard.writeText(code);
    copiedSlug.value = embed.slug;
    window.setTimeout(() => {
        copiedSlug.value = '';
    }, 2000);
}

onMounted(loadData);
</script>

<template>
    <Head title="Embeds" />

    <div class="space-y-6 rounded-xl p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Embeds</h1>
                <p class="text-sm text-muted-foreground">Generate embed codes for TikTok-style shopping experiences.</p>
            </div>
            <Button variant="outline" :disabled="loading" @click="loadData">Refresh</Button>
        </div>

        <p v-if="errorText" class="rounded border border-red-400/40 bg-red-500/10 px-3 py-2 text-sm text-red-300">
            {{ errorText }}
        </p>

        <div class="rounded-lg border bg-card p-4">
            <h2 class="mb-4 text-lg font-semibold">Create Embed</h2>
            <div class="grid gap-3 md:grid-cols-2">
                <div>
                    <Label>Name</Label>
                    <Input v-model="form.name" placeholder="Homepage feed" />
                </div>
                <div>
                    <Label>Slug</Label>
                    <Input v-model="form.slug" :placeholder="slugify(form.name || 'homepage-feed')" />
                </div>
                <div>
                    <Label>Type</Label>
                    <select v-model="form.type" class="mt-1 w-full rounded border bg-background px-2 py-2 text-sm">
                        <option value="vertical_feed">Vertical feed</option>
                        <option value="floating_widget">Floating widget</option>
                        <option value="carousel">Carousel</option>
                        <option value="product_page">Product page</option>
                    </select>
                </div>
                <div>
                    <Label>Playlist</Label>
                    <select v-model="form.playlist_id" class="mt-1 w-full rounded border bg-background px-2 py-2 text-sm">
                        <option :value="null">None</option>
                        <option v-for="playlist in playlists" :key="playlist.id" :value="playlist.id">
                            {{ playlist.title }}
                        </option>
                    </select>
                </div>
                <div>
                    <Label>Single video (optional)</Label>
                    <select v-model="form.video_id" class="mt-1 w-full rounded border bg-background px-2 py-2 text-sm">
                        <option :value="null">None</option>
                        <option v-for="video in videos" :key="video.id" :value="video.id">
                            {{ video.title }}
                        </option>
                    </select>
                </div>
            </div>
            <Button class="mt-4" :disabled="saving || !form.name" @click="createEmbed">
                {{ saving ? 'Creating...' : 'Create embed' }}
            </Button>
        </div>

        <div class="space-y-3">
            <h2 class="text-lg font-semibold">Embed Codes</h2>
            <div v-if="loading" class="rounded-lg border p-4 text-sm text-muted-foreground">Loading...</div>
            <div v-else-if="embeds.length === 0" class="rounded-lg border p-4 text-sm text-muted-foreground">
                No embeds yet.
            </div>
            <div v-else class="space-y-3">
                <div v-for="embed in embeds" :key="embed.id" class="rounded-lg border bg-card p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold">{{ embed.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ embed.type }} • /embed/{{ embed.slug }}
                            </p>
                            <a
                                :href="embed.embed_url"
                                target="_blank"
                                rel="noreferrer"
                                class="text-xs text-primary underline"
                            >
                                Preview embed
                            </a>
                        </div>
                        <div class="flex gap-2">
                            <Button variant="outline" size="sm" @click="copyEmbedCode(embed)">
                                {{ copiedSlug === embed.slug ? 'Copied!' : 'Copy iframe' }}
                            </Button>
                            <Button variant="destructive" size="sm" @click="removeEmbed(embed)">Delete</Button>
                        </div>
                    </div>
                    <pre class="mt-3 overflow-x-auto rounded bg-muted p-3 text-xs">{{ embed.iframe_code }}</pre>
                    <p v-if="embed.type === 'floating_widget'" class="mt-2 text-xs text-muted-foreground">
                        Floating widget opens as a launcher bubble on the embed page. Paste the iframe on any page or use the embed URL directly.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
