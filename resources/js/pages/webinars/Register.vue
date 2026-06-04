<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Loader2, Mail, UserRound, Video } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type WebinarData = {
    id: number;
    title: string;
    description?: string | null;
    thumbnail_url?: string | null;
    host_name?: string | null;
    registration_title?: string | null;
    registration_description?: string | null;
};

const page = usePage();
const webinarId = Number((page.props as Record<string, unknown>).webinarId ?? 0);

const loading = ref(false);
const registering = ref(false);
const errorText = ref('');
const webinar = ref<WebinarData | null>(null);
const form = ref({
    full_name: '',
    email: '',
});

const titleText = computed(() => webinar.value?.registration_title || 'Join Webinar');
const descriptionText = computed(
    () => webinar.value?.registration_description || 'Enter your details to join.',
);

async function apiFetch<T>(url: string, options: RequestInit = {}): Promise<T> {
    const headers = new Headers(options.headers ?? {});
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const response = await fetch(url, {
        ...options,
        headers,
        credentials: 'same-origin',
    });

    const payload = await response.json().catch(() => null);

    if (!response.ok) {
        const message = payload && typeof payload === 'object' && 'message' in payload
            ? String((payload as { message: string }).message)
            : `Request failed (${response.status})`;

        throw new Error(message);
    }

    return payload as T;
}

async function loadWebinar() {
    loading.value = true;
    errorText.value = '';

    try {
        const payload = await apiFetch<{ data: WebinarData }>(`/api/v1/player/webinars/${webinarId}`);
        webinar.value = payload.data;
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not load webinar.';
    } finally {
        loading.value = false;
    }
}

async function register() {
    if (!form.value.full_name.trim() || !form.value.email.trim()) {
        errorText.value = 'Full name and email are required.';

        return;
    }

    registering.value = true;
    errorText.value = '';

    try {
        const response = await apiFetch<{ data: { room_url: string; registration_id: number } }>(
            `/api/v1/player/webinars/${webinarId}/register`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    full_name: form.value.full_name.trim(),
                    email: form.value.email.trim(),
                }),
            },
        );

        if (response.data.registration_id > 0) {
            sessionStorage.setItem(
                `webinar_registration_${webinarId}`,
                String(response.data.registration_id),
            );
        }

        router.visit(response.data.room_url);
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not register.';
    } finally {
        registering.value = false;
    }
}

onMounted(() => {
    const errors = page.props.errors as Record<string, string | string[] | undefined>;
    const registrationError = errors?.registration;

    if (registrationError) {
        errorText.value = Array.isArray(registrationError)
            ? registrationError[0]
            : registrationError;
    }

    loadWebinar();
});
</script>

<template>
    <Head title="Join Webinar" />

    <div class="webinar-register relative min-h-screen overflow-hidden px-4 py-8 sm:px-6 lg:px-8">
        <div class="blob blob-one" />
        <div class="blob blob-two" />

        <div class="relative mx-auto flex min-h-[calc(100vh-4rem)] w-full max-w-6xl items-center">
            <div class="grid w-full gap-6 lg:grid-cols-[1.15fr_0.85fr]">
                <!-- Webinar preview -->
                <section class="hero-card overflow-hidden rounded-4xl">
                    <div class="relative min-h-[540px]">
                        <img
                            v-if="webinar?.thumbnail_url"
                            :src="webinar.thumbnail_url"
                            :alt="webinar?.title"
                            class="absolute inset-0 h-full w-full object-cover"
                        >
                        <div v-else class="absolute inset-0 flex items-center justify-center bg-linear-to-br from-[#E8563A] to-[#ff8c42]">
                            <Video class="size-16 text-white/80" />
                        </div>

                        <div class="absolute inset-0 bg-linear-to-t from-black/85 via-black/40 to-black/10" />

                        <div class="relative z-10 flex min-h-[540px] flex-col justify-between p-6 sm:p-8">
                            <div class="flex items-center justify-between gap-3">
                                <div class="inline-flex items-center gap-2 rounded-full bg-white/15 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white backdrop-blur-md">
                                    <span class="size-2 rounded-full bg-[#ff8c42]" />
                                    Live Commerce Webinar
                                </div>
                                <div class="rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white/90 backdrop-blur-md">
                                    Free access
                                </div>
                            </div>

                            <div class="max-w-2xl space-y-5">
                                <div>
                                    <p class="mb-2 text-sm font-semibold uppercase tracking-[0.25em] text-white/60">
                                        Upcoming session
                                    </p>
                                    <h1 class="text-4xl font-black leading-tight text-white sm:text-5xl">
                                        {{ webinar?.title || 'Webinar' }}
                                    </h1>
                                </div>

                                <p class="max-w-xl text-base leading-relaxed text-white/75">
                                    {{ webinar?.description || 'Register to join this session and get access to the live room.' }}
                                </p>

                                <div class="flex flex-wrap gap-2">
                                    <span class="feature-pill">Live chat</span>
                                    <span class="feature-pill">Product offers</span>
                                    <span class="feature-pill">Instant room access</span>
                                </div>

                                <div class="flex items-center gap-3 rounded-2xl bg-white/10 p-4 backdrop-blur-md">
                                    <div class="flex size-10 items-center justify-center rounded-xl bg-white/20">
                                        <UserRound class="size-5 text-white" />
                                    </div>
                                    <div>
                                        <p class="text-xs font-medium text-white/60">Hosted by</p>
                                        <p class="font-bold text-white">{{ webinar?.host_name || 'SupremeVid Team' }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Registration card -->
                <section class="form-card self-center rounded-4xl p-6 sm:p-8">
                    <div class="mb-7 text-center">
                        <div class="mx-auto mb-4 flex size-12 items-center justify-center rounded-2xl bg-[#E8563A]/10">
                            <Video class="size-6 text-[#E8563A]" />
                        </div>
                        <p class="mb-1 text-xs font-bold uppercase tracking-[0.2em] text-[#E8563A]">Reserve your seat</p>
                        <h2 class="text-2xl font-black text-gray-900">{{ titleText }}</h2>
                        <p class="mt-2 text-sm leading-relaxed text-gray-500">{{ descriptionText }}</p>
                    </div>

                    <div v-if="loading" class="mb-4 flex items-center justify-center gap-2 rounded-xl bg-[#FAF8F5] px-4 py-3 text-sm text-gray-500">
                        <Loader2 class="size-4 animate-spin" />
                        Loading webinar...
                    </div>

                    <div v-if="errorText" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ errorText }}
                    </div>

                    <form class="space-y-4" @submit.prevent="register">
                        <div class="space-y-1.5">
                            <Label class="text-sm font-semibold text-gray-700">Full Name *</Label>
                            <div class="relative">
                                <UserRound class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                                <Input v-model="form.full_name" class="form-input pl-9" placeholder="John Doe" autocomplete="name" />
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <Label class="text-sm font-semibold text-gray-700">Email Address *</Label>
                            <div class="relative">
                                <Mail class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                                <Input v-model="form.email" type="email" class="form-input pl-9" placeholder="you@example.com" autocomplete="email" />
                            </div>
                        </div>

                        <Button class="cta-btn mt-2 h-12 w-full text-sm font-bold" :disabled="loading || registering">
                            <Loader2 v-if="registering" class="mr-2 size-4 animate-spin" />
                            {{ registering ? 'Preparing room...' : 'Continue to Webinar' }}
                        </Button>
                    </form>

                    <div class="mt-6 grid grid-cols-3 gap-2 text-center">
                        <div class="mini-stat">
                            <p class="text-sm font-black text-gray-900">Live</p>
                            <p class="text-[10px] text-gray-500">Q&amp;A</p>
                        </div>
                        <div class="mini-stat">
                            <p class="text-sm font-black text-gray-900">Secure</p>
                            <p class="text-[10px] text-gray-500">Access</p>
                        </div>
                        <div class="mini-stat">
                            <p class="text-sm font-black text-gray-900">Offers</p>
                            <p class="text-[10px] text-gray-500">Inside</p>
                        </div>
                    </div>

                    <p class="mt-5 text-center text-xs leading-relaxed text-gray-400">
                        By registering, you will be taken directly to the webinar room.
                    </p>
                </section>
            </div>
        </div>
    </div>
</template>

<style scoped>
.webinar-register {
    background-color: #F2EFEA;
}

.blob {
    position: absolute;
    border-radius: 9999px;
    filter: blur(70px);
    opacity: 0.28;
    pointer-events: none;
}
.blob-one {
    width: 320px;
    height: 320px;
    right: -80px;
    top: -80px;
    background: #E8563A;
}
.blob-two {
    width: 260px;
    height: 260px;
    left: -80px;
    bottom: -80px;
    background: #ff8c42;
}

.hero-card,
.form-card {
    background: #fff;
    border: 1px solid #F0EDE8;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 14px 42px rgba(0,0,0,0.10);
}

.feature-pill {
    border-radius: 9999px;
    background: rgba(255,255,255,0.14);
    padding: 0.45rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 700;
    color: rgba(255,255,255,0.9);
    backdrop-filter: blur(10px);
}

.form-input {
    height: 44px;
    border-color: #e5e7eb;
    background: #fafafa;
    border-radius: 12px;
    transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
}
.form-input:focus {
    border-color: #E8563A;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(232,86,58,0.10);
}

.cta-btn {
    background: #E8563A;
    color: #fff;
    border: none;
    box-shadow: 0 4px 16px rgba(232,86,58,0.35);
    transition: all 0.15s;
}
.cta-btn:hover:not(:disabled) {
    background: #D44A2F;
    box-shadow: 0 8px 24px rgba(232,86,58,0.45);
    transform: translateY(-1px);
}
.cta-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.mini-stat {
    border-radius: 14px;
    background: #FAF8F5;
    padding: 0.75rem 0.5rem;
    border: 1px solid #F0EDE8;
}
</style>
