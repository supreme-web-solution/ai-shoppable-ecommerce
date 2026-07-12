export type ReverbConfig = {
    enabled: boolean;
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
};

declare global {
    interface Window {
        __REVERB__?: ReverbConfig | null;
        /** @deprecated Use __REVERB__ — kept for older embed snippets. */
        __MSC_REVERB__?: Partial<ReverbConfig>;
        /** @deprecated Use __MSC_REVERB__ — kept for older embed snippets. */
        __SUPREME_REVERB__?: Partial<ReverbConfig>;
    }
}

function defaultHost(): string {
    return window.location.hostname;
}

function defaultPort(scheme: 'http' | 'https'): number {
    return scheme === 'https' ? 443 : 80;
}

function normalizeConfig(raw: Partial<ReverbConfig> & { key?: string }): ReverbConfig | null {
    if (!raw.key) {
        return null;
    }

    const scheme: 'http' | 'https' = raw.scheme === 'http' ? 'http' : 'https';

    return {
        enabled: raw.enabled ?? true,
        key: raw.key,
        host: raw.host || defaultHost(),
        port: Number(raw.port || defaultPort(scheme)),
        scheme,
    };
}

/** Runtime config injected by Laravel (app.blade.php / embed.blade.php). */
export function resolveReverbConfigFromWindow(): ReverbConfig | null {
    const fromWindow = window.__REVERB__;

    if (fromWindow?.key) {
        return normalizeConfig(fromWindow);
    }

    const legacy =
        window.__MSC_REVERB__ ?? window.__SUPREME_REVERB__;

    if (legacy?.key) {
        return normalizeConfig(legacy);
    }

    return null;
}

/** Optional Vite dev fallback when the page was not server-rendered. */
export function resolveReverbConfigFromEnv(): ReverbConfig | null {
    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (!key) {
        return null;
    }

    const scheme = ((import.meta.env.VITE_REVERB_SCHEME as string | undefined) ||
        (window.location.protocol === 'https:' ? 'https' : 'http')) as 'http' | 'https';

    return normalizeConfig({
        enabled: true,
        key,
        host: (import.meta.env.VITE_REVERB_HOST as string | undefined) || defaultHost(),
        port: Number(import.meta.env.VITE_REVERB_PORT || defaultPort(scheme)),
        scheme,
    });
}

export function resolveReverbConfig(): ReverbConfig | null {
    return resolveReverbConfigFromWindow() ?? resolveReverbConfigFromEnv();
}

export function echoOptionsFromConfig(config: ReverbConfig) {
    return {
        broadcaster: 'reverb' as const,
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'] as ('ws' | 'wss')[],
    };
}
