<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Loader2,
    MessageSquare,
    RefreshCw,
    Search,
    Send,
    UserRound,
} from 'lucide-vue-next';
import { echo, echoIsConfigured } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ChatMessageBody from '@/components/chat/ChatMessageBody.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminApi } from '@/composables/useAdminApi';

type ChatSource = 'webinar' | 'live_video' | 'other';

type WebinarOption = {
    id: number;
    title: string;
    status: string;
    host_name?: string | null;
    messages_count?: number;
};

type WebinarConversation = {
    registration_id: number;
    full_name: string;
    email: string;
    last_message?: string | null;
    last_message_at?: string | null;
    messages_count: number;
};

type LiveVideoConversation = {
    video_id: number;
    title: string;
    last_message?: string | null;
    last_message_at?: string | null;
    messages_count: number;
    ai_assistant_enabled: boolean;
};

type ChatMessage = {
    id: number;
    live_show_registration_id?: number | null;
    sender_type: 'host' | 'attendee' | 'ai' | 'system';
    sender_name?: string | null;
    message: string;
    is_pinned: boolean;
    created_at: string;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Webinars', href: '/live-shows' },
            { title: 'Chats', href: '/live-shows/chats' },
        ],
    },
});

const page = usePage();
const { getList, apiFetch, postJson, ensureTeam } = useAdminApi();
const props = withDefaults(defineProps<{
    source?: ChatSource;
    webinarId?: number | null;
    registrationId?: number | null;
    videoId?: number | null;
    lockContext?: boolean;
}>(), {
    source: undefined,
    webinarId: null,
    registrationId: null,
    videoId: null,
    lockContext: false,
});

const sourceTab = ref<ChatSource>(props.source && props.source !== 'other' ? props.source : 'webinar');

const loadingWebinars = ref(false);
const loadingConversations = ref(false);
const loadingMessages = ref(false);
const sending = ref(false);
const errorText = ref('');
const backgroundSyncCount = ref(0);

const webinars = ref<WebinarOption[]>([]);
const webinarConversations = ref<WebinarConversation[]>([]);
const liveVideoConversations = ref<LiveVideoConversation[]>([]);
const messages = ref<ChatMessage[]>([]);
const conversationSearch = ref('');
const replyDraft = ref('');

const selectedWebinarId = ref<number | null>(null);
const selectedRegistrationId = ref<number | null>(null);
const selectedLiveVideoId = ref<number | null>(null);

let pollTimer: number | null = null;
let liveVideoEchoChannel: string | null = null;

function appendLiveVideoComment(payload: {
    comment?: {
        id?: number;
        body?: string;
        is_pinned?: boolean;
        created_at?: string;
        metadata?: {
            sender_type?: ChatMessage['sender_type'];
            sender_name?: string;
        };
    };
}) {
    const comment = payload.comment;

    if (!comment?.id || !comment.body) {
        return;
    }

    const metadata = comment.metadata ?? {};
    const senderType = metadata.sender_type ?? 'attendee';
    const message: ChatMessage = {
        id: comment.id,
        sender_type: senderType,
        sender_name:
            metadata.sender_name ??
            (senderType === 'host'
                ? 'Host'
                : senderType === 'ai'
                  ? 'AI Assistant'
                  : 'Viewer'),
        message: comment.body,
        is_pinned: Boolean(comment.is_pinned),
        created_at: comment.created_at ?? new Date().toISOString(),
    };

    if (messages.value.some((existing) => existing.id === message.id)) {
        return;
    }

    messages.value.push(message);
}

function leaveLiveVideoEchoChannel() {
    if (!liveVideoEchoChannel || !echoIsConfigured()) {
        liveVideoEchoChannel = null;

        return;
    }

    echo().leave(liveVideoEchoChannel);
    liveVideoEchoChannel = null;
}

watch([sourceTab, selectedLiveVideoId], () => {
    leaveLiveVideoEchoChannel();

    if (
        !echoIsConfigured() ||
        sourceTab.value !== 'live_video' ||
        !selectedLiveVideoId.value
    ) {
        return;
    }

    liveVideoEchoChannel = `video.${selectedLiveVideoId.value}`;
    echo()
        .channel(liveVideoEchoChannel)
        .listen('.comment.created', appendLiveVideoComment);
}, { immediate: true });

const initialWebinarId = computed(() => {
    if (props.webinarId && props.webinarId > 0) {
        return props.webinarId;
    }

    const params = new URLSearchParams(window.location.search);
    const id = Number(params.get('webinar') || 0);

    return id > 0 ? id : null;
});

const initialRegistrationId = computed(() => {
    if (props.registrationId && props.registrationId > 0) {
        return props.registrationId;
    }

    const params = new URLSearchParams(window.location.search);
    const id = Number(params.get('registration') || 0);

    return id > 0 ? id : null;
});

const selectedWebinar = computed(() =>
    webinars.value.find((w) => w.id === selectedWebinarId.value) ?? null,
);

const selectedWebinarConversation = computed(() =>
    webinarConversations.value.find((c) => c.registration_id === selectedRegistrationId.value) ?? null,
);

const selectedLiveVideoConversation = computed(() =>
    liveVideoConversations.value.find((c) => c.video_id === selectedLiveVideoId.value) ?? null,
);
const backHref = computed(() => (props.lockContext ? '/live-shows/chats' : '/live-shows'));

const isBackgroundSyncing = computed(() => backgroundSyncCount.value > 0);
const sourceStats = computed(() => {
    const webinarThreads = webinarConversations.value.length;
    const liveVideoThreads = liveVideoConversations.value.length;

    return [
        {
            key: 'webinar' as ChatSource,
            label: 'Webinar chats',
            description: 'Registrations + room chat',
            count: webinarThreads,
        },
        {
            key: 'live_video' as ChatSource,
            label: 'Live video chat',
            description: 'Playlist/feed comment chat',
            count: liveVideoThreads,
        },
        {
            key: 'other' as ChatSource,
            label: 'Other',
            description: 'Future channels',
            count: 0,
        },
    ];
});

const filteredWebinarConversations = computed(() => {
    const q = conversationSearch.value.trim().toLowerCase();

    if (!q) {
        return webinarConversations.value;
    }

    return webinarConversations.value.filter(
        (c) =>
            c.full_name.toLowerCase().includes(q) ||
            c.email.toLowerCase().includes(q) ||
            (c.last_message?.toLowerCase().includes(q) ?? false),
    );
});

const filteredLiveVideoConversations = computed(() => {
    const q = conversationSearch.value.trim().toLowerCase();

    if (!q) {
        return liveVideoConversations.value;
    }

    return liveVideoConversations.value.filter(
        (c) =>
            c.title.toLowerCase().includes(q) ||
            (c.last_message?.toLowerCase().includes(q) ?? false),
    );
});

function formatTime(value?: string | null): string {
    if (!value) {
        return '';
    }

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) {
        return '';
    }

    const now = new Date();
    const isToday = d.toDateString() === now.toDateString();

    if (isToday) {
        return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }

    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function initials(name: string): string {
    return name
        .split(' ')
        .map((p) => p[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

function beginBackgroundSync(silent: boolean): () => void {
    if (!silent) {
        return () => undefined;
    }

    backgroundSyncCount.value += 1;

    return () => {
        backgroundSyncCount.value = Math.max(0, backgroundSyncCount.value - 1);
    };
}

async function loadWebinars() {
    loadingWebinars.value = true;
    errorText.value = '';

    try {
        await ensureTeam();
        const payload = await getList<WebinarOption>('/api/v1/admin/live-shows');
        webinars.value = payload.data ?? [];

        if (!selectedWebinarId.value && webinars.value.length > 0) {
            selectedWebinarId.value = initialWebinarId.value ?? webinars.value[0].id;
        }
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not load webinars.';
    } finally {
        loadingWebinars.value = false;
    }
}

async function loadConversations(options: { silent?: boolean } = {}) {
    if (sourceTab.value !== 'webinar') {
        webinarConversations.value = [];
        return;
    }

    if (!selectedWebinarId.value) {
        webinarConversations.value = [];

        return;
    }

    const silent = options.silent ?? false;
    const endBackgroundSync = beginBackgroundSync(silent);

    if (!silent) {
        loadingConversations.value = true;
    }

    try {
        const payload = await apiFetch<{ data: WebinarConversation[] }>(
            `/api/v1/admin/live-shows/${selectedWebinarId.value}/conversations`,
        );
        webinarConversations.value = payload.data ?? [];

        if (
            selectedRegistrationId.value &&
            !webinarConversations.value.some((c) => c.registration_id === selectedRegistrationId.value)
        ) {
            selectedRegistrationId.value = webinarConversations.value[0]?.registration_id ?? null;
        } else if (!selectedRegistrationId.value && webinarConversations.value.length > 0) {
            selectedRegistrationId.value =
                initialRegistrationId.value ?? webinarConversations.value[0].registration_id;
        }
    } catch (error) {
        if (!silent) {
            errorText.value = error instanceof Error ? error.message : 'Could not load conversations.';
        }
    } finally {
        if (!silent) {
            loadingConversations.value = false;
        }

        endBackgroundSync();
    }
}

async function loadLiveVideoConversations(options: { silent?: boolean } = {}) {
    if (sourceTab.value === 'other') {
        liveVideoConversations.value = [];
        return;
    }

    const silent = options.silent ?? false;
    const endBackgroundSync = beginBackgroundSync(silent);

    if (!silent) {
        loadingConversations.value = true;
    }

    try {
        const payload = await apiFetch<{ data: LiveVideoConversation[] }>(
            '/api/v1/admin/live-video-chats',
        );
        liveVideoConversations.value = payload.data ?? [];

        if (
            selectedLiveVideoId.value &&
            !liveVideoConversations.value.some((c) => c.video_id === selectedLiveVideoId.value)
        ) {
            selectedLiveVideoId.value = liveVideoConversations.value[0]?.video_id ?? null;
        } else if (!selectedLiveVideoId.value && liveVideoConversations.value.length > 0) {
            selectedLiveVideoId.value = liveVideoConversations.value[0].video_id;
        }
    } catch (error) {
        if (!silent) {
            errorText.value = error instanceof Error ? error.message : 'Could not load live video chats.';
        }
    } finally {
        if (!silent) {
            loadingConversations.value = false;
        }
        endBackgroundSync();
    }
}

async function loadMessages(options: { silent?: boolean } = {}) {
    const selectedConversationId =
        sourceTab.value === 'webinar' ? selectedRegistrationId.value : selectedLiveVideoId.value;

    if (!selectedConversationId) {
        messages.value = [];

        return;
    }

    const silent = options.silent ?? false;
    const endBackgroundSync = beginBackgroundSync(silent);

    if (!silent) {
        loadingMessages.value = true;
    }

    try {
        const payload = sourceTab.value === 'webinar'
            ? await apiFetch<{ data: ChatMessage[] }>(
                `/api/v1/admin/live-shows/${selectedWebinarId.value}/messages?registration_id=${selectedRegistrationId.value}`,
            )
            : await apiFetch<{ data: ChatMessage[] }>(
                `/api/v1/admin/live-video-chats/${selectedLiveVideoId.value}/messages`,
            );
        messages.value = payload.data ?? [];
    } catch (error) {
        if (!silent) {
            errorText.value = error instanceof Error ? error.message : 'Could not load messages.';
        }
    } finally {
        if (!silent) {
            loadingMessages.value = false;
        }

        endBackgroundSync();
    }
}

async function refreshAll() {
    await loadWebinars();
    await loadLiveVideoConversations();
    if (sourceTab.value === 'webinar') {
        await loadConversations();
    }
    if (sourceTab.value === 'live_video') {
        await loadLiveVideoConversations();
    }
    if (sourceTab.value !== 'other') {
        await loadMessages();
    }
}

function selectWebinar(id: number) {
    selectedWebinarId.value = id;
    selectedRegistrationId.value = null;
    webinarConversations.value = [];
    messages.value = [];
}

function selectSource(source: ChatSource) {
    if (props.lockContext) return;
    sourceTab.value = source;
    conversationSearch.value = '';
    messages.value = [];
}

function selectLiveVideo(videoId: number) {
    selectedLiveVideoId.value = videoId;
    messages.value = [];
}

function selectConversation(registrationId: number) {
    selectedRegistrationId.value = registrationId;
}

async function sendReply() {
    if (!replyDraft.value.trim()) {
        return;
    }

    if (sourceTab.value === 'webinar' && (!selectedWebinarId.value || !selectedRegistrationId.value)) {
        return;
    }

    if (sourceTab.value === 'live_video' && !selectedLiveVideoId.value) {
        return;
    }

    sending.value = true;

    try {
        const hostName = (page.props.auth?.user as { name?: string } | undefined)?.name
            || selectedWebinar.value?.host_name
            || 'Host';
        const payload = sourceTab.value === 'webinar'
            ? await postJson<{ data: ChatMessage }>(
                `/api/v1/admin/live-shows/${selectedWebinarId.value}/messages`,
                {
                    registration_id: selectedRegistrationId.value,
                    sender_name: hostName,
                    message: replyDraft.value.trim(),
                },
            )
            : await postJson<{ data: ChatMessage }>(
                `/api/v1/admin/live-video-chats/${selectedLiveVideoId.value}/messages`,
                {
                    sender_name: hostName,
                    message: replyDraft.value.trim(),
                },
            );

        if (payload?.data) {
            messages.value.push(payload.data);
        }

        replyDraft.value = '';
        if (sourceTab.value === 'webinar') {
            await loadConversations({ silent: true });
        } else if (sourceTab.value === 'live_video') {
            await loadLiveVideoConversations({ silent: true });
        }
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not send reply.';
    } finally {
        sending.value = false;
    }
}

watch(selectedWebinarId, async () => {
    if (sourceTab.value !== 'webinar') return;
    await loadConversations();
});

watch(selectedRegistrationId, async () => {
    if (sourceTab.value !== 'webinar') return;
    await loadMessages();
});

watch(selectedLiveVideoId, async () => {
    if (sourceTab.value !== 'live_video') return;
    await loadMessages();
});

watch(sourceTab, async (nextSource) => {
    messages.value = [];
    if (nextSource === 'webinar') {
        if (!selectedWebinarId.value && webinars.value.length > 0) {
            selectedWebinarId.value = webinars.value[0].id;
        }
        await loadConversations();
        await loadMessages();
        return;
    }

    if (nextSource === 'live_video') {
        await loadLiveVideoConversations();
        await loadMessages();
        return;
    }
});

onMounted(async () => {
    selectedWebinarId.value = initialWebinarId.value;
    selectedRegistrationId.value = initialRegistrationId.value;
    selectedLiveVideoId.value = props.videoId && props.videoId > 0 ? props.videoId : null;
    await loadWebinars();
    await loadLiveVideoConversations();
    if (sourceTab.value === 'webinar') {
        await loadConversations();
    }
    if (sourceTab.value === 'live_video') {
        await loadMessages();
    }
    if (sourceTab.value === 'webinar') {
        await loadMessages();
    }
    pollTimer = window.setInterval(async () => {
        if (sourceTab.value === 'webinar' && selectedWebinarId.value && selectedRegistrationId.value) {
            await loadMessages({ silent: true });
            await loadConversations({ silent: true });
        }
        if (sourceTab.value === 'live_video' && selectedLiveVideoId.value) {
            await loadMessages({ silent: true });
            await loadLiveVideoConversations({ silent: true });
        }
    }, 5000);
});

onBeforeUnmount(() => {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
    }

    leaveLiveVideoEchoChannel();
});
</script>

<template>
    <Head title="Live Chat Inbox" />

    <div class="chats-root flex h-[calc(100vh-4rem)] flex-col gap-0 p-4 md:p-5">
        <!-- Top bar -->
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Button variant="ghost" size="icon" class="ghost-icon" as-child>
                    <Link :href="backHref">
                        <ArrowLeft class="size-4" />
                    </Link>
                </Button>
                <div class="page-icon flex size-10 items-center justify-center rounded-xl">
                    <MessageSquare class="size-5 text-white" />
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-[#E8563A]">Live Commerce</p>
                    <h1 class="text-xl font-black tracking-tight text-gray-900">Live Chat Inbox</h1>
                    <p class="text-sm text-gray-500">
                        Unified chat across webinar rooms and live video feeds.
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="sync-pill inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-bold text-gray-600">
                    <span
                        class="size-2 rounded-full bg-emerald-500"
                        :class="{ 'animate-pulse': isBackgroundSyncing }"
                    />
                    {{ isBackgroundSyncing ? 'Syncing' : 'Live sync' }}
                </span>
                <Button variant="outline" size="sm" class="ghost-btn" :disabled="loadingWebinars" @click="refreshAll">
                    <RefreshCw class="mr-1.5 size-3.5" :class="{ 'animate-spin': loadingWebinars }" />
                    Refresh
                </Button>
            </div>
        </div>

        <div
            v-if="errorText"
            class="mb-3 rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700"
        >
            {{ errorText }}
        </div>

        <!-- Source groups -->
        <div v-if="!props.lockContext" class="mb-3 grid gap-2 sm:grid-cols-3">
            <button
                v-for="source in sourceStats"
                :key="source.key"
                type="button"
                :class="[
                    'rounded-xl border px-3 py-3 text-left transition-colors',
                    sourceTab === source.key
                        ? 'border-[#E8563A] bg-[#E8563A]/8'
                        : 'border-gray-200 bg-white hover:border-[#E8563A]/40',
                ]"
                @click="selectSource(source.key)"
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm font-bold text-gray-900">{{ source.label }}</p>
                    <span class="rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-gray-600">
                        {{ source.count }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-gray-500">{{ source.description }}</p>
            </button>
        </div>

        <!-- Webinar selector -->
        <div v-if="sourceTab === 'webinar' && !props.lockContext" class="mb-3 flex flex-wrap items-center gap-2">
            <span class="text-xs font-bold uppercase tracking-wide text-gray-500">Webinar</span>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="webinar in webinars"
                    :key="webinar.id"
                    type="button"
                    :class="[
                        'rounded-full border px-3 py-1 text-xs font-semibold transition-colors',
                        selectedWebinarId === webinar.id
                            ? 'border-[#E8563A] bg-[#E8563A] text-white shadow-sm shadow-[#E8563A]/20'
                            : 'border-gray-200 bg-white text-gray-600 hover:border-[#E8563A]/40 hover:text-[#E8563A]',
                    ]"
                    @click="selectWebinar(webinar.id)"
                >
                    {{ webinar.title }}
                </button>
            </div>
        </div>

        <!-- WhatsApp-style panel -->
        <div class="chat-shell flex min-h-0 flex-1 overflow-hidden rounded-2xl">
            <!-- Left: conversation list -->
            <aside class="flex w-full max-w-sm flex-col border-r border-[#F0EDE8] bg-[#FAF8F5] md:w-80 lg:w-96">
                <div class="border-b border-[#F0EDE8] bg-white p-3">
                    <div class="relative">
                        <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                        <Input
                            v-model="conversationSearch"
                            placeholder="Search chats..."
                            class="search-input pl-9"
                        />
                    </div>
                </div>

                <div v-if="loadingConversations && ((sourceTab === 'webinar' && webinarConversations.length === 0) || (sourceTab === 'live_video' && liveVideoConversations.length === 0))" class="space-y-2 p-3">
                    <Skeleton v-for="n in 8" :key="n" class="h-14 rounded-lg" />
                </div>

                <div
                    v-else-if="sourceTab === 'other'"
                    class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center text-sm text-gray-500"
                >
                    <div class="empty-icon flex size-14 items-center justify-center rounded-2xl">
                        <MessageSquare class="size-7 text-[#E8563A]" />
                    </div>
                    <p>No channels configured yet.</p>
                    <p class="text-xs">This section is ready for future chat sources.</p>
                </div>

                <div
                    v-else-if="sourceTab === 'webinar' && filteredWebinarConversations.length === 0"
                    class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center text-sm text-gray-500"
                >
                    <div class="empty-icon flex size-14 items-center justify-center rounded-2xl">
                        <MessageSquare class="size-7 text-[#E8563A]" />
                    </div>
                    <p>No chats yet for this webinar.</p>
                    <p class="text-xs">Attendees appear here after they register and send a message.</p>
                </div>

                <div
                    v-else-if="sourceTab === 'live_video' && filteredLiveVideoConversations.length === 0"
                    class="flex flex-1 flex-col items-center justify-center gap-2 p-6 text-center text-sm text-gray-500"
                >
                    <div class="empty-icon flex size-14 items-center justify-center rounded-2xl">
                        <MessageSquare class="size-7 text-[#E8563A]" />
                    </div>
                    <p>No live video chat yet.</p>
                    <p class="text-xs">Viewer comments on playlist/embed videos will appear here.</p>
                </div>

                <div v-else class="flex-1 overflow-y-auto">
                    <button
                        v-for="conv in filteredWebinarConversations"
                        v-if="sourceTab === 'webinar'"
                        :key="conv.registration_id"
                        type="button"
                        :class="[
                            'flex w-full items-center gap-3 border-b border-[#F0EDE8] px-3 py-3 text-left transition-colors hover:bg-white',
                            selectedRegistrationId === conv.registration_id ? 'bg-[#E8563A]/5' : '',
                        ]"
                        @click="selectConversation(conv.registration_id)"
                    >
                        <div class="avatar-chip flex size-11 shrink-0 items-center justify-center rounded-full text-sm font-bold">
                            {{ initials(conv.full_name) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate font-semibold text-gray-900">{{ conv.full_name }}</p>
                                <span class="shrink-0 text-[10px] text-gray-400">
                                    {{ formatTime(conv.last_message_at) }}
                                </span>
                            </div>
                            <p class="truncate text-xs text-gray-500">{{ conv.email }}</p>
                            <p class="mt-0.5 truncate text-xs text-gray-500">
                                {{ conv.last_message || 'No messages yet' }}
                            </p>
                        </div>
                        <Badge
                            v-if="conv.messages_count > 0"
                            variant="secondary"
                            class="message-count shrink-0 text-[10px]"
                        >
                            {{ conv.messages_count }}
                        </Badge>
                    </button>
                    <button
                        v-for="conv in filteredLiveVideoConversations"
                        v-if="sourceTab === 'live_video'"
                        :key="conv.video_id"
                        type="button"
                        :class="[
                            'flex w-full items-center gap-3 border-b border-[#F0EDE8] px-3 py-3 text-left transition-colors hover:bg-white',
                            selectedLiveVideoId === conv.video_id ? 'bg-[#E8563A]/5' : '',
                        ]"
                        @click="selectLiveVideo(conv.video_id)"
                    >
                        <div class="avatar-chip flex size-11 shrink-0 items-center justify-center rounded-full text-sm font-bold">
                            {{ initials(conv.title) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate font-semibold text-gray-900">{{ conv.title }}</p>
                                <span class="shrink-0 text-[10px] text-gray-400">
                                    {{ formatTime(conv.last_message_at) }}
                                </span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-gray-500">
                                {{ conv.last_message || 'No messages yet' }}
                            </p>
                            <p class="mt-0.5 text-[10px] text-gray-400">
                                AI {{ conv.ai_assistant_enabled ? 'enabled' : 'disabled' }}
                            </p>
                        </div>
                        <Badge
                            v-if="conv.messages_count > 0"
                            variant="secondary"
                            class="message-count shrink-0 text-[10px]"
                        >
                            {{ conv.messages_count }}
                        </Badge>
                    </button>
                </div>
            </aside>

            <!-- Right: message thread -->
            <section class="message-panel flex min-w-0 flex-1 flex-col">
                <template v-if="(sourceTab === 'webinar' && !selectedRegistrationId) || (sourceTab === 'live_video' && !selectedLiveVideoId) || sourceTab === 'other'">
                    <div class="flex flex-1 flex-col items-center justify-center gap-3 text-gray-500">
                        <div class="empty-icon flex size-16 items-center justify-center rounded-full">
                            <MessageSquare class="size-8 text-[#E8563A]" />
                        </div>
                        <p class="text-sm">Select a conversation to view messages</p>
                    </div>
                </template>

                <template v-else>
                    <!-- Thread header -->
                    <div class="flex items-center gap-3 border-b border-[#F0EDE8] bg-white px-4 py-3">
                        <div class="avatar-chip flex size-10 items-center justify-center rounded-full text-sm font-bold">
                            {{ initials(sourceTab === 'webinar' ? (selectedWebinarConversation?.full_name ?? 'A') : (selectedLiveVideoConversation?.title ?? 'L')) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-gray-900">
                                {{ sourceTab === 'webinar' ? selectedWebinarConversation?.full_name : selectedLiveVideoConversation?.title }}
                            </p>
                            <p class="truncate text-xs text-gray-500">
                                {{ sourceTab === 'webinar' ? selectedWebinarConversation?.email : 'Live video comments channel' }}
                            </p>
                        </div>
                        <Button variant="outline" size="sm" class="ghost-btn" as-child>
                            <a
                                v-if="sourceTab === 'webinar' && selectedWebinarId"
                                :href="`/webinars/${selectedWebinarId}/room`"
                                target="_blank"
                                rel="noreferrer"
                            >
                                Open room
                            </a>
                        </Button>
                    </div>

                    <!-- Messages -->
                    <div class="message-scroll flex-1 space-y-2 overflow-y-auto p-4">
                        <div v-if="loadingMessages && messages.length === 0" class="space-y-2">
                            <Skeleton v-for="n in 6" :key="n" class="h-12 w-2/3 rounded-2xl" />
                        </div>
                        <div
                            v-else-if="messages.length === 0"
                            class="py-12 text-center text-sm text-gray-500"
                        >
                            No messages in this chat yet.
                        </div>
                        <div
                            v-for="msg in messages"
                            :key="msg.id"
                            :class="[
                                'flex',
                                msg.sender_type === 'attendee' ? 'justify-start' : 'justify-end',
                            ]"
                        >
                            <div
                                :class="[
                                    'max-w-[75%] rounded-2xl px-3 py-2 shadow-sm',
                                    msg.sender_type === 'attendee'
                                        ? 'rounded-tl-sm bg-white text-gray-900'
                                        : msg.sender_type === 'ai'
                                          ? 'rounded-tr-sm bg-[#E8563A]/10 text-gray-900'
                                          : 'rounded-tr-sm bg-[#E8563A] text-white',
                                ]"
                            >
                                <p
                                    v-if="msg.sender_type !== 'attendee'"
                                    :class="[
                                        'mb-0.5 text-[10px] font-medium',
                                        msg.sender_type === 'host' ? 'text-white/70' : 'text-gray-500',
                                    ]"
                                >
                                    {{ msg.sender_name || msg.sender_type }}
                                </p>
                                <p class="text-sm leading-relaxed">
                                    <ChatMessageBody
                                        :text="msg.message"
                                        :variant="msg.sender_type === 'host' ? 'on-primary' : 'default'"
                                    />
                                </p>
                                <p :class="[
                                    'mt-1 text-right text-[10px]',
                                    msg.sender_type === 'host' ? 'text-white/70' : 'text-gray-500',
                                ]">
                                    {{ formatTime(msg.created_at) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Reply box -->
                    <div class="border-t border-[#F0EDE8] bg-white p-3">
                        <div class="flex items-end gap-2">
                            <div class="avatar-chip flex size-9 shrink-0 items-center justify-center rounded-full">
                                <UserRound class="size-4" />
                            </div>
                            <textarea
                                v-model="replyDraft"
                                rows="1"
                                class="reply-input max-h-28 min-h-[40px] flex-1 resize-none rounded-2xl border px-4 py-2.5 text-sm focus:outline-none"
                                placeholder="Type a reply… Paste a link to share it."
                                @keydown.enter.exact.prevent="sendReply"
                            />
                            <Button
                                size="icon"
                                class="send-btn size-10 shrink-0 rounded-full"
                                :disabled="sending || !replyDraft.trim()"
                                @click="sendReply"
                            >
                                <Loader2 v-if="sending" class="size-4 animate-spin" />
                                <Send v-else class="size-4" />
                            </Button>
                        </div>
                        <p class="mt-2 text-center text-[10px] text-gray-400">
                            Your reply is sent as the host and appears live in the selected chat source.
                        </p>
                    </div>
                </template>
            </section>
        </div>
    </div>
</template>

<style scoped>
.chats-root {
    background-color: #F2EFEA;
}

.page-icon {
    background: linear-gradient(135deg, #E8563A, #ff8c42);
    box-shadow: 0 4px 12px rgba(232,86,58,0.35);
}

.chat-shell {
    background: #fff;
    border: 1px solid #F0EDE8;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 18px rgba(0,0,0,0.07);
}

.message-panel {
    background:
        radial-gradient(circle at 20% 20%, rgba(232,86,58,0.06), transparent 28%),
        radial-gradient(circle at 80% 0%, rgba(255,140,66,0.07), transparent 30%),
        #F8F5F0;
}

.message-scroll {
    background-image:
        linear-gradient(rgba(255,255,255,0.45) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.45) 1px, transparent 1px);
    background-size: 28px 28px;
}

.ghost-icon,
.ghost-btn {
    background: #fff;
    border-color: #e5e7eb;
    color: #4b5563;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.ghost-icon:hover,
.ghost-btn:hover:not(:disabled) {
    border-color: rgba(232,86,58,0.40);
    color: #E8563A;
    background: rgba(232,86,58,0.04);
}

.sync-pill {
    background: rgba(255,255,255,0.78);
    border: 1px solid #F0EDE8;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.search-input,
.reply-input {
    border-color: #e5e7eb;
    background: #fff;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.search-input:focus,
.reply-input:focus {
    border-color: #E8563A;
    box-shadow: 0 0 0 3px rgba(232,86,58,0.10);
}

.avatar-chip,
.empty-icon {
    background: rgba(232,86,58,0.10);
    color: #E8563A;
    box-shadow: inset 0 0 0 1px rgba(232,86,58,0.12);
}

.message-count {
    background: rgba(232,86,58,0.10);
    color: #E8563A;
    border-radius: 9999px;
    font-weight: 700;
}

.send-btn {
    background: #E8563A;
    color: #fff;
    box-shadow: 0 4px 14px rgba(232,86,58,0.30);
    transition: all 0.15s;
}
.send-btn:hover:not(:disabled) {
    background: #D44A2F;
    box-shadow: 0 6px 18px rgba(232,86,58,0.40);
    transform: translateY(-1px);
}
.send-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
</style>
