export type VideoPlaybackProvider = 'direct' | 'youtube' | 'vimeo';

export type VideoPlayback = {
    provider: VideoPlaybackProvider;
    source_url: string;
    embed_url: string | null;
    direct_url: string | null;
    thumbnail_url?: string | null;
};

export function youtubeId(url: string): string | null {
    try {
        const parsed = new URL(url);
        const host = parsed.hostname.toLowerCase();

        if (host.includes('youtu.be')) {
            const id = parsed.pathname.replace(/^\/+/, '');

            return normalizeYoutubeId(id);
        }

        if (!host.includes('youtube.com') && !host.includes('youtube-nocookie.com')) {
            return null;
        }

        const embedMatch = parsed.pathname.match(/^\/embed\/([^/?]+)/);

        if (embedMatch?.[1]) {
            return normalizeYoutubeId(embedMatch[1]);
        }

        const shortsMatch = parsed.pathname.match(/^\/shorts\/([^/?]+)/);

        if (shortsMatch?.[1]) {
            return normalizeYoutubeId(shortsMatch[1]);
        }

        const id = parsed.searchParams.get('v');

        return id ? normalizeYoutubeId(id) : null;
    } catch {
        return null;
    }
}

export function vimeoId(url: string): string | null {
    try {
        const parsed = new URL(url);

        if (!parsed.hostname.toLowerCase().includes('vimeo.com')) {
            return null;
        }

        const match = parsed.pathname.match(/\/(?:video\/)?(\d+)/);

        return match?.[1] ?? null;
    } catch {
        return null;
    }
}

function normalizeYoutubeId(id: string): string | null {
    const trimmed = id.trim();

    if (!/^[\w-]{6,}$/.test(trimmed)) {
        return null;
    }

    return trimmed;
}

export function parseExternalVideoUrl(url: string | null | undefined): VideoPlayback | null {
    const trimmed = (url ?? '').trim();

    if (!trimmed) {
        return null;
    }

    try {
         
        new URL(trimmed);
    } catch {
        return null;
    }

    const yt = youtubeId(trimmed);

    if (yt) {
        return {
            provider: 'youtube',
            source_url: trimmed,
            embed_url: `https://www.youtube-nocookie.com/embed/${yt}`,
            direct_url: null,
            thumbnail_url: `https://img.youtube.com/vi/${yt}/hqdefault.jpg`,
        };
    }

    const vm = vimeoId(trimmed);

    if (vm) {
        return {
            provider: 'vimeo',
            source_url: trimmed,
            embed_url: `https://player.vimeo.com/video/${vm}`,
            direct_url: null,
            thumbnail_url: null,
        };
    }

    return {
        provider: 'direct',
        source_url: trimmed,
        embed_url: null,
        direct_url: trimmed,
        thumbnail_url: null,
    };
}

export function isEmbedPlayback(
    playback: VideoPlayback | null | undefined,
): playback is VideoPlayback & { embed_url: string } {
    return (
        playback != null &&
        (playback.provider === 'youtube' || playback.provider === 'vimeo') &&
        Boolean(playback.embed_url)
    );
}

/** Admin preview — keeps visible controls. */
export function embedUrlWithAutoplay(
    embedUrl: string,
    provider: VideoPlaybackProvider,
): string {
    const url = new URL(embedUrl);

    url.searchParams.set('autoplay', '1');

    if (provider === 'youtube') {
        url.searchParams.set('playsinline', '1');
        url.searchParams.set('rel', '0');
    }

    if (provider === 'vimeo') {
        url.searchParams.set('title', '0');
        url.searchParams.set('byline', '0');
        url.searchParams.set('portrait', '0');
    }

    return url.toString();
}

/**
 * Webinar room embed — no controls/branding; feels like a live stream.
 */
export function embedUrlForLiveWebinar(
    embedUrl: string,
    provider: VideoPlaybackProvider,
): string {
    const url = new URL(embedUrl);

    url.searchParams.set('autoplay', '1');

    if (provider === 'youtube') {
        url.searchParams.set('playsinline', '1');
        url.searchParams.set('controls', '0');
        url.searchParams.set('modestbranding', '1');
        url.searchParams.set('rel', '0');
        url.searchParams.set('iv_load_policy', '3');
        url.searchParams.set('disablekb', '1');
        url.searchParams.set('fs', '0');
        url.searchParams.set('cc_load_policy', '0');
        url.searchParams.set('loop', '0');
    }

    if (provider === 'vimeo') {
        url.searchParams.set('controls', '0');
        url.searchParams.set('title', '0');
        url.searchParams.set('byline', '0');
        url.searchParams.set('portrait', '0');
        url.searchParams.set('badge', '0');
        url.searchParams.set('pip', '0');
        url.searchParams.set('dnt', '1');
    }

    return url.toString();
}
