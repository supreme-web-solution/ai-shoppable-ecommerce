import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { embedApiUrl, getEmbedOrigin } from '@/embed/config';

export type ReverbConfig = {
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
};

declare global {
    interface Window {
        __SUPREME_REVERB__?: Partial<ReverbConfig>;
    }
}

function hostFromOrigin(origin: string): string {
    try {
        return new URL(origin).hostname;
    } catch {
        return 'localhost';
    }
}

function configFromEnv(): ReverbConfig | null {
    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (!key) {
        return null;
    }

    const origin = getEmbedOrigin();
    const defaultHost = origin ? hostFromOrigin(origin) : window.location.hostname;

    return {
        key,
        host: (import.meta.env.VITE_REVERB_HOST as string | undefined) || defaultHost,
        port: Number(import.meta.env.VITE_REVERB_PORT || 8080),
        scheme: ((import.meta.env.VITE_REVERB_SCHEME as string | undefined) ||
            'http') as 'http' | 'https',
    };
}

function configFromWindow(): ReverbConfig | null {
    const w = window.__SUPREME_REVERB__;

    if (!w?.key) {
        return null;
    }

    const origin = getEmbedOrigin();
    const defaultHost = origin ? hostFromOrigin(origin) : window.location.hostname;

    return {
        key: w.key,
        host: w.host || defaultHost,
        port: Number(w.port || 8080),
        scheme: (w.scheme === 'https' ? 'https' : 'http') as 'http' | 'https',
    };
}

export async function resolveReverbConfig(): Promise<ReverbConfig | null> {
    const fromWindow = configFromWindow();

    if (fromWindow) {
        return fromWindow;
    }

    const fromEnv = configFromEnv();

    if (fromEnv) {
        return fromEnv;
    }

    try {
        const response = await fetch(embedApiUrl('/api/v1/player/broadcast-config'));

        if (!response.ok) {
            return null;
        }

        const payload = (await response.json()) as {
            enabled?: boolean;
            key?: string;
            host?: string;
            port?: number;
            scheme?: string;
        };

        if (!payload.enabled || !payload.key) {
            return null;
        }

        const origin = getEmbedOrigin();
        const defaultHost = origin ? hostFromOrigin(origin) : window.location.hostname;

        return {
            key: payload.key,
            host: payload.host || defaultHost,
            port: Number(payload.port || 8080),
            scheme: payload.scheme === 'https' ? 'https' : 'http',
        };
    } catch {
        return null;
    }
}

export async function createEmbedEcho(): Promise<Echo<'reverb'> | null> {
    const config = await resolveReverbConfig();

    if (!config) {
        return null;
    }

    const client = new Pusher(config.key, {
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        cluster: 'mt1',
    });

    return new Echo({ broadcaster: 'reverb', client });
}
