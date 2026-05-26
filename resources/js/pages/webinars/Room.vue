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
    if (!value) return '';
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

    <div class="min-h-screen bg-background p-4 md:p-6">
        <div class="mx-auto grid w-full max-w-[1380px] gap-4 lg:grid-cols-[1.8fr_1fr]">
            <section class="rounded-2xl border bg-card p-3 md:p-4">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div>
                        <h1 class="text-lg font-semibold">{{ webinar?.title || 'Webinar Room' }}</h1>
                        <p class="text-sm text-muted-foreground">{{ webinar?.host_name || 'Host' }}</p>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-full bg-destructive/10 px-3 py-1 text-xs font-semibold text-destructive">
                        <span class="relative flex size-2">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-destructive opacity-75" />
                            <span class="relative inline-flex size-2 rounded-full bg-destructive" />
                        </span>
                        LIVE
                    </div>
                </div>

                <div class="relative overflow-hidden rounded-xl border bg-black/90">
                    <video
                        v-if="webinar?.video_url"
                        :src="webinar.video_url"
                        controls
                        playsinline
                        class="h-full min-h-[250px] w-full object-cover md:min-h-[520px]"
                        :poster="webinar.thumbnail_url || undefined"
                    />
                    <div
                        v-else
                        class="flex min-h-[250px] items-center justify-center text-muted-foreground md:min-h-[520px]"
                    >
                        <Video class="size-8" />
                    </div>

                    <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                        <div class="rounded-full bg-background/90 px-4 py-2 text-sm font-medium shadow">
                            <Mic2 class="mr-2 inline size-4 text-primary" />
                            Click to Enable Sound
                        </div>
                    </div>
                </div>

                <div class="mt-3 flex items-center gap-2 overflow-x-auto">
                    <button
                        v-for="emoji in ['👍', '❤️', '🔥', '👏']"
                        :key="emoji"
                        class="rounded-lg border bg-background px-3 py-1.5 text-sm hover:bg-muted"
                        type="button"
                    >
                        {{ emoji }}
                    </button>
                </div>
            </section>

            <aside class="flex min-h-[520px] flex-col rounded-2xl border bg-card">
                <div class="border-b px-4 py-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-semibold">{{ webinar?.room_title || 'In-call chat' }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ webinar?.host_name || 'Host' }}
                            </p>
                        </div>
                        <div class="inline-flex items-center gap-1 rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive">
                            <Users class="size-3.5" />
                            LIVE
                        </div>
                    </div>
                </div>

                <div class="border-b px-4 py-2">
                    <div class="inline-flex rounded-md border bg-muted/30 p-1 text-xs">
                        <span class="rounded bg-primary px-2 py-1 text-primary-foreground">Chat</span>
                        <span class="px-2 py-1 text-muted-foreground">
                            Offers ({{ webinar?.featured_products?.length || 0 }})
                        </span>
                    </div>
                    <div
                        v-if="pinnedMessage"
                        class="mt-2 rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs"
                    >
                        <Pin class="mr-1 inline size-3.5 text-amber-600" />
                        <span class="font-medium">{{ pinnedMessage.sender_name || 'Pinned' }}:</span>
                        {{ pinnedMessage.message }}
                    </div>
                </div>

                <div class="flex-1 space-y-2 overflow-y-auto px-4 py-3">
                    <div v-if="loading" class="space-y-2">
                        <div v-for="n in 4" :key="n" class="h-14 rounded-md border bg-muted/40" />
                    </div>
                    <div
                        v-else-if="messages.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No messages yet.
                    </div>
                    <article
                        v-for="message in messages"
                        :key="message.id"
                        class="rounded-md border px-3 py-2 text-sm"
                    >
                        <div class="mb-1 flex items-center gap-2 text-xs text-muted-foreground">
                            <MessageSquare class="size-3.5" />
                            <span class="font-medium">
                                {{ message.sender_name || (message.sender_type === 'ai' ? 'AI Assistant' : message.sender_type) }}
                            </span>
                            <Bot v-if="message.sender_type === 'ai'" class="size-3.5" />
                        </div>
                        <p>{{ message.message }}</p>
                    </article>
                </div>

                <div class="border-t p-3">
                    <div v-if="errorText" class="mb-2 text-xs text-destructive">{{ errorText }}</div>
                    <div class="flex items-center gap-2">
                        <Input
                            v-model="messageDraft"
                            :disabled="!webinar?.chat_enabled"
                            placeholder="Send a message..."
                            @keyup.enter="sendMessage"
                        />
                        <Button size="icon" :disabled="posting || !webinar?.chat_enabled" @click="sendMessage">
                            <Loader2 v-if="posting" class="size-4 animate-spin" />
                            <Send v-else class="size-4" />
                        </Button>
                    </div>
                </div>
            </aside>
        </div>

        <section
            v-if="webinar?.featured_products?.length"
            class="mx-auto mt-4 w-full max-w-[1380px] rounded-2xl border bg-card p-4"
        >
            <h2 class="mb-3 text-sm font-semibold text-muted-foreground">Offers</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <article
                    v-for="product in webinar.featured_products"
                    :key="product.id"
                    class="rounded-xl border bg-background p-3"
                >
                    <div class="mb-2 flex h-24 items-center justify-center overflow-hidden rounded-md border bg-muted">
                        <img
                            v-if="product.image_url"
                            :src="product.image_url"
                            :alt="product.title"
                            class="h-full w-full object-cover"
                        >
                        <Video v-else class="size-5 text-muted-foreground" />
                    </div>
                    <p class="line-clamp-2 text-sm font-medium">{{ product.title }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ formatMoney(product) }}</p>
                </article>
            </div>
        </section>
    </div>
</template>
