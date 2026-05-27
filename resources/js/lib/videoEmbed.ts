import { buildEmbedScriptCode } from '@/lib/embedCode';

export type EmbedDisplayType =
    | 'vertical_feed'
    | 'carousel'
    | 'floating_widget'
    | 'product_page';

export type EmbedItem = {
    id: number;
    name?: string;
    slug: string;
    type: string;
    video_id?: number | null;
    playlist_id?: number | null;
    embed_url?: string;
    embed_code?: string;
    iframe_code?: string;
};

export const EMBED_DISPLAY_OPTIONS: Array<{
    value: EmbedDisplayType;
    label: string;
    description: string;
}> = [
    {
        value: 'vertical_feed',
        label: 'Vertical feed',
        description: 'TikTok-style swipe player (default)',
    },
    {
        value: 'carousel',
        label: 'Carousel',
        description: 'Full player with horizontal video picker',
    },
    {
        value: 'floating_widget',
        label: 'Floating widget',
        description: 'Shop live button that opens the player',
    },
    {
        value: 'product_page',
        label: 'Product page',
        description: 'Split layout for product detail pages',
    },
];

type EmbedCreateResponse = { data?: EmbedItem } & Partial<EmbedItem>;

export type EmbedApiClient = {
    getList: <T>(path: string) => Promise<{ data?: T[] }>;
    postJson: <T>(path: string, body: Record<string, unknown>) => Promise<T>;
    patchJson: <T>(path: string, body: Record<string, unknown>) => Promise<T>;
};

export function embedDisplayLabel(type: string | undefined): string {
    return (
        EMBED_DISPLAY_OPTIONS.find((option) => option.value === type)?.label
        ?? 'Vertical feed'
    );
}

function slugify(value: string): string {
    return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

function normalizeEmbed(payload: EmbedCreateResponse | null | undefined): EmbedItem | null {
    if (!payload) return null;

    if (payload.data && typeof payload.data === 'object') {
        return payload.data;
    }

    if (typeof payload === 'object' && 'slug' in payload) {
        return payload as EmbedItem;
    }

    return null;
}

export async function ensureEmbedForPlaylist(
    api: EmbedApiClient,
    playlistId: number,
    playlistTitle: string,
    playlistSlug: string,
): Promise<EmbedItem | null> {
    const list = await api.getList<EmbedItem>('/api/v1/admin/embeds');
    const existing = (list.data ?? []).find((item) => item.playlist_id === playlistId);
    if (existing) {
        return existing;
    }

    const created = await api.postJson<EmbedCreateResponse>('/api/v1/admin/embeds', {
        name: `${playlistTitle} Embed`,
        slug: `playlist-${playlistId}-${slugify(playlistSlug || playlistTitle)}`,
        type: 'vertical_feed',
        playlist_id: playlistId,
        is_active: true,
    });

    return normalizeEmbed(created);
}

export async function ensureEmbedForVideo(
    api: EmbedApiClient,
    videoId: number,
    videoTitle: string,
): Promise<EmbedItem | null> {
    const list = await api.getList<EmbedItem>('/api/v1/admin/embeds');
    const existing = (list.data ?? []).find((item) => item.video_id === videoId);
    if (existing) {
        return existing;
    }

    const created = await api.postJson<EmbedCreateResponse>('/api/v1/admin/embeds', {
        name: `${videoTitle} Embed`,
        slug: `video-${videoId}-${slugify(videoTitle || `video-${videoId}`)}`,
        type: 'vertical_feed',
        video_id: videoId,
        is_active: true,
    });

    return normalizeEmbed(created);
}

export function embedPreviewUrl(embed: EmbedItem): string {
    return embed.embed_url || `${window.location.origin}/embed/${embed.slug}`;
}

export function embedScriptCode(
    embed: EmbedItem,
    type?: EmbedDisplayType,
): string {
    const displayType = type ?? (embed.type as EmbedDisplayType) ?? 'vertical_feed';

    return buildEmbedScriptCode(embed.slug, displayType);
}

export async function updateEmbedDisplayType(
    api: EmbedApiClient,
    embedId: number,
    type: EmbedDisplayType,
): Promise<EmbedItem | null> {
    const payload = await api.patchJson<EmbedCreateResponse>(
        `/api/v1/admin/embeds/${embedId}`,
        { type },
    );

    return normalizeEmbed(payload);
}

export function replaceEmbedInList(
    embeds: EmbedItem[],
    updated: EmbedItem,
): EmbedItem[] {
    const index = embeds.findIndex((item) => item.id === updated.id);

    if (index === -1) {
        return [updated, ...embeds];
    }

    const next = [...embeds];
    next[index] = { ...next[index], ...updated };

    return next;
}

export function socialShareLinks(url: string, title: string): Array<{ key: string; label: string; url: string }> {
    const encodedUrl = encodeURIComponent(url);
    const encodedTitle = encodeURIComponent(title || 'Check this out');

    return [
        { key: 'facebook', label: 'Facebook', url: `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}` },
        { key: 'x', label: 'X / Twitter', url: `https://twitter.com/intent/tweet?url=${encodedUrl}&text=${encodedTitle}` },
        { key: 'linkedin', label: 'LinkedIn', url: `https://www.linkedin.com/sharing/share-offsite/?url=${encodedUrl}` },
        { key: 'whatsapp', label: 'WhatsApp', url: `https://wa.me/?text=${encodedTitle}%20${encodedUrl}` },
        { key: 'telegram', label: 'Telegram', url: `https://t.me/share/url?url=${encodedUrl}&text=${encodedTitle}` },
    ];
}
