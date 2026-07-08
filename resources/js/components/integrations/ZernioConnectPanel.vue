<script setup lang="ts">
import { ExternalLink, Loader2, Unlink } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useAdminApi } from '@/composables/useAdminApi';

type SocialAccount = {
    _id?: string;
    id?: string;
    platform?: string;
    username?: string;
    displayName?: string;
};

const PLATFORM_LABELS: Record<string, string> = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    tiktok: 'TikTok',
    youtube: 'YouTube',
    linkedin: 'LinkedIn',
    twitter: 'X / Twitter',
};

const { apiFetch, deleteResource, ensureTeam } = useAdminApi();

const loading = ref(false);
const disconnectingPlatform = ref<string | null>(null);
const errorText = ref('');
const activeTeamId = ref<number | null>(null);
const accounts = ref<SocialAccount[]>([]);
const supportedPlatforms = ref<string[]>([]);

const accountsByPlatform = computed(() => {
    const map = new Map<string, SocialAccount>();

    for (const account of accounts.value) {
        const platform = String(account.platform ?? '').toLowerCase();
        if (platform) {
            map.set(platform, account);
        }
    }

    return map;
});

function accountLabel(account: SocialAccount): string {
    return account.displayName || account.username || 'Connected';
}

function connectHref(platform: string): string {
    if (!activeTeamId.value) {
        return '#';
    }

    const base = window.location.origin.replace(/\/$/, '');

    return `${base}/settings/integrations/zernio/${encodeURIComponent(platform)}/redirect?team_id=${activeTeamId.value}`;
}

async function loadStatus() {
    loading.value = true;
    errorText.value = '';

    try {
        const id = await ensureTeam();
        activeTeamId.value = id;

        const data = await apiFetch<{
            accounts?: SocialAccount[];
            supported_platforms?: string[];
        }>(`/api/v1/admin/zernio/status?team_id=${id}`);

        accounts.value = data.accounts ?? [];
        supportedPlatforms.value = data.supported_platforms ?? [];
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load social accounts.';
    } finally {
        loading.value = false;
    }
}

async function disconnectPlatform(platform: string) {
    disconnectingPlatform.value = platform;
    errorText.value = '';

    try {
        const teamId = await ensureTeam();
        await deleteResource(
            `/api/v1/admin/zernio/platforms/${encodeURIComponent(platform)}?team_id=${teamId}`,
        );
        await loadStatus();
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not disconnect.';
    } finally {
        disconnectingPlatform.value = null;
    }
}

onMounted(() => {
    void loadStatus();
});
</script>

<template>
    <div class="section-card rounded-2xl p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-[#E8563A]">Social publishing</p>
                <p class="mt-1 text-sm text-gray-500">Connect accounts to publish videos to social channels.</p>
            </div>
            <Button variant="outline" size="sm" :disabled="loading" @click="loadStatus">
                <Loader2 v-if="loading" class="mr-1.5 size-3.5 animate-spin" />
                Refresh
            </Button>
        </div>

        <p v-if="errorText" class="mb-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errorText }}
        </p>

        <div v-if="loading" class="flex items-center gap-2 text-sm text-gray-500">
            <Loader2 class="size-4 animate-spin" />
            Loading…
        </div>

        <div v-else class="space-y-2">
            <div
                v-for="platform in supportedPlatforms"
                :key="platform"
                class="flex items-center justify-between gap-3 rounded-xl border px-3 py-2.5 text-sm"
            >
                <div class="min-w-0">
                    <p class="font-medium text-gray-800">{{ PLATFORM_LABELS[platform] ?? platform }}</p>
                    <p
                        v-if="accountsByPlatform.has(platform)"
                        class="truncate text-xs text-emerald-700"
                    >
                        Connected · {{ accountLabel(accountsByPlatform.get(platform)!) }}
                    </p>
                </div>

                <a
                    v-if="!accountsByPlatform.has(platform)"
                    :href="connectHref(platform)"
                    class="inline-flex h-8 shrink-0 items-center gap-1 rounded-md border border-input bg-background px-3 text-xs font-medium hover:bg-accent"
                    :class="{ 'pointer-events-none opacity-50': !activeTeamId }"
                >
                    <ExternalLink class="size-3" />
                    Connect
                </a>

                <Button
                    v-else
                    variant="ghost"
                    size="sm"
                    class="shrink-0 text-red-600 hover:bg-red-50 hover:text-red-700"
                    :disabled="disconnectingPlatform !== null"
                    @click="disconnectPlatform(platform)"
                >
                    <Loader2 v-if="disconnectingPlatform === platform" class="mr-1 size-3.5 animate-spin" />
                    <Unlink v-else class="mr-1 size-3.5" />
                    Disconnect
                </Button>
            </div>
        </div>
    </div>
</template>
