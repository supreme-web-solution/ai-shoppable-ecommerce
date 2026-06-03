<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, MessageSquare, Tv, Video, Loader2 } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useAdminApi } from '@/composables/useAdminApi';

type WebinarItem = {
    id: number;
    title: string;
    status: string;
    messages_count?: number;
};

type LiveVideoConversation = {
    video_id: number;
    title: string;
    messages_count: number;
    ai_assistant_enabled: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Live Shows', href: '/live-shows' },
            { title: 'Chats', href: '/live-shows/chats' },
        ],
    },
});

const { getList, apiFetch, ensureTeam } = useAdminApi();

const loading = ref(false);
const errorText = ref('');
const webinars = ref<WebinarItem[]>([]);
const liveVideos = ref<LiveVideoConversation[]>([]);

async function loadIndexData() {
    loading.value = true;
    errorText.value = '';

    try {
        await ensureTeam();
        const [webinarPayload, liveVideoPayload] = await Promise.all([
            getList<WebinarItem>('/api/v1/admin/live-shows'),
            apiFetch<{ data?: LiveVideoConversation[] }>('/api/v1/admin/live-video-chats'),
        ]);

        webinars.value = webinarPayload.data ?? [];
        liveVideos.value = liveVideoPayload.data ?? [];
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load chat categories.';
    } finally {
        loading.value = false;
    }
}

onMounted(loadIndexData);
</script>

<template>
    <Head title="Chat Categories" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4 md:p-5">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black tracking-tight text-gray-900">Chat Categories</h1>
                <p class="text-sm text-gray-500">Pick a category, then open a dedicated chat detail page.</p>
            </div>
            <Button variant="outline" :disabled="loading" @click="loadIndexData">
                <Loader2 v-if="loading" class="mr-2 size-4 animate-spin" />
                Refresh
            </Button>
        </div>

        <div
            v-if="errorText"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            {{ errorText }}
        </div>

        <details class="rounded-2xl border bg-white p-4 shadow-sm" open>
            <summary class="cursor-pointer list-none">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <Tv class="size-4 text-[#E8563A]" />
                        <p class="text-sm font-bold text-gray-900">Webinar chats</p>
                    </div>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">
                        {{ webinars.length }}
                    </span>
                </div>
            </summary>

            <div class="mt-3 space-y-2">
                <div
                    v-if="!loading && webinars.length === 0"
                    class="rounded-xl border border-dashed px-3 py-4 text-sm text-gray-500"
                >
                    No webinars available yet.
                </div>
                <Link
                    v-for="item in webinars"
                    :key="item.id"
                    :href="`/live-shows/chats/webinars/${item.id}`"
                    class="flex items-center justify-between rounded-xl border px-3 py-2.5 text-sm hover:border-[#E8563A]/40 hover:bg-[#E8563A]/5"
                >
                    <div>
                        <p class="font-semibold text-gray-900">{{ item.title }}</p>
                        <p class="text-xs text-gray-500">{{ item.status }} · {{ item.messages_count || 0 }} messages</p>
                    </div>
                    <ChevronRight class="size-4 text-gray-400" />
                </Link>
            </div>
        </details>

        <details class="rounded-2xl border bg-white p-4 shadow-sm" open>
            <summary class="cursor-pointer list-none">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <Video class="size-4 text-[#E8563A]" />
                        <p class="text-sm font-bold text-gray-900">Live video chats</p>
                    </div>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">
                        {{ liveVideos.length }}
                    </span>
                </div>
            </summary>

            <div class="mt-3 space-y-2">
                <div
                    v-if="!loading && liveVideos.length === 0"
                    class="rounded-xl border border-dashed px-3 py-4 text-sm text-gray-500"
                >
                    No live video chat threads yet.
                </div>
                <Link
                    v-for="item in liveVideos"
                    :key="item.video_id"
                    :href="`/live-shows/chats/live-videos/${item.video_id}`"
                    class="flex items-center justify-between rounded-xl border px-3 py-2.5 text-sm hover:border-[#E8563A]/40 hover:bg-[#E8563A]/5"
                >
                    <div>
                        <p class="font-semibold text-gray-900">{{ item.title }}</p>
                        <p class="text-xs text-gray-500">
                            {{ item.messages_count }} messages · AI {{ item.ai_assistant_enabled ? 'enabled' : 'disabled' }}
                        </p>
                    </div>
                    <ChevronRight class="size-4 text-gray-400" />
                </Link>
            </div>
        </details>

        <details class="rounded-2xl border bg-white p-4 shadow-sm">
            <summary class="cursor-pointer list-none">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <MessageSquare class="size-4 text-[#E8563A]" />
                        <p class="text-sm font-bold text-gray-900">Other channels</p>
                    </div>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">0</span>
                </div>
            </summary>
            <p class="mt-3 text-sm text-gray-500">Reserved for future chat sources.</p>
        </details>
    </div>
</template>
