<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronRight,
    Film,
    Loader2,
    MessageSquare,
    RefreshCw,
    Tv,
    Video,
    XCircle,
} from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminApi } from '@/composables/useAdminApi';

type WebinarItem = {
    id: number;
    title: string;
    status: string;
    host_name?: string | null;
    conversations_count?: number;
    messages_count?: number;
};

type LiveVideoConversation = {
    video_id: number;
    title: string;
    display_title?: string;
    messages_count: number;
    conversations_count?: number;
    ai_assistant_enabled: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Live Cast', href: '/live-shows' },
            { title: 'Chats', href: '/live-shows/chats' },
        ],
    },
});

const { getList, apiFetch, ensureTeam } = useAdminApi();

const loading = ref(false);
const errorText = ref('');
const webinars = ref<WebinarItem[]>([]);
const liveVideos = ref<LiveVideoConversation[]>([]);
const chatSummary = ref({
    webinar_chats_count: 0,
    live_video_chats_count: 0,
    total_chats_count: 0,
});

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'live') {
        return 'destructive';
    }

    if (status === 'scheduled') {
        return 'secondary';
    }

    return 'outline';
}

async function loadIndexData() {
    loading.value = true;
    errorText.value = '';

    try {
        await ensureTeam();
        const [webinarPayload, liveVideoPayload, summaryPayload] = await Promise.all([
            getList<WebinarItem>('/api/v1/admin/live-shows'),
            apiFetch<{ data?: LiveVideoConversation[] }>('/api/v1/admin/live-video-chats'),
            apiFetch<{
                data: {
                    webinar_chats_count: number;
                    live_video_chats_count: number;
                    total_chats_count: number;
                };
            }>('/api/v1/admin/chats/summary'),
        ]);

        webinars.value = webinarPayload.data ?? [];
        liveVideos.value = liveVideoPayload.data ?? [];
        chatSummary.value = summaryPayload.data ?? chatSummary.value;
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load chat categories.';
    } finally {
        loading.value = false;
    }
}

onMounted(loadIndexData);
</script>

<template>
    <Head title="Chats" />

    <div class="chats-hub-root flex h-full min-h-0 flex-1 flex-col gap-5 p-4 md:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <div class="page-icon flex size-10 shrink-0 items-center justify-center rounded-xl">
                    <MessageSquare class="size-5 text-white" />
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-[#E8563A]">Live Commerce</p>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900">Chats</h1>
                    <p class="mt-0.5 text-sm text-gray-500">
                        Reply to webinar attendees and live video viewers from one inbox.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" class="ghost-btn" as-child>
                    <Link href="/live-shows">Back to live casts</Link>
                </Button>
                <Button variant="outline" size="sm" class="ghost-btn" :disabled="loading" @click="loadIndexData">
                    <RefreshCw class="mr-1.5 size-3.5" :class="{ 'animate-spin': loading }" />
                    {{ loading ? 'Refreshing…' : 'Refresh' }}
                </Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="stat-card rounded-2xl p-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Webinar chats</p>
                    <div class="stat-icon flex size-9 items-center justify-center rounded-xl">
                        <Tv class="size-4 text-[#E8563A]" />
                    </div>
                </div>
                <p class="mt-1 text-3xl font-black text-gray-900">
                    {{ chatSummary.webinar_chats_count.toLocaleString() }}
                </p>
            </div>
            <div class="stat-card rounded-2xl p-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Live video chats</p>
                    <div class="stat-icon flex size-9 items-center justify-center rounded-xl">
                        <Video class="size-4 text-[#E8563A]" />
                    </div>
                </div>
                <p class="mt-1 text-3xl font-black text-gray-900">
                    {{ chatSummary.live_video_chats_count.toLocaleString() }}
                </p>
            </div>
            <div class="stat-card rounded-2xl p-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Total chats</p>
                    <div class="stat-icon flex size-9 items-center justify-center rounded-xl">
                        <MessageSquare class="size-4 text-[#E8563A]" />
                    </div>
                </div>
                <p class="mt-1 text-3xl font-black text-[#E8563A]">
                    {{ chatSummary.total_chats_count.toLocaleString() }}
                </p>
            </div>
        </div>

        <div
            v-if="errorText"
            class="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            <XCircle class="size-4 shrink-0" />
            {{ errorText }}
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <section class="category-card category-card-panel flex flex-col rounded-2xl">
                <div class="flex shrink-0 items-center justify-between gap-3 border-b border-[#F0EDE8] px-4 py-3">
                    <div class="flex items-center gap-2">
                        <div class="stat-icon flex size-8 items-center justify-center rounded-lg">
                            <Tv class="size-4 text-[#E8563A]" />
                        </div>
                        <div>
                            <p class="text-sm font-bold text-gray-900">Webinar chats</p>
                            <p class="text-xs text-gray-500">In-call room messages from registered attendees</p>
                        </div>
                    </div>
                    <span class="rounded-full bg-[#E8563A]/10 px-2.5 py-0.5 text-xs font-bold text-[#E8563A]">
                        {{ webinars.length }}
                    </span>
                </div>

                <div class="category-card-body p-3">
                    <div v-if="loading" class="space-y-2">
                        <Skeleton v-for="n in 5" :key="n" class="h-14 rounded-xl" />
                    </div>
                    <div
                        v-else-if="webinars.length === 0"
                        class="flex min-h-40 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-[#F0EDE8] px-4 py-10 text-center text-sm text-gray-500"
                    >
                        <Film class="size-8 text-[#E8563A]/40" />
                        <p>No webinars yet.</p>
                        <Button size="sm" variant="outline" class="ghost-btn" as-child>
                            <Link href="/live-shows">Create a webinar</Link>
                        </Button>
                    </div>
                    <div v-else class="space-y-2">
                        <Link
                            v-for="item in webinars"
                            :key="item.id"
                            :href="`/live-shows/chats/webinars/${item.id}`"
                            class="list-row flex items-center justify-between rounded-xl border border-[#F0EDE8] bg-white px-3 py-3 transition-colors hover:border-[#E8563A]/35 hover:bg-[#E8563A]/5"
                        >
                            <div class="min-w-0 pr-3">
                                <p class="truncate font-semibold text-gray-900">{{ item.title }}</p>
                                <p class="mt-0.5 truncate text-xs text-gray-500">
                                    {{ item.host_name || 'Host' }}
                                    ·
                                    {{ item.conversations_count ?? 0 }}
                                    chat{{ (item.conversations_count ?? 0) === 1 ? '' : 's' }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <Badge :variant="statusVariant(item.status)" class="capitalize">
                                    {{ item.status }}
                                </Badge>
                                <ChevronRight class="size-4 text-gray-400" />
                            </div>
                        </Link>
                    </div>
                </div>
            </section>

            <section class="category-card category-card-panel flex flex-col rounded-2xl">
                <div class="flex shrink-0 items-center justify-between gap-3 border-b border-[#F0EDE8] px-4 py-3">
                    <div class="flex items-center gap-2">
                        <div class="stat-icon flex size-8 items-center justify-center rounded-lg">
                            <Video class="size-4 text-[#E8563A]" />
                        </div>
                        <div>
                            <p class="text-sm font-bold text-gray-900">Live video chats</p>
                            <p class="text-xs text-gray-500">Viewer comments on playlist and embed videos</p>
                        </div>
                    </div>
                    <span class="rounded-full bg-[#E8563A]/10 px-2.5 py-0.5 text-xs font-bold text-[#E8563A]">
                        {{ liveVideos.length }}
                    </span>
                </div>

                <div class="category-card-body p-3">
                    <div v-if="loading" class="space-y-2">
                        <Skeleton v-for="n in 5" :key="n" class="h-14 rounded-xl" />
                    </div>
                    <div
                        v-else-if="liveVideos.length === 0"
                        class="flex min-h-40 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-[#F0EDE8] px-4 py-10 text-center text-sm text-gray-500"
                    >
                        <Video class="size-8 text-[#E8563A]/40" />
                        <p>No live video chat threads yet.</p>
                    </div>
                    <div v-else class="space-y-2">
                        <Link
                            v-for="item in liveVideos"
                            :key="item.video_id"
                            :href="`/live-shows/chats/live-videos/${item.video_id}`"
                            class="list-row flex items-center justify-between rounded-xl border border-[#F0EDE8] bg-white px-3 py-3 transition-colors hover:border-[#E8563A]/35 hover:bg-[#E8563A]/5"
                        >
                            <div class="min-w-0 pr-3">
                                <p class="truncate font-semibold text-gray-900">
                                    {{ item.display_title || item.title }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    {{ item.conversations_count ?? 0 }}
                                    chat{{ (item.conversations_count ?? 0) === 1 ? '' : 's' }}
                                    · AI {{ item.ai_assistant_enabled ? 'on' : 'off' }}
                                </p>
                            </div>
                            <ChevronRight class="size-4 shrink-0 text-gray-400" />
                        </Link>
                    </div>
                </div>
            </section>
        </div>

        <section class="category-card rounded-2xl p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <div class="stat-icon flex size-8 items-center justify-center rounded-lg">
                        <MessageSquare class="size-4 text-[#E8563A]" />
                    </div>
                    <div>
                        <p class="text-sm font-bold text-gray-900">Other channels</p>
                        <p class="text-xs text-gray-500">Reserved for future chat sources (SMS, WhatsApp, etc.)</p>
                    </div>
                </div>
                <Badge variant="outline">Coming soon</Badge>
            </div>
        </section>
    </div>
</template>

<style scoped>
.chats-hub-root {
    background-color: #f2efea;
}

.page-icon {
    background: linear-gradient(135deg, #e8563a, #ff8c42);
    box-shadow: 0 4px 12px rgba(232, 86, 58, 0.35);
}

.stat-card,
.category-card {
    background: #fff;
    border: 1px solid #f0ede8;
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.04),
        0 4px 16px rgba(0, 0, 0, 0.06);
}

.category-card-panel {
    max-height: min(26rem, 48vh);
    min-height: 14rem;
}

.category-card-body {
    min-height: 0;
    flex: 1 1 auto;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.stat-icon {
    background: rgba(232, 86, 58, 0.1);
    box-shadow: inset 0 0 0 1px rgba(232, 86, 58, 0.12);
}

.ghost-btn {
    background: #fff;
    border-color: #e5e7eb;
    color: #4b5563;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

.ghost-btn:hover:not(:disabled) {
    border-color: rgba(232, 86, 58, 0.4);
    color: #e8563a;
    background: rgba(232, 86, 58, 0.04);
}

.list-row {
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
}
</style>
