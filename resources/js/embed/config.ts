declare global {
    interface Window {
        __MSC_EMBED_ORIGIN__?: string;
        /** @deprecated Use __MSC_EMBED_ORIGIN__ — kept for older embed snippets. */
        __SUPREME_EMBED_ORIGIN__?: string;
    }
}

export function getEmbedOrigin(): string {
    if (typeof window !== 'undefined' && window.__MSC_EMBED_ORIGIN__) {
        return window.__MSC_EMBED_ORIGIN__.replace(/\/$/, '');
    }

    if (typeof window !== 'undefined' && window.__SUPREME_EMBED_ORIGIN__) {
        return window.__SUPREME_EMBED_ORIGIN__.replace(/\/$/, '');
    }

    if (typeof window !== 'undefined') {
        return window.location.origin;
    }

    return '';
}

export function embedApiUrl(path: string): string {
    const normalized = path.startsWith('/') ? path : `/${path}`;

    return `${getEmbedOrigin()}${normalized}`;
}
