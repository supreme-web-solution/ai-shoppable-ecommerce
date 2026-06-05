import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

type PaginatedResponse<T> = {
    data: T[];
    meta?: {
        current_page: number;
        last_page: number;
        total: number;
    };
};

export type VideoUploadResult = {
    local_file_path?: string;
    cloudinary_public_id?: string;
    playback_url?: string;
    thumbnail_url?: string | null;
    duration_seconds?: number;
};

export function videoUploadFields(upload: VideoUploadResult): Record<string, unknown> {
    if (upload.local_file_path) {
        return { local_file_path: upload.local_file_path };
    }

    return {
        cloudinary_public_id: upload.cloudinary_public_id,
        playback_url: upload.playback_url,
        thumbnail_url: upload.thumbnail_url,
        duration_seconds: upload.duration_seconds,
        status: 'ready',
    };
}

function readCookie(name: string): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

function buildHeaders(extra?: HeadersInit): Headers {
    const headers = new Headers(extra ?? {});
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const csrfToken = readCookie('XSRF-TOKEN');

    if (csrfToken) {
        headers.set('X-XSRF-TOKEN', csrfToken);
    }

    return headers;
}

let unauthenticatedHandled = false;

function handleUnauthenticated(status: number): void {
    if (unauthenticatedHandled) {
        return;
    }

    unauthenticatedHandled = true;

    const message = status === 419
        ? 'Your session expired. Please sign in again.'
        : 'Please sign in to continue.';

    toast.error(message);

    router.visit('/login', { replace: true, preserveScroll: false });
}

export function useAdminApi() {
    const page = usePage();
    const resolvedTeamId = ref(0);

    const teamId = computed(() => {
        const fromPage = Number((page.props.auth.user as Record<string, unknown>)?.team_id || 0);

        return fromPage > 0 ? fromPage : resolvedTeamId.value;
    });

    async function apiFetch<T>(url: string, options: RequestInit = {}): Promise<T> {
        const headers = buildHeaders(options.headers);
        const response = await fetch(url, {
            ...options,
            headers,
            credentials: 'same-origin',
        });

        if (response.status === 204) {
            return undefined as T;
        }

        if (response.status === 401 || response.status === 419) {
            handleUnauthenticated(response.status);

            throw new Error('Session expired.');
        }

        const contentType = response.headers.get('content-type') ?? '';
        const payload = contentType.includes('application/json')
            ? await response.json().catch(() => null)
            : null;

        if (!response.ok) {
            if (response.status === 524) {
                throw new Error(
                    'Upload timed out before the server finished receiving the file. Try again or use a smaller video.',
                );
            }

            const message =
                payload && typeof payload === 'object' && 'message' in payload
                    ? String((payload as { message: string }).message)
                    : `Request failed (${response.status})`;

            throw new Error(message);
        }

        return payload as T;
    }

    async function getList<T>(path: string, query: Record<string, string | number> = {}): Promise<PaginatedResponse<T>> {
        const params = new URLSearchParams({ team_id: String(teamId.value) });

        Object.entries(query).forEach(([key, value]) => {
            params.set(key, String(value));
        });

        return apiFetch<PaginatedResponse<T>>(`${path}?${params.toString()}`);
    }

    async function postJson<T>(path: string, body: Record<string, unknown>): Promise<T> {
        const headers = buildHeaders({ 'Content-Type': 'application/json' });

        return apiFetch<T>(path, {
            method: 'POST',
            headers,
            body: JSON.stringify({ team_id: teamId.value, ...body }),
        });
    }

    async function putJson<T>(path: string, body: Record<string, unknown>): Promise<T> {
        const headers = buildHeaders({ 'Content-Type': 'application/json' });

        return apiFetch<T>(path, {
            method: 'PUT',
            headers,
            body: JSON.stringify(body),
        });
    }

    async function patchJson<T>(path: string, body: Record<string, unknown>): Promise<T> {
        const headers = buildHeaders({ 'Content-Type': 'application/json' });

        return apiFetch<T>(path, {
            method: 'PATCH',
            headers,
            body: JSON.stringify(body),
        });
    }

    async function deleteResource(path: string): Promise<void> {
        await apiFetch<void>(path, { method: 'DELETE' });
    }

    async function uploadProductImage(file: File): Promise<{ image_url: string; public_id?: string }> {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('team_id', String(teamId.value));

        const headers = buildHeaders();

        return apiFetch<{ image_url: string; public_id?: string }>('/api/v1/admin/products/upload-image', {
            method: 'POST',
            headers,
            body: formData,
        });
    }

    type VideoUploadParams = {
        direct_upload?: boolean;
        upload_url?: string;
        api_key?: string;
        timestamp?: number;
        signature?: string;
        folder?: string;
        public_id?: string | null;
        cloud_name?: string;
    };

    type CloudinaryUploadResponse = {
        public_id?: string;
        secure_url?: string;
        duration?: number;
        error?: { message?: string };
    };

    function withVideoDeliveryTransform(url: string): string {
        if (!url || !url.includes('/video/upload/') || url.includes('/video/upload/f_auto')) {
            return url;
        }

        return url.replace('/video/upload/', '/video/upload/f_auto,q_auto/');
    }

    function cloudinaryVideoThumbnail(cloudName: string, publicId: string): string {
        const escapedId = publicId.replace(/\//g, '%2F');

        return `https://res.cloudinary.com/${cloudName}/video/upload/so_0,w_400,h_711,c_fill/f_auto,q_auto/${escapedId}.jpg`;
    }

    function uploadWithProgress(
        url: string,
        formData: FormData,
        onProgress?: (percent: number) => void,
    ): Promise<CloudinaryUploadResponse> {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.responseType = 'json';

            xhr.upload.onprogress = (event) => {
                if (!onProgress || !event.lengthComputable) {
                    return;
                }

                onProgress(Math.round((event.loaded / event.total) * 100));
            };

            xhr.onload = () => {
                let response = xhr.response as CloudinaryUploadResponse | null;

                if (!response && typeof xhr.responseText === 'string' && xhr.responseText !== '') {
                    try {
                        response = JSON.parse(xhr.responseText) as CloudinaryUploadResponse;
                    } catch {
                        response = null;
                    }
                }

                if (xhr.status >= 200 && xhr.status < 300 && response?.public_id) {
                    resolve(response);

                    return;
                }

                const message = response?.error?.message
                    ?? `Cloudinary upload failed (${xhr.status})`;

                reject(new Error(message));
            };

            xhr.onerror = () => {
                reject(new Error('Cloudinary upload failed. Check your connection and try again.'));
            };

            xhr.send(formData);
        });
    }

    async function uploadVideoFile(
        file: File,
        onProgress?: (percent: number) => void,
    ): Promise<VideoUploadResult> {
        const params = await postJson<VideoUploadParams>('/api/v1/admin/videos/upload-params', {});

        if (!params.direct_upload || !params.upload_url || !params.api_key || !params.signature || !params.folder) {
            const legacy = await uploadFile('/api/v1/admin/videos/upload', file);

            return { local_file_path: legacy.local_file_path };
        }

        const formData = new FormData();
        formData.append('file', file);
        formData.append('api_key', params.api_key);
        formData.append('timestamp', String(params.timestamp));
        formData.append('signature', params.signature);
        formData.append('folder', params.folder);

        if (params.public_id) {
            formData.append('public_id', params.public_id);
        }

        const response = await uploadWithProgress(params.upload_url, formData, onProgress);
        const publicId = String(response.public_id ?? '');
        const cloudName = String(params.cloud_name ?? '');

        return {
            cloudinary_public_id: publicId,
            playback_url: withVideoDeliveryTransform(String(response.secure_url ?? '')),
            thumbnail_url: cloudName !== '' ? cloudinaryVideoThumbnail(cloudName, publicId) : null,
            duration_seconds: Math.round(Number(response.duration ?? 0)),
        };
    }

    async function uploadFile(path: string, file: File, extra: Record<string, string> = {}): Promise<{ local_file_path: string }> {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('team_id', String(teamId.value));

        Object.entries(extra).forEach(([key, value]) => {
            formData.append(key, value);
        });

        const headers = buildHeaders();

        return apiFetch<{ local_file_path: string }>(path, {
            method: 'POST',
            headers,
            body: formData,
        });
    }

    async function ensureTeam(): Promise<number> {
        if (teamId.value > 0) {
            return teamId.value;
        }

        const user = page.props.auth.user as Record<string, unknown> | null;
        const team = await postJson<{ id: number }>('/api/v1/admin/teams', {
            name: `${String(user?.name ?? 'My')} Store`,
        });

        resolvedTeamId.value = team.id;

        return team.id;
    }

    return {
        teamId,
        apiFetch,
        getList,
        postJson,
        putJson,
        patchJson,
        deleteResource,
        uploadFile,
        uploadVideoFile,
        uploadProductImage,
        ensureTeam,
    };
}
