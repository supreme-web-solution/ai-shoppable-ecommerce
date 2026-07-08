<script setup lang="ts">
import { Loader2, Send } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAdminApi } from '@/composables/useAdminApi';

type SocialAccount = {
    _id?: string;
    id?: string;
    platform?: string;
    username?: string;
    displayName?: string;
    name?: string;
};

type PublishLimits = {
    media_required_platforms?: string[];
    platform_content_limits?: Record<string, number>;
    twitter_url_length?: number;
};

const props = defineProps<{
    videoId: number;
    title?: string;
    shopUrl?: string;
}>();

const emit = defineEmits<{
    published: [];
}>();

const { apiFetch, ensureTeam, postJson } = useAdminApi();

const loading = ref(false);
const publishing = ref(false);
const errorText = ref('');
const successText = ref('');
const accounts = ref<SocialAccount[]>([]);
const selected = ref<Record<string, boolean>>({});
const caption = ref('');
const localShopUrl = ref('');
const publishLimits = ref<PublishLimits>({});

const PLATFORM_LABELS: Record<string, string> = {
    instagram: 'Instagram',
    facebook: 'Facebook',
    tiktok: 'TikTok',
    youtube: 'YouTube',
    linkedin: 'LinkedIn',
    twitter: 'X / Twitter',
};

const selectedPlatforms = computed(() =>
    accounts.value
        .filter((account) => selected.value[accountKey(account)])
        .map((account) => ({
            platform: String(account.platform ?? ''),
            accountId: accountId(account),
        }))
        .filter((row) => row.platform && row.accountId),
);

const captionWarnings = computed(() => {
    const text = caption.value;
    const warnings: string[] = [];
    const limits = publishLimits.value.platform_content_limits ?? {};
    const mediaRequired = new Set(publishLimits.value.media_required_platforms ?? []);

    for (const row of selectedPlatforms.value) {
        const platform = row.platform.toLowerCase();

        if (platform === 'twitter' || platform === 'x') {
            const effective = twitterEffectiveLength(text);
            const max = limits.twitter ?? 280;

            if (effective > max) {
                warnings.push(`X / Twitter: ${effective}/${max} characters (links count as ${publishLimits.value.twitter_url_length ?? 23}).`);
            }
        } else if (platform === 'tiktok') {
            const max = limits.tiktok_video ?? 2200;

            if (text.length > max) {
                warnings.push(`TikTok: caption will be trimmed to ${max} characters.`);
            }
        } else if (platform === 'youtube') {
            const title = shortTitle(props.title, text);
            const max = limits.youtube_title ?? 100;

            if (title.length > max) {
                warnings.push(`YouTube: title will be trimmed to ${max} characters.`);
            }
        } else if (platform === 'instagram') {
            const max = limits.instagram ?? 2200;

            if (text.length > max) {
                warnings.push(`Instagram: caption will be trimmed to ${max} characters.`);
            }

            if (mediaRequired.has('instagram')) {
                warnings.push('Instagram requires video or image (included when your video is attached).');
            }
        } else if (platform === 'linkedin') {
            const max = limits.linkedin ?? 3000;

            if (text.length > max) {
                warnings.push(`LinkedIn: caption will be trimmed to ${max} characters.`);
            }
        }
    }

    return [...new Set(warnings)];
});

function accountKey(account: SocialAccount): string {
    return `${account.platform}:${accountId(account)}`;
}

function accountId(account: SocialAccount): string {
    return String(account._id ?? account.id ?? '');
}

function accountLabel(account: SocialAccount): string {
    return account.displayName || account.username || account.name || account.platform || 'Account';
}

function shortTitle(title: string | undefined, fullCaption: string): string {
    const trimmedTitle = title?.trim() ?? '';

    if (trimmedTitle !== '') {
        return trimmedTitle;
    }

    const firstLine = fullCaption.split(/\r?\n/)[0]?.trim() ?? '';

    return firstLine !== '' ? firstLine : fullCaption;
}

function twitterEffectiveLength(text: string): number {
    const urlLength = publishLimits.value.twitter_url_length ?? 23;
    let length = 0;
    let offset = 0;
    const pattern = /https?:\/\/[^\s]+/gi;
    let match: RegExpExecArray | null;

    while ((match = pattern.exec(text)) !== null) {
        length += (match.index - offset);
        length += urlLength;
        offset = match.index + match[0].length;
    }

    length += text.slice(offset).length;

    return length;
}

const displayShopUrl = computed(() => props.shopUrl || localShopUrl.value);

async function loadAccountsAndShop() {
    loading.value = true;
    errorText.value = '';

    try {
        const id = await ensureTeam();

        const [status, shop] = await Promise.all([
            apiFetch<{
                accounts?: SocialAccount[];
                publish_limits?: PublishLimits;
            }>(`/api/v1/admin/zernio/status?team_id=${id}`),
            apiFetch<{ shop_url: string }>(
                `/api/v1/admin/zernio/shop-link?team_id=${id}&video_id=${props.videoId}`,
            ),
        ]);

        accounts.value = status.accounts ?? [];
        publishLimits.value = status.publish_limits ?? {};
        localShopUrl.value = shop.shop_url;
        caption.value = props.title?.trim()
            ? `${props.title.trim()}\n\nShop now: ${shop.shop_url}`
            : `Shop now: ${shop.shop_url}`;

        const nextSelected: Record<string, boolean> = {};

        for (const account of accounts.value) {
            nextSelected[accountKey(account)] = false;
        }

        selected.value = nextSelected;
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load social accounts.';
    } finally {
        loading.value = false;
    }
}

async function publish() {
    if (selectedPlatforms.value.length === 0) {
        errorText.value = 'Select at least one connected account.';

        return;
    }

    publishing.value = true;
    errorText.value = '';
    successText.value = '';

    try {
        const id = await ensureTeam();
        await postJson('/api/v1/admin/zernio/publish', {
            team_id: id,
            video_id: props.videoId,
            caption: caption.value,
            publish_now: true,
            platforms: selectedPlatforms.value,
        });

        successText.value = 'Published to your selected channels.';
        emit('published');
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Publish failed.';
    } finally {
        publishing.value = false;
    }
}

watch(
    () => props.videoId,
    () => {
        void loadAccountsAndShop();
    },
);

onMounted(() => {
    void loadAccountsAndShop();
});
</script>

<template>
    <div class="space-y-3 rounded-xl border border-[#E8563A]/20 bg-[#E8563A]/5 p-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-[#E8563A]">Publish</p>
            <p class="mt-0.5 text-[11px] text-gray-600">
                Caption is adapted per platform (X limits, YouTube title, etc.) before posting.
            </p>
        </div>

        <div v-if="displayShopUrl" class="space-y-1">
            <Label class="text-xs text-gray-500">Shop link (in caption)</Label>
            <Input :model-value="displayShopUrl" readonly class="h-8 text-xs" />
        </div>

        <div v-if="loading" class="flex items-center gap-2 text-xs text-gray-500">
            <Loader2 class="size-3.5 animate-spin" />
            Loading accounts…
        </div>

        <template v-else>
            <p v-if="errorText" class="text-xs text-red-600">{{ errorText }}</p>
            <p v-if="successText" class="text-xs text-emerald-700">{{ successText }}</p>

            <div v-if="accounts.length === 0" class="text-xs text-gray-600">
                No accounts connected.
                <a href="/settings/integrations" class="font-semibold text-[#E8563A] underline">Connect in Integrations</a>
            </div>

            <div v-else class="space-y-2">
                <Label class="text-xs text-gray-500">Post to</Label>
                <label
                    v-for="account in accounts"
                    :key="accountKey(account)"
                    class="flex cursor-pointer items-center gap-2 rounded-lg border bg-white px-2 py-1.5 text-xs"
                >
                    <input v-model="selected[accountKey(account)]" type="checkbox" class="rounded border-gray-300">
                    <span class="font-medium">{{ PLATFORM_LABELS[account.platform ?? ''] ?? account.platform }}</span>
                    <span class="truncate text-gray-500">{{ accountLabel(account) }}</span>
                </label>
            </div>

            <div class="space-y-1">
                <Label class="text-xs text-gray-500">Caption</Label>
                <textarea
                    v-model="caption"
                    rows="4"
                    class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs"
                />
                <ul v-if="captionWarnings.length" class="space-y-1 text-[11px] text-amber-700">
                    <li v-for="warning in captionWarnings" :key="warning">• {{ warning }}</li>
                </ul>
            </div>

            <Button
                size="sm"
                class="cta-btn w-full"
                :disabled="publishing || accounts.length === 0"
                @click="publish"
            >
                <Loader2 v-if="publishing" class="mr-1.5 size-3.5 animate-spin" />
                <Send v-else class="mr-1.5 size-3.5" />
                {{ publishing ? 'Publishing…' : 'Publish to social' }}
            </Button>
        </template>
    </div>
</template>

<style scoped>
.cta-btn {
    background: #e8563a;
    color: #fff;
}
</style>
