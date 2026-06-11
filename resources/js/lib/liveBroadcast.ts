function readCookie(name: string): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

function buildJsonHeaders(): Headers {
    const headers = new Headers();
    headers.set('Accept', 'application/json');
    headers.set('Content-Type', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const csrfToken = readCookie('XSRF-TOKEN');

    if (csrfToken) {
        headers.set('X-XSRF-TOKEN', csrfToken);
    }

    return headers;
}

function buildChunkHeaders(): Headers {
    const headers = new Headers();
    headers.set('Accept', 'application/json');
    headers.set('Content-Type', 'video/webm');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const csrfToken = readCookie('XSRF-TOKEN');

    if (csrfToken) {
        headers.set('X-XSRF-TOKEN', csrfToken);
    }

    return headers;
}

function preferredMimeType(): { mimeType: string; extension: string } {
    const candidates = [
        { mimeType: 'video/webm;codecs=vp8,opus', extension: 'webm' },
        { mimeType: 'video/webm;codecs=vp9,opus', extension: 'webm' },
        { mimeType: 'video/webm', extension: 'webm' },
        { mimeType: 'video/mp4', extension: 'mp4' },
    ];

    const match = candidates.find((candidate) => MediaRecorder.isTypeSupported(candidate.mimeType));

    return match ?? { mimeType: 'video/webm', extension: 'webm' };
}

async function requestCamera(): Promise<MediaStream> {
    return navigator.mediaDevices.getUserMedia({
        video: {
            facingMode: 'user',
            width: { ideal: 1280 },
            height: { ideal: 720 },
        },
        audio: true,
    });
}

export type LiveBroadcastSession = {
    mediaStream: MediaStream;
    stop: () => void;
};

const MAX_UPLOAD_FAILURES = 8;
const UPLOAD_RETRIES = 3;

export async function startLiveBroadcast(options: {
    liveShowId: number;
    mediaStream?: MediaStream | null;
    onError?: (message: string) => void;
}): Promise<LiveBroadcastSession> {
    const startResponse = await fetch(
        `/api/v1/admin/live-shows/${options.liveShowId}/broadcast/start`,
        {
            method: 'POST',
            headers: buildJsonHeaders(),
            credentials: 'same-origin',
        },
    );

    const startPayload = await startResponse.json().catch(() => null);

    if (!startResponse.ok) {
        const message =
            startPayload && typeof startPayload === 'object' && 'message' in startPayload
                ? String((startPayload as { message: string }).message)
                : `Could not start broadcasting (${startResponse.status})`;

        throw new Error(message);
    }

    const sessionId =
        startPayload &&
        typeof startPayload === 'object' &&
        'data' in startPayload &&
        startPayload.data &&
        typeof startPayload.data === 'object' &&
        'session_id' in startPayload.data
            ? String((startPayload.data as { session_id: string }).session_id)
            : '';

    if (!sessionId) {
        throw new Error('Broadcast session could not be created.');
    }

    const mediaStream = options.mediaStream ?? (await requestCamera());
    const { mimeType, extension } = preferredMimeType();
    let stopped = false;
    let chunkIndex = 0;
    let consecutiveFailures = 0;
    let uploadChain = Promise.resolve();

    const recorder = new MediaRecorder(mediaStream, {
        mimeType,
        videoBitsPerSecond: 2_500_000,
    });

    async function uploadChunk(index: number, blob: Blob): Promise<void> {
        let lastError = 'Broadcast upload failed.';

        for (let attempt = 1; attempt <= UPLOAD_RETRIES; attempt += 1) {
            if (stopped) {
                return;
            }

            try {
                const headers = buildChunkHeaders();
                headers.set('X-Broadcast-Session', sessionId);
                headers.set('X-Broadcast-Chunk', String(index));
                headers.set('X-Broadcast-Format', extension);

                const response = await fetch(
                    `/api/v1/admin/live-shows/${options.liveShowId}/broadcast/chunk`,
                    {
                        method: 'POST',
                        headers,
                        body: blob,
                        credentials: 'same-origin',
                    },
                );

                if (response.ok) {
                    consecutiveFailures = 0;

                    return;
                }

                const payload = await response.json().catch(() => null);
                lastError =
                    payload && typeof payload === 'object' && 'message' in payload
                        ? String((payload as { message: string }).message)
                        : `Broadcast upload failed (${response.status})`;
            } catch (error: unknown) {
                lastError = error instanceof Error ? error.message : 'Broadcast upload failed.';
            }

            if (attempt < UPLOAD_RETRIES) {
                await new Promise((resolve) => window.setTimeout(resolve, 500 * attempt));
            }
        }

        consecutiveFailures += 1;

        if (consecutiveFailures >= MAX_UPLOAD_FAILURES) {
            options.onError?.(lastError);
        }
    }

    recorder.addEventListener('dataavailable', (event) => {
        if (stopped || event.data.size === 0) {
            return;
        }

        const currentIndex = chunkIndex;
        chunkIndex += 1;

        uploadChain = uploadChain.then(() => uploadChunk(currentIndex, event.data));
    });

    recorder.start(500);

    const stopSession = async (): Promise<void> => {
        if (stopped) {
            return;
        }

        stopped = true;

        if (recorder.state !== 'inactive') {
            recorder.stop();
        }

        await uploadChain.catch(() => undefined);

        try {
            await fetch(`/api/v1/admin/live-shows/${options.liveShowId}/broadcast/stop`, {
                method: 'POST',
                headers: buildJsonHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ session_id: sessionId }),
            });
        } catch {
            // Best-effort shutdown.
        }
    };

    return {
        mediaStream,
        stop: () => {
            void stopSession();
        },
    };
}
