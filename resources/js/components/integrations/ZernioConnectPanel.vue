<script setup lang="ts">
import { ExternalLink, Loader2, RefreshCw, Unlink } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useAdminApi } from '@/composables/useAdminApi';

type ZernioAccount = {
    _id?: string;
    id?: string;
    platform?: string;
    username?: string;
    displayName?: string;
    name?: string;
};

const PLATFORM_LABELS: Record<string, string> = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    tiktok: 'TikTok',
    youtube: 'YouTube',
    linkedin: 'LinkedIn',
    threads: 'Threads',
    twitter: 'X / Twitter',
    pinterest: 'Pinterest',
};

const { apiFetch, postJson, deleteResource, ensureTeam } = useAdminApi();

const loading = ref(false);
const connecting = ref<string | null>(null);
const disconnectingId = ref<string | null>(null);
const errorText = ref('');
const profileId = ref<string | null>(null);
const accounts = ref<ZernioAccount[]>([]);
const supportedPlatforms = ref<string[]>([]);

const connectedCount = computed(() => accounts.value.length);

const connectedPlatforms = computed(() =>
    new Set(accounts.value.map((account) => String(account.platform ?? '').toLowerCase())),
);

function accountLabel(account: ZernioAccount): string {
    return (
        account.displayName
        || account.username
        || account.name
        || account.platform
        || 'Connected account'
    );
}

function accountId(account: ZernioAccount): string {
    return String(account._id ?? account.id ?? '');
}

async function loadStatus() {
    loading.value = true;
    errorText.value = '';

    try {
        const id = await ensureTeam();
        const data = await apiFetch<{
            profile_id?: string | null;
            accounts?: ZernioAccount[];
            supported_platforms?: string[];
        }>(`/api/v1/admin/zernio/status?team_id=${id}`);

        profileId.value = data.profile_id ?? null;
        accounts.value = data.accounts ?? [];
        supportedPlatforms.value = data.supported_platforms ?? [];
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load Zernio status.';
    } finally {
        loading.value = false;
    }
}

async function ensureProfileOnly(): Promise<string> {
    const id = await ensureTeam();
    const data = await postJson<{ profile_id: string }>(`/api/v1/admin/zernio/profile?team_id=${id}`, {});
    profileId.value = data.profile_id ?? profileId.value;

    return profileId.value ?? '';
}

function watchOAuthPopup(popup: Window | null) {
    if (!popup) {
        return;
    }

    const timer = window.setInterval(() => {
        if (popup.closed) {
            window.clearInterval(timer);
            void loadStatus();
        }
    }, 800);
}

async function connectPlatform(platform: string) {
    connecting.value = platform;
    errorText.value = '';

    try {
        const id = await ensureTeam();

        if (!profileId.value) {
            await ensureProfileOnly();
        }

        const data = await apiFetch<{ auth_url: string }>(
            `/api/v1/admin/zernio/connect?team_id=${id}&platform=${encodeURIComponent(platform)}`,
        );

        const popup = window.open(data.auth_url, '_blank', 'noopener,noreferrer');
        watchOAuthPopup(popup);
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not start OAuth.';
    } finally {
        connecting.value = null;
    }
}

async function disconnectAccount(account: ZernioAccount) {
    const id = accountId(account);

    if (!id) {
        return;
    }

    disconnectingId.value = id;
    errorText.value = '';

    try {
        const teamId = await ensureTeam();
        await deleteResource(`/api/v1/admin/zernio/accounts/${encodeURIComponent(id)}?team_id=${teamId}`);
        await loadStatus();
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not disconnect account.';
    } finally {
        disconnectingId.value = null;
    }
}

onMounted(() => {
    void loadStatus();
});
</script>

<template>
    <div class="section-card rounded-2xl p-6">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-[#E8563A]">Social publishing</p>
                <p class="mt-1 max-w-xl text-sm text-gray-500">
                    Connect one platform at a time via OAuth. Only accounts linked to
                    <strong>this team's Zernio profile</strong> are shown — not every account on your API key.
                </p>
            </div>
            <Button variant="outline" size="sm" :disabled="loading" @click="loadStatus">
                <RefreshCw class="mr-1.5 size-3.5" :class="{ 'animate-spin': loading }" />
                Refresh
            </Button>
        </div>

        <p v-if="errorText" class="mb-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errorText }}
        </p>

        <div v-if="loading" class="flex items-center gap-2 text-sm text-gray-500">
            <Loader2 class="size-4 animate-spin" />
            Loading connected accounts…
        </div>

        <template v-else>
            <p class="mb-3 text-xs text-gray-500">
                Profile:
                <span class="font-mono">{{ profileId || 'Created on first connect' }}</span>
                · {{ connectedCount }} account(s) for this team
            </p>

            <div v-if="accounts.length" class="mb-4 space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Connected for this team</p>
                <div
                    v-for="account in accounts"
                    :key="accountId(account)"
                    class="flex items-center justify-between gap-3 rounded-xl border bg-gray-50 px-3 py-2 text-sm"
                >
                    <div class="min-w-0">
                        <p class="font-medium capitalize text-gray-800">
                            {{ PLATFORM_LABELS[account.platform ?? ''] ?? account.platform }}
                        </p>
                        <p class="truncate text-xs text-gray-500">{{ accountLabel(account) }}</p>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="shrink-0 text-red-600 hover:bg-red-50 hover:text-red-700"
                        :disabled="disconnectingId !== null"
                        @click="disconnectAccount(account)"
                    >
                        <Loader2 v-if="disconnectingId === accountId(account)" class="mr-1 size-3.5 animate-spin" />
                        <Unlink v-else class="mr-1 size-3.5" />
                        Disconnect
                    </Button>
                </div>
            </div>

            <p v-else class="mb-4 text-sm text-gray-500">
                No accounts connected yet. Click a platform below to start OAuth — you'll return here and can hit Refresh when done.
            </p>

            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Connect a platform</p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="platform in supportedPlatforms"
                    :key="platform"
                    variant="outline"
                    size="sm"
                    :disabled="connecting !== null"
                    @click="connectPlatform(platform)"
                >
                    <Loader2 v-if="connecting === platform" class="mr-1 size-3 animate-spin" />
                    <ExternalLink v-else class="mr-1 size-3" />
                    {{ PLATFORM_LABELS[platform] ?? platform }}
                    <span
                        v-if="connectedPlatforms.has(platform)"
                        class="ml-1.5 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700"
                    >
                        linked
                    </span>
                </Button>
            </div>
        </template>
    </div>
</template>
