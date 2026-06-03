<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    Bot,
    Loader2,
    MessageSquare,
    Mic2,
    Pin,
    Send,
    Users,
    Video,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type WebinarProduct = {
    id: number;
    title: string;
    image_url?: string | null;
    price?: string | null;
    sale_price?: string | null;
    currency?: string;
};

type WebinarData = {
    id: number;
    title: string;
    host_name?: string | null;
    room_title?: string | null;
    video_url?: string | null;
    thumbnail_url?: string | null;
    chat_enabled?: boolean;
    featured_products?: WebinarProduct[];
};

type RoomMessage = {
    id: number;
    sender_type: 'host' | 'attendee' | 'ai' | 'system';
    sender_name?: string | null;
    message: string;
    is_pinned: boolean;
    created_at: string;
};

const page = usePage();
const webinarId = Number((page.props as Record<string, unknown>).webinarId ?? 0);
const registrationId = Number((page.props as Record<string, unknown>).registrationId ?? 0);

const loading = ref(false);
const posting = ref(false);
const errorText = ref('');
const webinar = ref<WebinarData | null>(null);
const messages = ref<RoomMessage[]>([]);
const messageDraft = ref('');
const pollRef = ref<number | null>(null);

const pinnedMessage = computed(() => messages.value.find((message) => message.is_pinned));

async function apiFetch<T>(url: string, options: RequestInit = {}): Promise<T> {
    const headers = new Headers(options.headers ?? {});
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const response = await fetch(url, {
        ...options,
        headers,
        credentials: 'same-origin',
    });

    if (response.status === 204) {
        return undefined as T;
    }

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        const message = payload && typeof payload === 'object' && 'message' in payload
            ? String((payload as { message: string }).message)
            : `Request failed (${response.status})`;

        throw new Error(message);
    }

    return payload as T;
}

function formatMoney(product: WebinarProduct): string {
    const value = product.sale_price || product.price;

    if (!value) {
return '';
}

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: product.currency || 'USD',
    }).format(Number(value));
}

async function loadRoom() {
    loading.value = true;
    errorText.value = '';

    try {
        const [webinarPayload, messagesPayload] = await Promise.all([
            apiFetch<{ data: WebinarData }>(`/api/v1/player/webinars/${webinarId}`),
            apiFetch<{ data: RoomMessage[] }>(`/api/v1/player/webinars/${webinarId}/messages`),
        ]);
        webinar.value = webinarPayload.data;
        messages.value = messagesPayload.data ?? [];
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not load webinar room.';
    } finally {
        loading.value = false;
    }
}

async function pollMessages() {
    const lastId = messages.value.length > 0 ? messages.value[messages.value.length - 1].id : 0;

    try {
        const payload = await apiFetch<{ data: RoomMessage[] }>(
            `/api/v1/player/webinars/${webinarId}/messages?after_id=${lastId}`,
        );

        if (payload.data?.length) {
            messages.value = [...messages.value, ...payload.data];
        }
    } catch {
        // Keep room stable even if a polling request fails.
    }
}

async function sendMessage() {
    if (!messageDraft.value.trim() || posting.value || !webinar.value?.chat_enabled) {
        return;
    }

    posting.value = true;

    try {
        const payload = await apiFetch<{ data: RoomMessage[] }>(
            `/api/v1/player/webinars/${webinarId}/messages`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    registration_id: registrationId,
                    message: messageDraft.value.trim(),
                }),
            },
        );

        if (payload.data?.length) {
            messages.value = [...messages.value, ...payload.data];
        }

        messageDraft.value = '';
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not send message.';
    } finally {
        posting.value = false;
    }
}

onMounted(async () => {
    await loadRoom();
    pollRef.value = window.setInterval(pollMessages, 5000);
});

onBeforeUnmount(() => {
    if (pollRef.value !== null) {
        window.clearInterval(pollRef.value);
    }
});
</script>

<template>
    <Head title="Webinar Room" />

    <div class="room-root min-h-screen p-4 md:p-6">
        <div class="mx-auto w-full max-w-[1380px] space-y-4">
            <!-- Header -->
            <header class="room-header flex flex-wrap items-center justify-between gap-3 rounded-3xl p-4">
                <div class="flex items-center gap-3">
                    <div class="brand-icon flex size-11 items-center justify-center rounded-2xl">
                        <Video class="size-5 text-white" />
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.22em] text-[#E8563A]">Live Commerce Room</p>
                        <h1 class="text-xl font-black tracking-tight text-gray-900">{{ webinar?.title || 'Webinar Room' }}</h1>
                        <p class="text-sm text-gray-500">Hosted by {{ webinar?.host_name || 'Host' }}</p>
                    </div>
                </div>

                <div class="live-pill inline-flex items-center gap-2 rounded-full px-4 py-2 text-xs font-black text-white">
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-white opacity-75" />
                        <span class="relative inline-flex size-2 rounded-full bg-white" />
                    </span>
                    LIVE NOW
                </div>
            </header>

            <div class="grid gap-4 lg:grid-cols-[1.75fr_1fr]">
                <!-- Video area -->
                <section class="video-card rounded-3xl p-3 md:p-4">
                    <div class="video-stage relative aspect-video overflow-hidden rounded-2xl bg-black">
                        <video
                            v-if="webinar?.video_url"
                            :src="webinar.video_url"
                            controls
                            playsinline
                            class="absolute inset-0 h-full w-full object-contain"
                            :poster="webinar.thumbnail_url || undefined"
                        />
                        <div
                            v-else
                            class="empty-video absolute inset-0 flex items-center justify-center"
                        >
                            <div class="text-center">
                                <Video class="mx-auto mb-2 size-10 text-white/60" />
                                <p class="text-sm font-semibold text-white/70">Video will appear here</p>
                            </div>
                        </div>

                        <div class="pointer-events-none absolute inset-x-0 top-0 bg-linear-to-b from-black/70 to-transparent p-4">
                            <div class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1.5 text-xs font-bold text-white backdrop-blur-md">
                                <Users class="size-3.5" />
                                Interactive live room
                            </div>
                        </div>

                        <div class="pointer-events-none absolute inset-x-0 bottom-0 bg-linear-to-t from-black/80 to-transparent p-4">
                            <div class="inline-flex rounded-full bg-white/90 px-4 py-2 text-sm font-bold text-gray-900 shadow-lg backdrop-blur-md">
                                <Mic2 class="mr-2 size-4 text-[#E8563A]" />
                                Click video controls to enable sound
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-2 overflow-x-auto">
                            <button
                                v-for="emoji in ['👍', '❤️', '🔥', '👏']"
                                :key="emoji"
                                class="reaction-btn rounded-xl px-3 py-2 text-sm"
                                type="button"
                            >
                                {{ emoji }}
                            </button>
                        </div>
                        <p class="text-xs text-gray-500">React and chat with the host in real time.</p>
                    </div>
                </section>

                <!-- Chat -->
                <aside class="chat-card flex min-h-[560px] flex-col rounded-3xl">
                    <div class="border-b border-[#F0EDE8] px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="font-black text-gray-900">{{ webinar?.room_title || 'In-call chat' }}</p>
                                <p class="text-xs text-gray-500">{{ webinar?.host_name || 'Host' }}</p>
                            </div>
                            <div class="inline-flex items-center gap-1.5 rounded-full bg-[#E8563A]/10 px-2.5 py-1 text-xs font-bold text-[#E8563A]">
                                <Users class="size-3.5" />
                                LIVE
                            </div>
                        </div>
                    </div>

                    <div class="border-b border-[#F0EDE8] px-4 py-3">
                        <div class="inline-flex rounded-xl border border-gray-100 bg-[#FAF8F5] p-1 text-xs">
                            <span class="rounded-lg bg-[#E8563A] px-3 py-1 font-bold text-white">Chat</span>
                            <span class="px-3 py-1 font-semibold text-gray-500">
                                Offers ({{ webinar?.featured_products?.length || 0 }})
                            </span>
                        </div>
                        <div
                            v-if="pinnedMessage"
                            class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                        >
                            <Pin class="mr-1 inline size-3.5 text-amber-600" />
                            <span class="font-bold">{{ pinnedMessage.sender_name || 'Pinned' }}:</span>
                            {{ pinnedMessage.message }}
                        </div>
                    </div>

                    <div class="message-list flex-1 space-y-2 overflow-y-auto px-4 py-3">
                        <div v-if="loading" class="space-y-2">
                            <div v-for="n in 4" :key="n" class="h-14 rounded-xl border border-gray-100 bg-white/70" />
                        </div>
                        <div
                            v-else-if="messages.length === 0"
                            class="flex h-full flex-col items-center justify-center gap-2 text-center text-sm text-gray-500"
                        >
                            <MessageSquare class="size-8 text-[#E8563A]/50" />
                            <p>No messages yet.</p>
                        </div>
                        <article
                            v-for="message in messages"
                            :key="message.id"
                            :class="[
                                'message-bubble rounded-2xl px-3 py-2 text-sm',
                                message.sender_type === 'attendee'
                                    ? 'bg-white text-gray-900'
                                    : message.sender_type === 'ai'
                                      ? 'bg-[#E8563A]/10 text-gray-900'
                                      : 'bg-[#E8563A] text-white',
                            ]"
                        >
                            <div
                                :class="[
                                    'mb-1 flex items-center gap-2 text-xs',
                                    message.sender_type === 'host' ? 'text-white/75' : 'text-gray-500',
                                ]"
                            >
                                <MessageSquare class="size-3.5" />
                                <span class="font-bold">
                                    {{ message.sender_name || (message.sender_type === 'ai' ? 'AI Assistant' : message.sender_type) }}
                                </span>
                                <Bot v-if="message.sender_type === 'ai'" class="size-3.5" />
                            </div>
                            <p class="leading-relaxed">{{ message.message }}</p>
                        </article>
                    </div>

                    <div class="border-t border-[#F0EDE8] bg-white p-3">
                        <div v-if="errorText" class="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{{ errorText }}</div>
                        <div class="flex items-center gap-2">
                            <Input
                                v-model="messageDraft"
                                :disabled="!webinar?.chat_enabled"
                                class="reply-input"
                                placeholder="Send a message..."
                                @keyup.enter="sendMessage"
                            />
                            <Button class="send-btn" size="icon" :disabled="posting || !webinar?.chat_enabled" @click="sendMessage">
                                <Loader2 v-if="posting" class="size-4 animate-spin" />
                                <Send v-else class="size-4" />
                            </Button>
                        </div>
                    </div>
                </aside>
            </div>

            <!-- Offers -->
            <section
                v-if="webinar?.featured_products?.length"
                class="offers-card rounded-3xl p-4"
            >
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#E8563A]">Featured Offers</p>
                        <h2 class="text-lg font-black text-gray-900">Products from this session</h2>
                    </div>
                    <span class="rounded-full bg-[#E8563A]/10 px-3 py-1 text-xs font-bold text-[#E8563A]">
                        {{ webinar.featured_products.length }} offer{{ webinar.featured_products.length !== 1 ? 's' : '' }}
                    </span>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <article
                        v-for="product in webinar.featured_products"
                        :key="product.id"
                        class="product-card rounded-2xl p-3"
                    >
                        <div class="mb-3 flex h-28 items-center justify-center overflow-hidden rounded-xl border border-gray-100 bg-gray-100">
                            <img
                                v-if="product.image_url"
                                :src="product.image_url"
                                :alt="product.title"
                                class="h-full w-full object-cover"
                            >
                            <Video v-else class="size-5 text-gray-400" />
                        </div>
                        <p class="line-clamp-2 text-sm font-bold text-gray-900">{{ product.title }}</p>
                        <p class="mt-1 text-sm font-black text-[#E8563A]">{{ formatMoney(product) }}</p>
                    </article>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.room-root {
    background:
        radial-gradient(circle at 0% 0%, rgba(232,86,58,0.12), transparent 28%),
        radial-gradient(circle at 100% 0%, rgba(255,140,66,0.12), transparent 30%),
        #F2EFEA;
}

.room-header,
.video-card,
.chat-card,
.offers-card {
    background: #fff;
    border: 1px solid #F0EDE8;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 8px 28px rgba(0,0,0,0.08);
}

.brand-icon,
.live-pill {
    background: linear-gradient(135deg, #E8563A, #ff8c42);
    box-shadow: 0 4px 14px rgba(232,86,58,0.35);
}

.empty-video {
    background: linear-gradient(135deg, #1f2937, #111827);
}

.reaction-btn,
.product-card {
    background: #FAF8F5;
    border: 1px solid #F0EDE8;
    transition: all 0.15s;
}
.reaction-btn:hover,
.product-card:hover {
    border-color: rgba(232,86,58,0.35);
    background: rgba(232,86,58,0.05);
}

.message-list {
    background:
        linear-gradient(rgba(255,255,255,0.55) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.55) 1px, transparent 1px),
        #F8F5F0;
    background-size: 28px 28px;
}

.message-bubble {
    border: 1px solid rgba(240,237,232,0.9);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.reply-input {
    height: 42px;
    border-color: #e5e7eb;
    background: #FAFAFA;
    border-radius: 9999px;
}
.reply-input:focus {
    border-color: #E8563A;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(232,86,58,0.10);
}

.send-btn {
    background: #E8563A;
    color: #fff;
    border-radius: 9999px;
    box-shadow: 0 4px 14px rgba(232,86,58,0.30);
}
.send-btn:hover:not(:disabled) {
    background: #D44A2F;
    transform: translateY(-1px);
}
.send-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
</style>
