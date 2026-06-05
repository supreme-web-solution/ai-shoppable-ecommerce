import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { embedApiUrl } from '@/embed/config';
import {
    echoOptionsFromConfig,
    type ReverbConfig,
    resolveReverbConfigFromEnv,
    resolveReverbConfigFromWindow,
} from '@/lib/reverbConfig';

async function resolveReverbConfigFromApi(): Promise<ReverbConfig | null> {
    try {
        const response = await fetch(embedApiUrl('/api/v1/player/broadcast-config'));

        if (!response.ok) {
            return null;
        }

        const payload = (await response.json()) as Partial<ReverbConfig>;

        if (!payload.enabled || !payload.key) {
            return null;
        }

        return {
            enabled: true,
            key: payload.key,
            host: payload.host || window.location.hostname,
            port: Number(payload.port || (payload.scheme === 'http' ? 80 : 443)),
            scheme: payload.scheme === 'http' ? 'http' : 'https',
        };
    } catch {
        return null;
    }
}

export async function resolveReverbConfig(): Promise<ReverbConfig | null> {
    return (
        resolveReverbConfigFromWindow() ??
        resolveReverbConfigFromEnv() ??
        (await resolveReverbConfigFromApi())
    );
}

export async function createEmbedEcho(): Promise<Echo<'reverb'> | null> {
    const config = await resolveReverbConfig();

    if (!config) {
        return null;
    }

    const options = echoOptionsFromConfig(config);
    const client = new Pusher(options.key, {
        wsHost: options.wsHost,
        wsPort: options.wsPort,
        wssPort: options.wssPort,
        forceTLS: options.forceTLS,
        enabledTransports: options.enabledTransports,
        cluster: 'mt1',
    });

    return new Echo({ broadcaster: 'reverb', client });
}

export type { ReverbConfig };
