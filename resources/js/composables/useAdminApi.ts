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

        const payload = await response.json().catch(() => null);

        if (!response.ok) {
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
        ensureTeam,
    };
}
