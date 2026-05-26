<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    ChevronLeft,
    ChevronRight,
    Bookmark,
    Clapperboard,
    Eye,
    Film,
    Heart,
    ImageOff,
    Loader2,
    MessageCircle,
    Package,
    Pause,
    Play,
    Plus,
    Search,
    Share2,
    ShoppingBag,
    Sparkles,
    Tag,
    Upload,
    Users,
    Wand2,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAdminApi } from '@/composables/useAdminApi';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Shoppable Videos', href: '/content' },
            { title: 'Create', href: '/content/create' },
        ],
    },
});

type ProductOption = {
    id: number;
    title: string;
    image_url?: string | null;
    price: string;
    sale_price?: string | null;
    currency: string;
    description?: string | null;
};

type VideoItem = { id: number; title: string };

type AiGenerationItem = {
    id: number;
    type: string;
    status: string;
    provider: string;
    output?: { full_script?: string; hook?: string };
    error_message?: string | null;
    video_id?: number | null;
};

type AdStyle = 'avatar_only' | 'avatar_beside_product' | 'product_card_overlay' | 'full_product_background' | 'template_ad_scene';

type HeyGenAvatarOption = {
    id: string;
    name: string;
    avatar_type?: string | null;
    gender?: string | null;
    preview_image_url?: string | null;
    preview_video_url?: string | null;
    default_voice_id?: string | null;
    preferred_orientation?: string | null;
    ownership?: string | null;
};

type HeyGenVoiceOption = {
    voice_id: string;
    name: string;
    language?: string | null;
    gender?: string | null;
    preview_audio_url?: string | null;
    type?: string | null;
};

type HeyGenOptions = {
    enabled: boolean;
    avatars: HeyGenAvatarOption[];
    voices: HeyGenVoiceOption[];
    cached_at?: string | null;
    message?: string | null;
};

const { teamId, apiFetch, getList, postJson, uploadFile, ensureTeam } = useAdminApi();

/* ── top-level tabs ── */
const activeTab = ref<'upload' | 'ai'>('upload');

/* ── AI wizard step (1-4) ── */
const aiStep = ref(1);

const AI_STEPS = [
    { n: 1, label: 'Products', icon: ShoppingBag },
    { n: 2, label: 'Script', icon: Wand2 },
    { n: 3, label: 'Presenter', icon: Users },
    { n: 4, label: 'Review', icon: Sparkles },
];

const adStyleOptions: Array<{ value: AdStyle; title: string; description: string }> = [
    {
        value: 'avatar_only',
        title: 'Avatar only',
        description: 'Classic presenter video with no product visual background.',
    },
    {
        value: 'avatar_beside_product',
        title: 'Avatar beside product',
        description: 'Use a product visual while the avatar presents beside it.',
    },
    {
        value: 'product_card_overlay',
        title: 'Product card overlay',
        description: 'Best for shop-style ads with a visual product card feel.',
    },
    {
        value: 'full_product_background',
        title: 'Full product background',
        description: 'Use the image as the full scene behind the avatar.',
    },
    {
        value: 'template_ad_scene',
        title: 'Template ad scene',
        description: 'Prepared for future HeyGen template scenes.',
    },
];

function goStep(n: number) {
    aiStep.value = Math.max(1, Math.min(4, n));
}

/* ── global error / notice ── */
const errorText = ref('');
const scriptGenerationNotice = ref('');

/* ── upload form state ── */
const uploading = ref(false);
const products = ref<ProductOption[]>([]);
const heygenOptions = ref<HeyGenOptions>({ enabled: false, avatars: [], voices: [], cached_at: null, message: null });
const heygenLoading = ref(false);
const heygenError = ref('');
const aiGenerating = ref(false);

/* ── video file + preview ── */
const selectedFile = ref<File | null>(null);
const previewVideoUrl = ref<string | null>(null);
const thumbnailPreviewUrl = ref<string | null>(null);
const adVisualPreviewUrl = ref<string | null>(null);
const adVisualUploading = ref(false);

/* ── product modal ── */
const productModalOpen = ref(false);
const productSearch = ref('');

const filteredProducts = computed(() => {
    const q = productSearch.value.trim().toLowerCase();
    if (!q) return products.value;
    return products.value.filter((p) => p.title.toLowerCase().includes(q));
});

/* ── upload form ── */
const uploadForm = ref({
    title: '',
    description: '',
    visibility: 'public',
    thumbnail_url: '',
    product_ids: [] as number[],
});

/* ── viewer simulation ── */
const viewerSim = ref({ enabled: false, min: 50, max: 500 });

/* ── AI script form ── */
const scriptForm = ref({
    topic: 'product showcase',
    tone: 'engaging',
    language: 'en',
    duration_seconds: 45,
    product_ids: [] as number[],
});

/* ── AI avatar form ── */
const avatarForm = ref({
    title: '',
    description: '',
    script: '',
    language: 'en',
    avatar_id: '',
    voice_id: '',
    ad_style: 'avatar_only' as AdStyle,
    visual_url: '',
    visual_file_path: '',
    product_ids: [] as number[],
});

/* ── voice audio preview ── */
const playingVoiceId = ref('');
let audioInstance: HTMLAudioElement | null = null;

/* ── Step 3 inner tab ── */
const presenterTab = ref<'avatars' | 'voices'>('avatars');
const avatarSearch = ref('');
const avatarGenderFilter = ref('all');
const avatarTypeFilter = ref('all');
const avatarOwnershipFilter = ref('all');

const avatarTypes = computed(() =>
    [...new Set(heygenOptions.value.avatars.map((avatar) => avatar.avatar_type).filter(Boolean) as string[])].sort(),
);

const filteredHeyGenAvatars = computed(() => {
    const query = avatarSearch.value.trim().toLowerCase();

    return heygenOptions.value.avatars.filter((avatar) => {
        const gender = String(avatar.gender ?? '').toLowerCase();
        const type = String(avatar.avatar_type ?? '');
        const ownership = String(avatar.ownership ?? '');
        const haystack = [
            avatar.name,
            avatar.avatar_type,
            avatar.gender,
            avatar.ownership,
            avatar.preferred_orientation,
        ].filter(Boolean).join(' ').toLowerCase();

        return (!query || haystack.includes(query))
            && (avatarGenderFilter.value === 'all' || gender === avatarGenderFilter.value)
            && (avatarTypeFilter.value === 'all' || type === avatarTypeFilter.value)
            && (avatarOwnershipFilter.value === 'all' || ownership === avatarOwnershipFilter.value);
    });
});

function toggleVoicePreview(voice: HeyGenVoiceOption) {
    if (!voice.preview_audio_url) return;

    if (playingVoiceId.value === voice.voice_id) {
        audioInstance?.pause();
        playingVoiceId.value = '';
        return;
    }

    if (audioInstance) {
        audioInstance.pause();
    }

    audioInstance = new Audio(voice.preview_audio_url);
    playingVoiceId.value = voice.voice_id;
    audioInstance.play().catch(() => {});
    audioInstance.onended = () => {
        playingVoiceId.value = '';
    };
}

/* ── product modal ── */
const modalTarget = ref<'upload' | 'ai'>('upload');

function openProductModal(target: 'upload' | 'ai') {
    modalTarget.value = target;
    productSearch.value = '';
    productModalOpen.value = true;
}

const activeProductIds = computed(() =>
    modalTarget.value === 'upload' ? uploadForm.value.product_ids : avatarForm.value.product_ids,
);

function toggleProductInModal(productId: number) {
    const ids = modalTarget.value === 'upload' ? uploadForm.value.product_ids : avatarForm.value.product_ids;
    const idx = ids.indexOf(productId);
    if (idx === -1) {
        ids.push(productId);
    } else {
        ids.splice(idx, 1);
    }

    if (modalTarget.value === 'ai') {
        scriptForm.value.product_ids = [...ids];
    }
}

/* In-step product toggle (no modal, for step 1) */
function toggleAiProduct(productId: number) {
    const ids = avatarForm.value.product_ids;
    const idx = ids.indexOf(productId);
    if (idx === -1) {
        ids.push(productId);
    } else {
        ids.splice(idx, 1);
    }
    scriptForm.value.product_ids = [...ids];
}

const uploadSelectedProducts = computed(() =>
    products.value.filter((p) => uploadForm.value.product_ids.includes(p.id)),
);
const avatarSelectedProducts = computed(() =>
    products.value.filter((p) => avatarForm.value.product_ids.includes(p.id)),
);
const selectedHeyGenAvatar = computed(
    () => heygenOptions.value.avatars.find((avatar) => avatar.id === avatarForm.value.avatar_id) ?? null,
);
const selectedHeyGenVoice = computed(
    () => heygenOptions.value.voices.find((voice) => voice.voice_id === avatarForm.value.voice_id) ?? null,
);

/* ── helpers ── */
function formatPrice(currency: string, price: string | null | undefined): string {
    if (!price) return '';
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(Number(price));
}

function syncVoiceForAvatar(avatar: HeyGenAvatarOption | null) {
    if (avatar?.default_voice_id && heygenOptions.value.voices.some((v) => v.voice_id === avatar.default_voice_id)) {
        avatarForm.value.voice_id = avatar.default_voice_id;
        return;
    }

    if (!avatarForm.value.voice_id && heygenOptions.value.voices.length) {
        avatarForm.value.voice_id = heygenOptions.value.voices[0].voice_id;
    }
}

function selectHeyGenAvatar(avatar: HeyGenAvatarOption) {
    avatarForm.value.avatar_id = avatar.id;
    syncVoiceForAvatar(avatar);
}

function onFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    selectedFile.value = file;
    if (previewVideoUrl.value) URL.revokeObjectURL(previewVideoUrl.value);
    previewVideoUrl.value = URL.createObjectURL(file);
    if (!uploadForm.value.title) {
        uploadForm.value.title = file.name.replace(/\.[^.]+$/, '');
    }
}

function onThumbnailFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    if (thumbnailPreviewUrl.value?.startsWith('blob:')) URL.revokeObjectURL(thumbnailPreviewUrl.value);
    thumbnailPreviewUrl.value = URL.createObjectURL(file);
}

async function onAdVisualFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    if (adVisualPreviewUrl.value?.startsWith('blob:')) {
        URL.revokeObjectURL(adVisualPreviewUrl.value);
    }

    adVisualPreviewUrl.value = URL.createObjectURL(file);
    adVisualUploading.value = true;
    errorText.value = '';

    try {
        await ensureTeam();

        const formData = new FormData();
        formData.append('team_id', String(teamId.value));
        formData.append('file', file);

        const payload = await apiFetch<{ visual_url: string; visual_file_path: string }>('/api/v1/admin/ai/visuals', {
            method: 'POST',
            body: formData,
        });

        avatarForm.value.visual_url = payload.visual_url;
        avatarForm.value.visual_file_path = payload.visual_file_path;

        if (avatarForm.value.ad_style === 'avatar_only') {
            avatarForm.value.ad_style = 'avatar_beside_product';
        }
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Product visual upload failed.';
        avatarForm.value.visual_url = '';
        avatarForm.value.visual_file_path = '';
    } finally {
        adVisualUploading.value = false;
        input.value = '';
    }
}

function clearAdVisual() {
    if (adVisualPreviewUrl.value?.startsWith('blob:')) {
        URL.revokeObjectURL(adVisualPreviewUrl.value);
    }

    adVisualPreviewUrl.value = null;
    avatarForm.value.visual_url = '';
    avatarForm.value.visual_file_path = '';
}

onUnmounted(() => {
    if (previewVideoUrl.value) URL.revokeObjectURL(previewVideoUrl.value);
    if (thumbnailPreviewUrl.value?.startsWith('blob:')) URL.revokeObjectURL(thumbnailPreviewUrl.value);
    if (adVisualPreviewUrl.value?.startsWith('blob:')) URL.revokeObjectURL(adVisualPreviewUrl.value);
    audioInstance?.pause();
});

function unwrapVideo(payload: unknown): VideoItem | null {
    if (!payload || typeof payload !== 'object') return null;
    if ('data' in payload) {
        const d = (payload as { data?: unknown }).data;
        if (d && typeof d === 'object' && 'id' in d) return d as VideoItem;
    }
    if ('id' in payload) return payload as VideoItem;
    return null;
}

function buildProductTags(productIds: number[]) {
    return productIds.map((product_id, index) => ({
        product_id,
        starts_at_ms: 0,
        ends_at_ms: 15000,
        cta_label: 'Shop now',
        discount_percent: 0,
        is_pinned: true,
        sort_order: index,
    }));
}

async function attachProducts(videoId: number, productIds: number[]) {
    if (!productIds.length) return;
    await postJson(`/api/v1/admin/videos/${videoId}/product-tags/sync`, {
        tags: buildProductTags(productIds),
    });
}

async function submitUpload() {
    if (!selectedFile.value) {
        errorText.value = 'Please choose a video file first.';
        return;
    }
    uploading.value = true;
    errorText.value = '';
    try {
        await ensureTeam();
        const upload = await uploadFile('/api/v1/admin/videos/upload', selectedFile.value);

        const payload = await postJson<unknown>('/api/v1/admin/videos', {
            title: uploadForm.value.title || selectedFile.value.name.replace(/\.[^.]+$/, ''),
            description: uploadForm.value.description || null,
            source: 'uploaded',
            visibility: uploadForm.value.visibility,
            thumbnail_url: uploadForm.value.thumbnail_url || null,
            local_file_path: upload.local_file_path,
            metadata: viewerSim.value.enabled ? {
                viewer_sim_enabled: true,
                viewer_sim_min: viewerSim.value.min,
                viewer_sim_max: viewerSim.value.max,
            } : null,
        });

        const created = unwrapVideo(payload);
        if (created?.id) await attachProducts(created.id, uploadForm.value.product_ids);

        if (created?.id) {
            router.visit(`/content/${created.id}/edit`);
        } else {
            router.visit('/content');
        }
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Upload failed.';
    } finally {
        uploading.value = false;
    }
}

async function generateScript() {
    aiGenerating.value = true;
    errorText.value = '';
    scriptGenerationNotice.value = '';
    try {
        await ensureTeam();
        const payload = await postJson<{ data?: AiGenerationItem }>('/api/v1/admin/ai/scripts', {
            ...scriptForm.value,
            product_ids: avatarForm.value.product_ids,
        });
        const gen = payload.data ?? (payload as unknown as AiGenerationItem);
        if (gen.output?.full_script) avatarForm.value.script = gen.output.full_script;
        if (!avatarForm.value.title) avatarForm.value.title = `AI Video — ${scriptForm.value.topic}`;
        if (gen.provider && gen.provider !== 'openai') {
            scriptGenerationNotice.value = 'No OpenAI key found — used fallback template. Set OPENAI_API_KEY for real AI scripts.';
        } else {
            scriptGenerationNotice.value = '✓ Script generated.';
        }
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Script generation failed.';
    } finally {
        aiGenerating.value = false;
    }
}

function skipScript() {
    const product = avatarSelectedProducts.value[0];
    const name = product?.title ?? 'this product';
    const price = product ? formatPrice(product.currency, product.sale_price || product.price) : '$29.99';
    avatarForm.value.script = `Hey, stop scrolling — you need to see ${name}.\n\nThis is the product everyone's talking about, and right now you can grab it for just ${price}.\n\nTap the card below and shop now before it sells out.`;
    if (!avatarForm.value.title) {
        avatarForm.value.title = `${name} — AI Ad`;
    }
    scriptGenerationNotice.value = '⚠ TEST MODE — dummy script inserted, no OpenAI call made.';
}

async function generateAvatarVideo() {
    aiGenerating.value = true;
    errorText.value = '';
    try {
        await ensureTeam();
        const payload = await postJson<unknown>('/api/v1/admin/ai/avatar-videos', { ...avatarForm.value });

        const responseVideo =
            payload && typeof payload === 'object' && 'video' in payload
                ? unwrapVideo((payload as { video?: unknown }).video)
                : null;

        if (responseVideo?.id) await attachProducts(responseVideo.id, avatarForm.value.product_ids);
        router.visit('/content');
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Avatar video generation failed.';
    } finally {
        aiGenerating.value = false;
    }
}

async function loadProducts() {
    try {
        const payload = await getList<ProductOption>('/api/v1/admin/products');
        products.value = payload.data ?? [];
    } catch {
        products.value = [];
    }
}

async function loadHeyGenOptions(refresh = false) {
    heygenLoading.value = true;
    heygenError.value = '';

    try {
        await ensureTeam();
        const params = new URLSearchParams({ team_id: String(teamId.value) });

        if (refresh) {
            params.set('refresh', '1');
        }

        const payload = await apiFetch<HeyGenOptions>(`/api/v1/admin/ai/heygen-options?${params.toString()}`);
        heygenOptions.value = {
            enabled: Boolean(payload.enabled),
            avatars: payload.avatars ?? [],
            voices: payload.voices ?? [],
            cached_at: payload.cached_at ?? null,
            message: payload.message ?? null,
        };

        if (!avatarForm.value.avatar_id && heygenOptions.value.avatars.length) {
            selectHeyGenAvatar(heygenOptions.value.avatars[0]);
        } else {
            syncVoiceForAvatar(selectedHeyGenAvatar.value);
        }
    } catch (err) {
        heygenError.value = err instanceof Error ? err.message : 'Could not load HeyGen avatars and voices.';
    } finally {
        heygenLoading.value = false;
    }
}

onMounted(() => Promise.all([loadProducts(), loadHeyGenOptions()]));
</script>

<template>
    <Head title="Create Shoppable Video" />

    <div class="create-root flex h-full flex-1 flex-col gap-6 p-4 md:p-6">

        <!-- Header -->
        <div class="flex flex-wrap items-center gap-3">
            <Button variant="ghost" size="sm" as-child>
                <Link href="/content">
                    <ArrowLeft class="mr-1.5 size-4" />
                    Back
                </Link>
            </Button>
            <div class="h-5 w-px bg-border" />
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Create Shoppable Video</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">Upload your own or let AI generate a product ad.</p>
            </div>
        </div>

        <!-- Global error -->
        <div
            v-if="errorText"
            class="flex items-center gap-2 rounded-xl border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
        >
            <XCircle class="size-4 shrink-0" />
            {{ errorText }}
        </div>

        <!-- Top mode tabs -->
        <div class="inline-flex rounded-xl border border-[#E8563A]/20 bg-white p-1 shadow-sm">
            <button
                :class="[
                    'flex items-center gap-2 rounded-lg px-5 py-2 text-sm font-medium transition-all',
                    activeTab === 'upload'
                        ? 'bg-[#E8563A] text-white shadow-sm'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                @click="activeTab = 'upload'"
            >
                <Upload class="size-4" />
                Upload Video
            </button>
            <button
                :class="[
                    'flex items-center gap-2 rounded-lg px-5 py-2 text-sm font-medium transition-all',
                    activeTab === 'ai'
                        ? 'bg-[#E8563A] text-white shadow-sm'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                @click="activeTab = 'ai'"
            >
                <Sparkles class="size-4" />
                AI Generate
                <span class="rounded-full bg-linear-to-r from-[#E8563A] to-[#ff8c42] px-1.5 py-0.5 text-[10px] font-bold text-white">AI</span>
            </button>
        </div>

        <!-- ═══════════════ MAIN GRID ═══════════════ -->
        <div class="grid gap-6 lg:grid-cols-5">

            <!-- ── LEFT COLUMN ── -->
            <div class="lg:col-span-3">

                <!-- ══════════ UPLOAD TAB ══════════ -->
                <template v-if="activeTab === 'upload'">
                    <div class="space-y-5">

                        <!-- ── Big drop zone at the top ── -->
                        <div
                            :class="[
                                'group relative flex min-h-52 cursor-pointer flex-col items-center justify-center gap-4 overflow-hidden rounded-2xl border-2 border-dashed transition-all',
                                selectedFile
                                    ? 'border-[#E8563A] bg-[#E8563A]/5'
                                    : 'border-border bg-white hover:border-[#E8563A]/50 hover:bg-[#E8563A]/5',
                            ]"
                        >
                            <!-- Background video preview when file selected -->
                            <video
                                v-if="previewVideoUrl"
                                :src="previewVideoUrl"
                                class="absolute inset-0 h-full w-full object-cover opacity-30"
                                autoplay muted loop playsinline
                            />

                            <div class="relative z-10 flex flex-col items-center gap-3 text-center">
                                <div :class="[
                                    'flex size-16 items-center justify-center rounded-2xl transition-all',
                                    selectedFile ? 'bg-[#E8563A]/20' : 'bg-muted group-hover:bg-[#E8563A]/10',
                                ]">
                                    <Film :class="['size-8 transition-colors', selectedFile ? 'text-[#E8563A]' : 'text-muted-foreground group-hover:text-[#E8563A]']" />
                                </div>

                                <div v-if="!selectedFile">
                                    <p class="text-base font-semibold">Drop your video here</p>
                                    <p class="mt-1 text-sm text-muted-foreground">or click to browse · MP4, MOV, WEBM up to 512 MB</p>
                                </div>
                                <div v-else>
                                    <p class="text-base font-semibold text-[#E8563A]">{{ selectedFile.name }}</p>
                                    <p class="mt-0.5 text-sm text-muted-foreground">{{ (selectedFile.size / 1024 / 1024).toFixed(1) }} MB · click to replace</p>
                                </div>

                                <div v-if="!selectedFile" class="mt-1 flex items-center gap-2 rounded-xl border border-dashed bg-background/70 px-4 py-2 text-sm text-muted-foreground backdrop-blur-sm">
                                    <Upload class="size-4 shrink-0" />
                                    Browse files
                                </div>
                            </div>

                            <input
                                id="up-file"
                                type="file"
                                accept="video/*"
                                class="absolute inset-0 cursor-pointer opacity-0"
                                @change="onFileSelected"
                            >
                        </div>

                        <!-- ── Details card ── -->
                        <Card>
                            <CardHeader class="pb-3">
                                <CardTitle class="text-base">Video details</CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-4">

                                <div class="space-y-1.5">
                                    <Label for="up-title">Title <span class="text-destructive">*</span></Label>
                                    <Input id="up-title" v-model="uploadForm.title" placeholder="Summer launch reel" class="h-10" />
                                </div>

                                <div class="space-y-1.5">
                                    <Label for="up-desc">Description</Label>
                                    <textarea
                                        id="up-desc"
                                        v-model="uploadForm.description"
                                        rows="2"
                                        placeholder="Optional — what's this video about?"
                                        class="w-full rounded-xl border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    />
                                </div>

                                <!-- Visibility pills -->
                                <div class="space-y-1.5">
                                    <Label>Visibility</Label>
                                    <div class="flex gap-2">
                                        <button
                                            v-for="vis in [
                                                { value: 'public', label: '🌍 Public' },
                                                { value: 'unlisted', label: '🔗 Unlisted' },
                                                { value: 'private', label: '🔒 Private' },
                                            ]"
                                            :key="vis.value"
                                            type="button"
                                            :class="[
                                                'flex-1 rounded-xl border py-2 text-sm font-medium transition-all',
                                        uploadForm.visibility === vis.value
                                            ? 'border-[#E8563A] bg-[#E8563A]/10 text-[#E8563A] font-semibold'
                                            : 'border-border text-muted-foreground hover:border-[#E8563A]/40',
                                            ]"
                                            @click="uploadForm.visibility = vis.value"
                                        >{{ vis.label }}</button>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- ── Thumbnail card ── -->
                        <Card>
                            <CardHeader class="pb-3">
                                <CardTitle class="text-base">Thumbnail</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="flex gap-4">
                                    <!-- Preview -->
                                    <div class="relative h-28 w-20 shrink-0 overflow-hidden rounded-xl border bg-muted">
                                        <img
                                            v-if="thumbnailPreviewUrl || uploadForm.thumbnail_url"
                                            :src="thumbnailPreviewUrl || uploadForm.thumbnail_url"
                                            alt="Thumbnail"
                                            class="h-full w-full object-cover"
                                            @error="(e) => (e.target as HTMLImageElement).style.opacity='0'"
                                        >
                                        <div v-else class="flex h-full items-center justify-center">
                                            <Film class="size-6 text-muted-foreground/40" />
                                        </div>
                                        <!-- Change overlay -->
                                        <label
                                            v-if="thumbnailPreviewUrl || uploadForm.thumbnail_url"
                                            class="absolute inset-0 flex cursor-pointer items-center justify-center bg-black/40 opacity-0 transition-opacity hover:opacity-100"
                                        >
                                            <span class="text-[10px] font-semibold text-white">Change</span>
                                            <input type="file" accept="image/*" class="hidden" @change="onThumbnailFileSelected">
                                        </label>
                                    </div>

                                    <div class="flex-1 space-y-2.5">
                                        <label class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed py-3 text-sm text-muted-foreground transition-colors hover:border-[#E8563A]/40 hover:bg-[#E8563A]/5 hover:text-[#E8563A]">
                                            <Upload class="size-4" />
                                            Upload image
                                            <input type="file" accept="image/*" class="hidden" @change="onThumbnailFileSelected">
                                        </label>
                                        <div class="relative">
                                            <Input
                                                v-model="uploadForm.thumbnail_url"
                                                type="url"
                                                placeholder="Or paste a public image URL (https://…)"
                                                class="h-9 text-xs"
                                            />
                                        </div>
                                        <p v-if="thumbnailPreviewUrl && !uploadForm.thumbnail_url" class="text-[10px] text-amber-600">
                                            Preview only — paste a URL above to save it.
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- ── Products card ── -->
                        <Card>
                            <CardHeader class="pb-3">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <CardTitle class="text-base">Tag products</CardTitle>
                                        <p class="mt-0.5 text-xs text-muted-foreground">Products shown as buy cards below the video.</p>
                                    </div>
                                    <Button type="button" variant="outline" size="sm" class="gap-1.5 shrink-0" @click="openProductModal('upload')">
                                        <Plus class="size-3.5" />
                                        {{ uploadForm.product_ids.length ? 'Edit' : 'Attach' }}
                                    </Button>
                                </div>
                            </CardHeader>
                            <CardContent v-if="uploadSelectedProducts.length" class="pt-0">
                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    <div
                                        v-for="p in uploadSelectedProducts"
                                        :key="p.id"
                                        class="flex items-center gap-2 rounded-xl border bg-muted/40 p-2"
                                    >
                                        <div class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-background">
                                            <img v-if="p.image_url" :src="p.image_url" class="h-full w-full object-cover" :alt="p.title">
                                            <ImageOff v-else class="size-4 text-muted-foreground" />
                                        </div>
                                        <div class="min-w-0">
                                            <p class="truncate text-xs font-semibold">{{ p.title }}</p>
                                            <p class="text-[10px] text-muted-foreground">{{ formatPrice(p.currency, p.sale_price || p.price) }}</p>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                            <CardContent v-else class="pt-0">
                                <p class="text-sm text-muted-foreground">No products attached yet.</p>
                            </CardContent>
                        </Card>

                        <!-- ── Viewer simulation card ── -->
                        <Card>
                            <CardHeader class="pb-3">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <CardTitle class="text-base">Live viewer simulation</CardTitle>
                                        <p class="mt-0.5 text-xs text-muted-foreground">Show a drifting viewer count badge in your embed.</p>
                                    </div>
                                    <label class="relative mt-0.5 inline-flex cursor-pointer items-center">
                                        <input v-model="viewerSim.enabled" type="checkbox" class="peer sr-only">
                                        <div class="h-6 w-11 rounded-full bg-muted transition-colors peer-checked:bg-sky-500 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-all peer-checked:after:translate-x-5" />
                                    </label>
                                </div>
                            </CardHeader>
                            <CardContent v-if="viewerSim.enabled" class="pt-0">
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="space-y-1.5">
                                        <Label>Min viewers</Label>
                                        <Input v-model.number="viewerSim.min" type="number" min="1" :max="viewerSim.max - 1" placeholder="50" />
                                    </div>
                                    <div class="space-y-1.5">
                                        <Label>Max viewers</Label>
                                        <Input v-model.number="viewerSim.max" type="number" :min="viewerSim.min + 1" placeholder="500" />
                                    </div>
                                    <p class="col-span-2 text-xs text-muted-foreground">Count drifts between {{ viewerSim.min }} and {{ viewerSim.max }}.</p>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- ── Publish button ── -->
                        <Button
                            class="h-12 w-full bg-[#E8563A] text-base font-semibold text-white shadow-lg shadow-[#E8563A]/30 hover:bg-[#D44A2F]"
                            :disabled="uploading || !selectedFile"
                            @click="submitUpload"
                        >
                            <Loader2 v-if="uploading" class="mr-2 size-5 animate-spin" />
                            <Upload v-else class="mr-2 size-5" />
                            {{ uploading ? 'Uploading…' : 'Publish shoppable video' }}
                        </Button>
                    </div>
                </template>

                <!-- ══════════ AI GENERATE TAB ══════════ -->
                <template v-else>

                    <!-- ── Step indicator ── -->
                    <div class="mb-6">
                        <div class="flex items-center gap-0">
                            <template v-for="(step, i) in AI_STEPS" :key="step.n">
                                <!-- Step bubble -->
                                <button
                                    type="button"
                                    :class="[
                                        'flex flex-col items-center gap-1 px-1',
                                        step.n <= aiStep ? 'cursor-pointer' : 'cursor-default',
                                    ]"
                                    :disabled="step.n > aiStep"
                                    @click="step.n < aiStep && goStep(step.n)"
                                >
                                    <div :class="[
                                        'flex size-9 items-center justify-center rounded-full border-2 text-sm font-bold transition-all',
                                        step.n < aiStep
                                            ? 'border-[#E8563A] bg-[#E8563A] text-white'
                                                : step.n === aiStep
                                                    ? 'border-[#E8563A] bg-[#E8563A] text-white shadow-lg shadow-[#E8563A]/30'
                                                : 'border-muted-foreground/30 bg-muted text-muted-foreground',
                                    ]">
                                        <Check v-if="step.n < aiStep" class="size-4" />
                                        <span v-else>{{ step.n }}</span>
                                    </div>
                                    <span :class="[
                                        'text-[11px] font-medium',
                                        step.n === aiStep ? 'text-foreground' : 'text-muted-foreground',
                                    ]">{{ step.label }}</span>
                                </button>

                                <!-- Connector line -->
                                <div
                                    v-if="i < AI_STEPS.length - 1"
                                    :class="[
                                        'mb-4 h-0.5 flex-1 transition-colors',
                                        step.n < aiStep ? 'bg-[#E8563A]' : 'bg-[#E8563A]/20',
                                    ]"
                                />
                            </template>
                        </div>
                    </div>

                    <!-- ═══ STEP 1: Products ═══ -->
                    <div v-if="aiStep === 1" class="space-y-5">
                        <div>
                            <h2 class="text-lg font-semibold">What are you selling?</h2>
                            <p class="text-sm text-muted-foreground">Select the products you want to feature in this ad. The AI will write about them.</p>
                        </div>

                        <div v-if="products.length === 0" class="rounded-xl border border-dashed bg-muted/30 py-12 text-center">
                            <ShoppingBag class="mx-auto mb-3 size-8 text-muted-foreground" />
                            <p class="text-sm font-medium">No products yet</p>
                            <p class="mt-1 text-xs text-muted-foreground">Add products first, then come back to create an AI ad.</p>
                            <Button as-child variant="outline" size="sm" class="mt-4">
                                <Link href="/products">Add products →</Link>
                            </Button>
                        </div>

                        <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <button
                                v-for="p in products"
                                :key="p.id"
                                type="button"
                                :class="[
                                    'group relative overflow-hidden rounded-2xl border-2 bg-background text-left transition-all',
                                    avatarForm.product_ids.includes(p.id)
                                        ? 'border-[#E8563A] shadow-md shadow-[#E8563A]/10'
                                        : 'border-border hover:border-[#E8563A]/40',
                                ]"
                                @click="toggleAiProduct(p.id)"
                            >
                                <!-- Product image -->
                                <div class="aspect-square bg-muted">
                                    <img
                                        v-if="p.image_url"
                                        :src="p.image_url"
                                        :alt="p.title"
                                        class="h-full w-full object-cover"
                                    >
                                    <div v-else class="flex h-full items-center justify-center">
                                        <ImageOff class="size-8 text-muted-foreground/40" />
                                    </div>
                                </div>

                                <!-- Selected overlay -->
                                <div
                                    v-if="avatarForm.product_ids.includes(p.id)"
                                    class="absolute inset-0 bg-[#E8563A]/10"
                                >
                                    <div class="absolute right-2 top-2 flex size-6 items-center justify-center rounded-full bg-[#E8563A] shadow">
                                        <Check class="size-3.5 text-white" />
                                    </div>
                                </div>

                                <!-- Info -->
                                <div class="p-2.5">
                                    <p class="truncate text-xs font-semibold leading-tight">{{ p.title }}</p>
                                    <p class="mt-0.5 text-xs font-bold text-[#E8563A]">{{ formatPrice(p.currency, p.sale_price || p.price) }}</p>
                                </div>
                            </button>
                        </div>

                        <div class="flex justify-end pt-2">
                            <Button @click="goStep(2)">
                                {{ avatarForm.product_ids.length ? `Continue with ${avatarForm.product_ids.length} product${avatarForm.product_ids.length > 1 ? 's' : ''}` : 'Skip & continue' }}
                                <ChevronRight class="ml-1.5 size-4" />
                            </Button>
                        </div>
                    </div>

                    <!-- ═══ STEP 2: Script ═══ -->
                    <div v-else-if="aiStep === 2" class="space-y-5">
                        <div>
                            <h2 class="text-lg font-semibold">Write your ad script</h2>
                            <p class="text-sm text-muted-foreground">Tell the AI what kind of ad you want, then let it write the script.</p>
                        </div>

                        <Card>
                            <CardContent class="space-y-5 pt-5">
                                <div class="space-y-1.5">
                                    <Label>Topic / angle</Label>
                                    <Input v-model="scriptForm.topic" placeholder="e.g. Summer drop launch, unboxing, before & after…" />
                                </div>

                                <!-- Tone pills -->
                                <div class="space-y-1.5">
                                    <Label>Tone</Label>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="tone in ['engaging', 'luxury', 'urgent', 'friendly']"
                                            :key="tone"
                                            type="button"
                                            :class="[
                                                'rounded-full border px-4 py-1.5 text-sm font-medium capitalize transition-all',
                                                scriptForm.tone === tone
                                                    ? 'border-[#E8563A] bg-[#E8563A] text-white shadow-sm shadow-[#E8563A]/30'
                                                    : 'border-border text-muted-foreground hover:border-[#E8563A]/40 hover:text-foreground',
                                            ]"
                                            @click="scriptForm.tone = tone"
                                        >{{ tone }}</button>
                                    </div>
                                </div>

                                <!-- Duration quick-pick -->
                                <div class="space-y-1.5">
                                    <Label>Duration</Label>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            v-for="sec in [15, 30, 45, 60]"
                                            :key="sec"
                                            type="button"
                                            :class="[
                                                'rounded-full border px-4 py-1.5 text-sm font-medium transition-all',
                                                scriptForm.duration_seconds === sec
                                                    ? 'border-[#E8563A] bg-[#E8563A] text-white shadow-sm shadow-[#E8563A]/30'
                                                    : 'border-border text-muted-foreground hover:border-[#E8563A]/40 hover:text-foreground',
                                            ]"
                                            @click="scriptForm.duration_seconds = sec"
                                        >{{ sec }}s</button>
                                    </div>
                                </div>

                                <!-- Generate + Skip row -->
                                <div class="flex gap-2">
                                    <Button class="flex-1" :disabled="aiGenerating" @click="generateScript">
                                        <Loader2 v-if="aiGenerating" class="mr-2 size-4 animate-spin" />
                                        <Sparkles v-else class="mr-2 size-4" />
                                        {{ aiGenerating ? 'Writing…' : 'Generate with AI' }}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        :disabled="aiGenerating"
                                        class="shrink-0 gap-1.5 border-dashed text-muted-foreground hover:text-foreground"
                                        @click="skipScript"
                                    >
                                        Skip (test)
                                    </Button>
                                </div>

                                <!-- Notice -->
                                <p
                                    v-if="scriptGenerationNotice"
                                    :class="[
                                        'rounded-lg border px-3 py-2 text-xs',
                                        scriptGenerationNotice.includes('TEST MODE')
                                            ? 'border-amber-300 bg-amber-50 text-amber-800 dark:bg-amber-950/30 dark:text-amber-400'
                                            : scriptGenerationNotice.includes('No OpenAI')
                                                ? 'border-orange-300 bg-orange-50 text-orange-800 dark:bg-orange-950/30 dark:text-orange-400'
                                                : 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-400',
                                    ]"
                                >
                                    {{ scriptGenerationNotice }}
                                </p>

                                <!-- Script editor -->
                                <div v-if="avatarForm.script" class="space-y-1.5">
                                    <Label>Script — edit freely</Label>
                                    <textarea
                                        v-model="avatarForm.script"
                                        rows="8"
                                        class="w-full rounded-xl border bg-background px-3 py-2.5 text-sm leading-relaxed placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        <div class="flex items-center justify-between pt-2">
                            <Button variant="ghost" @click="goStep(1)">
                                <ChevronLeft class="mr-1.5 size-4" />
                                Back
                            </Button>
                            <Button :disabled="!avatarForm.script" @click="goStep(3)">
                                Continue
                                <ChevronRight class="ml-1.5 size-4" />
                            </Button>
                        </div>
                    </div>

                    <!-- ═══ STEP 3: Presenter & Voice ═══ -->
                    <div v-else-if="aiStep === 3" class="flex flex-col gap-4">
                        <!-- Header row -->
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold">Choose your presenter</h2>
                                <p class="text-sm text-muted-foreground">Pick an avatar, then pick a voice. Hit play to audition.</p>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                :disabled="heygenLoading"
                                class="h-7 shrink-0 gap-1 text-xs"
                                @click="loadHeyGenOptions(true)"
                            >
                                <Loader2 v-if="heygenLoading" class="size-3 animate-spin" />
                                Refresh
                            </Button>
                        </div>

                        <!-- Error / no-key banner -->
                        <div
                            v-if="heygenError || heygenOptions.message"
                            class="rounded-xl border border-dashed bg-muted/30 py-6 text-center"
                        >
                            <Clapperboard class="mx-auto mb-2 size-6 text-muted-foreground" />
                            <p class="text-sm text-muted-foreground">{{ heygenError || heygenOptions.message }}</p>
                        </div>

                        <!-- Inner tab switcher: Avatars / Voices -->
                        <div class="inline-flex self-start rounded-xl border bg-muted/40 p-1">
                            <button
                                type="button"
                                :class="[
                                    'flex items-center gap-1.5 rounded-lg px-4 py-1.5 text-sm font-medium transition-all',
                                    presenterTab === 'avatars'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                @click="presenterTab = 'avatars'"
                            >
                                <Users class="size-3.5" />
                                Avatars
                                <span v-if="avatarForm.avatar_id" class="ml-0.5 size-2 rounded-full bg-[#E8563A]" />
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'flex items-center gap-1.5 rounded-lg px-4 py-1.5 text-sm font-medium transition-all',
                                    presenterTab === 'voices'
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                @click="presenterTab = 'voices'"
                            >
                                <Play class="size-3.5" />
                                Voices
                                <span v-if="avatarForm.voice_id" class="ml-0.5 size-2 rounded-full bg-[#E8563A]" />
                            </button>
                        </div>

                        <!-- ── AVATARS panel ── -->
                        <div v-if="presenterTab === 'avatars'">
                            <!-- Avatar filters -->
                            <div class="mb-3 space-y-2 rounded-2xl border bg-muted/20 p-3">
                                <div class="relative">
                                    <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        v-model="avatarSearch"
                                        placeholder="Search niche, look, industry, style..."
                                        class="h-9 pl-9 text-sm"
                                    />
                                </div>
                                <div class="grid grid-cols-3 gap-2">
                                    <select
                                        v-model="avatarGenderFilter"
                                        class="h-9 rounded-lg border bg-background px-2 text-xs"
                                    >
                                        <option value="all">All genders</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                    <select
                                        v-model="avatarTypeFilter"
                                        class="h-9 rounded-lg border bg-background px-2 text-xs"
                                    >
                                        <option value="all">All types</option>
                                        <option
                                            v-for="type in avatarTypes"
                                            :key="type"
                                            :value="type"
                                        >
                                            {{ type.replace(/_/g, ' ') }}
                                        </option>
                                    </select>
                                    <select
                                        v-model="avatarOwnershipFilter"
                                        class="h-9 rounded-lg border bg-background px-2 text-xs"
                                    >
                                        <option value="all">All library</option>
                                        <option value="private">My avatars</option>
                                        <option value="public">Public</option>
                                    </select>
                                </div>
                                <p class="text-[11px] text-muted-foreground">
                                    Showing {{ filteredHeyGenAvatars.length }} of {{ heygenOptions.avatars.length }} avatars. Use Refresh to pull the wider HeyGen catalog.
                                </p>
                            </div>

                            <!-- Loading skeletons -->
                            <div v-if="heygenLoading" class="grid grid-cols-3 gap-2.5">
                                <div v-for="n in 6" :key="n" class="animate-pulse overflow-hidden rounded-2xl border">
                                    <div class="aspect-3/4 bg-muted" />
                                    <div class="space-y-1.5 p-2.5">
                                        <div class="h-2.5 w-2/3 rounded bg-muted" />
                                        <div class="h-2 w-1/2 rounded bg-muted" />
                                    </div>
                                </div>
                            </div>

                            <!-- Avatar grid — capped height, scrollable -->
                            <div
                                v-else-if="filteredHeyGenAvatars.length"
                                class="max-h-[420px] overflow-y-auto overscroll-contain rounded-2xl pr-1"
                            >
                                <div class="grid grid-cols-3 gap-2.5">
                                    <button
                                        v-for="avatar in filteredHeyGenAvatars"
                                        :key="avatar.id"
                                        type="button"
                                        :class="[
                                            'group overflow-hidden rounded-2xl border-2 bg-background text-left transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            avatarForm.avatar_id === avatar.id
                                                ? 'border-[#E8563A] shadow-lg shadow-[#E8563A]/10'
                                                : 'border-border hover:border-[#E8563A]/40',
                                        ]"
                                        @click="selectHeyGenAvatar(avatar); presenterTab = 'voices'"
                                    >
                                        <div class="relative aspect-3/4 overflow-hidden bg-muted">
                                            <img
                                                v-if="avatar.preview_image_url"
                                                :src="avatar.preview_image_url"
                                                :alt="avatar.name"
                                                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                            >
                                            <div v-else class="flex h-full items-center justify-center">
                                                <ImageOff class="size-6 text-muted-foreground/30" />
                                            </div>
                                            <div
                                                v-if="avatarForm.avatar_id === avatar.id"
                                                class="absolute inset-0 bg-[#E8563A]/10"
                                            >
                                                <div class="absolute right-1.5 top-1.5 flex size-6 items-center justify-center rounded-full bg-[#E8563A] shadow-md">
                                                    <Check class="size-3.5 text-white" />
                                                </div>
                                            </div>
                                            <span
                                                v-if="avatar.ownership === 'private'"
                                                class="absolute bottom-1.5 left-1.5 rounded-full bg-[#E8563A]/90 px-1.5 py-0.5 text-[9px] font-semibold text-white backdrop-blur-sm"
                                            >Mine</span>
                                        </div>
                                        <div class="px-2 py-2">
                                            <p class="truncate text-xs font-semibold leading-tight">{{ avatar.name }}</p>
                                            <p class="mt-0.5 truncate text-[10px] capitalize text-muted-foreground">
                                                {{ avatar.gender ?? avatar.avatar_type?.replace(/_/g, ' ') ?? 'HeyGen' }}
                                            </p>
                                        </div>
                                    </button>
                                </div>
                            </div>

                            <p v-else-if="!heygenLoading && !heygenError" class="rounded-xl border border-dashed bg-muted/30 py-6 text-center text-sm text-muted-foreground">
                                No avatars match these filters. Try All genders / All types, or click Refresh.
                            </p>

                            <!-- Tip when avatar picked -->
                            <p v-if="avatarForm.avatar_id && !heygenLoading" class="mt-3 text-xs text-muted-foreground">
                                ✓ <strong>{{ selectedHeyGenAvatar?.name }}</strong> selected — now pick a voice
                                <button type="button" class="ml-1.5 text-[#E8563A] underline underline-offset-2" @click="presenterTab = 'voices'">Go to Voices →</button>
                            </p>
                        </div>

                        <!-- ── VOICES panel ── -->
                        <div v-else-if="presenterTab === 'voices'">
                            <!-- Voice list — capped height, scrollable -->
                            <div
                                v-if="heygenOptions.voices.length"
                                class="max-h-[420px] overflow-y-auto overscroll-contain rounded-2xl pr-1"
                            >
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <button
                                        v-for="voice in heygenOptions.voices"
                                        :key="voice.voice_id"
                                        type="button"
                                        :class="[
                                            'flex items-center gap-3 rounded-2xl border-2 p-3 text-left transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            avatarForm.voice_id === voice.voice_id
                                                ? 'border-[#E8563A] bg-[#E8563A]/5 shadow-md shadow-[#E8563A]/10'
                                                : 'border-border hover:border-[#E8563A]/40',
                                        ]"
                                        @click="avatarForm.voice_id = voice.voice_id"
                                    >
                                        <!-- Play button -->
                                        <button
                                            v-if="voice.preview_audio_url"
                                            type="button"
                                            :class="[
                                                'flex size-9 shrink-0 items-center justify-center rounded-full border-2 transition-all',
                                                playingVoiceId === voice.voice_id
                                                    ? 'border-[#E8563A] bg-[#E8563A] text-white shadow-md shadow-[#E8563A]/30'
                                                    : 'border-border bg-background text-muted-foreground hover:border-[#E8563A]/50 hover:text-[#E8563A]',
                                            ]"
                                            @click.stop="toggleVoicePreview(voice)"
                                        >
                                            <Pause v-if="playingVoiceId === voice.voice_id" class="size-3.5" />
                                            <Play v-else class="ml-0.5 size-3.5" />
                                        </button>
                                        <div v-else class="flex size-9 shrink-0 items-center justify-center rounded-full border-2 border-dashed border-muted-foreground/20 text-muted-foreground/30">
                                            <Play class="ml-0.5 size-3.5" />
                                        </div>

                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold">{{ voice.name }}</p>
                                            <div class="mt-0.5 flex flex-wrap items-center gap-1">
                                                <span v-if="voice.language" class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">{{ voice.language }}</span>
                                                <span v-if="voice.gender" class="rounded-full bg-muted px-1.5 py-0.5 text-[10px] capitalize text-muted-foreground">{{ voice.gender }}</span>
                                            </div>
                                        </div>

                                        <div v-if="avatarForm.voice_id === voice.voice_id" class="shrink-0">
                                            <div class="flex size-5 items-center justify-center rounded-full bg-[#E8563A]">
                                                <Check class="size-3 text-white" />
                                            </div>
                                        </div>
                                    </button>
                                </div>
                            </div>
                            <p v-else-if="!heygenLoading" class="text-sm text-muted-foreground">No voices available. Check your HeyGen API key.</p>
                        </div>

                        <!-- Nav row -->
                        <div class="flex items-center justify-between pt-1">
                            <Button variant="ghost" @click="goStep(2)">
                                <ChevronLeft class="mr-1.5 size-4" />
                                Back
                            </Button>
                            <Button @click="goStep(4)">
                                Continue
                                <ChevronRight class="ml-1.5 size-4" />
                            </Button>
                        </div>
                    </div>

                    <!-- ═══ STEP 4: Review & Generate ═══ -->
                    <div v-else-if="aiStep === 4" class="space-y-5">
                        <div>
                            <h2 class="text-lg font-semibold">Review & generate</h2>
                            <p class="text-sm text-muted-foreground">Everything looks good? Hit generate and your AI presenter video will be queued.</p>
                        </div>

                        <!-- Summary cards row -->
                        <div class="grid grid-cols-3 gap-3">
                            <!-- Products summary -->
                            <div class="rounded-2xl border bg-muted/30 p-3 text-center">
                                <ShoppingBag class="mx-auto mb-1.5 size-5 text-[#E8563A]" />
                                <p class="text-lg font-bold">{{ avatarSelectedProducts.length }}</p>
                                <p class="text-xs text-muted-foreground">Product{{ avatarSelectedProducts.length !== 1 ? 's' : '' }}</p>
                            </div>

                            <!-- Avatar summary -->
                            <div class="overflow-hidden rounded-2xl border bg-muted/30">
                                <div v-if="selectedHeyGenAvatar?.preview_image_url"                                     class="relative aspect-3/4 bg-muted">
                                    <img :src="selectedHeyGenAvatar.preview_image_url" class="h-full w-full object-cover object-top" :alt="selectedHeyGenAvatar.name">
                                </div>
                                <div v-else class="flex h-16 items-center justify-center bg-muted">
                                    <Users class="size-5 text-muted-foreground" />
                                </div>
                                <p class="truncate px-2 py-1.5 text-center text-xs font-medium">
                                    {{ selectedHeyGenAvatar?.name ?? 'Auto-select' }}
                                </p>
                            </div>

                            <!-- Voice summary -->
                            <div class="rounded-2xl border bg-muted/30 p-3 text-center">
                                <div class="mx-auto mb-1.5 flex size-8 items-center justify-center rounded-full bg-[#E8563A]/10">
                                    <Play class="ml-0.5 size-4 text-[#E8563A]" />
                                </div>
                                <p class="truncate text-xs font-semibold">{{ selectedHeyGenVoice?.name ?? 'Default' }}</p>
                                <p class="text-[10px] text-muted-foreground capitalize">{{ selectedHeyGenVoice?.gender ?? '' }}</p>
                            </div>
                        </div>

                        <Card>
                            <CardContent class="space-y-4 pt-5">
                                <div class="space-y-1">
                                    <Label>Product ad style</Label>
                                    <p class="text-xs text-muted-foreground">
                                        Choose how HeyGen should use a product visual. Uploaded visuals are sent to HeyGen as reusable assets.
                                    </p>
                                </div>

                                <div class="grid gap-2 sm:grid-cols-2">
                                    <button
                                        v-for="style in adStyleOptions"
                                        :key="style.value"
                                        type="button"
                                        :class="[
                                            'rounded-2xl border p-3 text-left transition-all',
                                            avatarForm.ad_style === style.value
                                                ? 'border-[#E8563A] bg-[#E8563A]/10 ring-2 ring-[#E8563A]/20'
                                                : 'border-border bg-background hover:border-[#E8563A]/40',
                                        ]"
                                        @click="avatarForm.ad_style = style.value"
                                    >
                                        <p class="text-sm font-semibold">{{ style.title }}</p>
                                        <p class="mt-1 text-xs text-muted-foreground">{{ style.description }}</p>
                                    </button>
                                </div>

                                <div v-if="avatarForm.ad_style !== 'avatar_only'" class="grid gap-3 rounded-2xl border bg-muted/20 p-3 sm:grid-cols-[96px_1fr]">
                                    <div class="flex aspect-square items-center justify-center overflow-hidden rounded-xl border bg-background">
                                        <img
                                            v-if="adVisualPreviewUrl || avatarForm.visual_url || avatarSelectedProducts[0]?.image_url"
                                            :src="adVisualPreviewUrl || avatarForm.visual_url || avatarSelectedProducts[0]?.image_url || ''"
                                            class="h-full w-full object-cover"
                                            alt="Product ad visual"
                                        >
                                        <ImageOff v-else class="size-6 text-muted-foreground/40" />
                                    </div>

                                    <div class="space-y-2">
                                        <div>
                                            <p class="text-sm font-medium">Ad visual image</p>
                                            <p class="text-xs text-muted-foreground">
                                                Optional. If you do not upload one, the first selected product image will be used when it has a public HTTPS URL.
                                            </p>
                                        </div>

                                        <div class="flex flex-wrap gap-2">
                                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm font-medium transition-colors hover:border-[#E8563A]/40 hover:bg-[#E8563A]/5">
                                                <Loader2 v-if="adVisualUploading" class="size-4 animate-spin" />
                                                <Upload v-else class="size-4" />
                                                {{ adVisualUploading ? 'Uploading…' : 'Upload product visual' }}
                                                <input
                                                    type="file"
                                                    class="hidden"
                                                    accept="image/png,image/jpeg"
                                                    :disabled="adVisualUploading"
                                                    @change="onAdVisualFileSelected"
                                                >
                                            </label>

                                            <Button
                                                v-if="avatarForm.visual_url"
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                @click="clearAdVisual"
                                            >
                                                Remove visual
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardContent class="space-y-4 pt-5">
                                <div class="space-y-1.5">
                                    <Label>Video title <span class="text-destructive">*</span></Label>
                                    <Input v-model="avatarForm.title" placeholder="AI presenter product demo" />
                                </div>

                                <div class="space-y-1.5">
                                    <Label>Script</Label>
                                    <textarea
                                        v-model="avatarForm.script"
                                        rows="7"
                                        class="w-full rounded-xl border bg-background px-3 py-2.5 text-sm leading-relaxed placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        placeholder="Your script will appear here after Step 2…"
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Selected products chips -->
                        <div v-if="avatarSelectedProducts.length" class="flex flex-wrap gap-2">
                            <span
                                v-for="p in avatarSelectedProducts"
                                :key="p.id"
                                class="inline-flex items-center gap-1.5 rounded-full border bg-muted px-3 py-1 text-xs font-medium"
                            >
                                <img v-if="p.image_url" :src="p.image_url" class="size-4 rounded-full object-cover">
                                {{ p.title }}
                            </span>
                        </div>

                        <!-- Generate button -->
                        <Button
                            class="w-full bg-[#E8563A] text-white shadow-lg shadow-[#E8563A]/30 hover:bg-[#D44A2F]"
                            size="lg"
                            :disabled="aiGenerating || !avatarForm.title || !avatarForm.script"
                            @click="generateAvatarVideo"
                        >
                            <Loader2 v-if="aiGenerating" class="mr-2 size-5 animate-spin" />
                            <Sparkles v-else class="mr-2 size-5" />
                            {{ aiGenerating ? 'Queuing your video render…' : 'Generate AI avatar video' }}
                        </Button>

                        <p class="text-center text-xs text-muted-foreground">
                            Powered by HeyGen · renders in 1–5 min · you'll see it on the Videos page
                        </p>

                        <div class="flex justify-start pt-1">
                            <Button variant="ghost" @click="goStep(3)">
                                <ChevronLeft class="mr-1.5 size-4" />
                                Back
                            </Button>
                        </div>
                    </div>

                </template>
            </div>

            <!-- ── RIGHT: phone preview ── -->
            <div class="lg:col-span-2">
                <div class="sticky top-4 flex flex-col items-center gap-5">

                    <div class="flex w-full items-center justify-between px-1">
                        <p class="text-xs font-semibold uppercase tracking-widest text-muted-foreground">Live Preview</p>
                        <span class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">9:16</span>
                    </div>

                    <!-- Phone shell -->
                    <div class="relative w-full max-w-[260px]">
                        <!-- Outer body / bezel -->
                        <div class="relative overflow-hidden rounded-[38px] border-[7px] border-foreground/20 bg-black shadow-[0_32px_64px_-12px_rgba(0,0,0,0.4)] dark:shadow-[0_32px_64px_-12px_rgba(0,0,0,0.7)]" style="aspect-ratio: 9/19.5">

                            <!-- Dynamic Island notch -->
                            <div class="absolute left-1/2 top-3 z-20 h-[18px] w-[90px] -translate-x-1/2 rounded-full bg-black" />

                            <!-- Content layer -->
                            <div class="absolute inset-0">
                                <!-- Thumbnail -->
                                <img
                                    v-if="(thumbnailPreviewUrl || uploadForm.thumbnail_url) && !previewVideoUrl"
                                    :src="thumbnailPreviewUrl || uploadForm.thumbnail_url"
                                    alt=""
                                    class="h-full w-full object-cover"
                                >
                                <!-- Video -->
                                <video
                                    v-if="previewVideoUrl"
                                    :src="previewVideoUrl"
                                    :poster="thumbnailPreviewUrl || uploadForm.thumbnail_url || undefined"
                                    class="h-full w-full object-cover"
                                    autoplay muted loop playsinline
                                />
                                <!-- Placeholder gradient -->
                                <div
                                    v-if="!previewVideoUrl && !(thumbnailPreviewUrl || uploadForm.thumbnail_url)"
                                    class="flex h-full flex-col items-center justify-center gap-3 bg-linear-to-b from-zinc-900 to-zinc-800"
                                >
                                    <Film class="size-12 text-white/20" />
                                    <p class="text-xs text-white/30">Your video appears here</p>
                                </div>
                            </div>

                            <!-- Top HUD: views count -->
                            <div class="absolute left-0 right-0 top-0 z-10 flex items-start justify-between p-3 pt-8">
                                <div class="flex items-center gap-1 rounded-full bg-black/40 px-2 py-1 backdrop-blur-sm">
                                    <div class="size-1.5 animate-pulse rounded-full bg-red-500" />
                                    <span class="text-[10px] font-semibold text-white">LIVE</span>
                                </div>
                                <div class="flex items-center gap-1 rounded-full bg-black/40 px-2 py-1 text-[10px] font-semibold text-white backdrop-blur-sm">
                                    <Eye class="size-3" />
                                    <span>{{ viewerSim.enabled ? `${viewerSim.min}–${viewerSim.max}` : '—' }}</span>
                                </div>
                            </div>

                            <!-- Side action buttons (TikTok style) -->
                            <div class="absolute bottom-32 right-2 z-10 flex flex-col items-center gap-3.5">
                                <div class="flex flex-col items-center gap-0.5">
                                    <div class="flex size-9 items-center justify-center rounded-full bg-black/40 text-white backdrop-blur-sm">
                                        <Heart class="size-4 fill-[#E8563A] text-[#E8563A]" />
                                    </div>
                                    <span class="text-[8px] font-semibold text-white/80">2.4k</span>
                                </div>
                                <div class="flex flex-col items-center gap-0.5">
                                    <div class="flex size-9 items-center justify-center rounded-full bg-black/40 text-white backdrop-blur-sm">
                                        <MessageCircle class="size-4 fill-white/20 text-white" />
                                    </div>
                                    <span class="text-[8px] font-semibold text-white/80">148</span>
                                </div>
                                <div class="flex flex-col items-center gap-0.5">
                                    <div class="flex size-9 items-center justify-center rounded-full bg-black/40 text-white backdrop-blur-sm">
                                        <Share2 class="size-4 text-sky-400" />
                                    </div>
                                    <span class="text-[8px] font-semibold text-white/80">Share</span>
                                </div>
                                <div class="flex flex-col items-center gap-0.5">
                                    <div class="flex size-9 items-center justify-center rounded-full bg-black/40 text-white backdrop-blur-sm">
                                        <Bookmark class="size-4 fill-rose-500 text-rose-500" />
                                    </div>
                                    <span class="text-[8px] font-semibold text-white/80">Save</span>
                                </div>
                            </div>

                            <!-- Bottom gradient + product cards -->
                            <div class="absolute inset-x-0 bottom-0 z-10 bg-linear-to-t from-black via-black/70 to-transparent px-3 pb-4 pt-16">
                                <!-- Creator row -->
                                <div class="mb-3 flex items-center gap-2">
                                    <div class="size-7 rounded-full bg-linear-to-br from-[#E8563A] to-[#ff8c42]" />
                                    <div>
                                        <p class="text-[10px] font-bold text-white">@yourstore</p>
                                        <p class="text-[9px] text-white/60">Shoppable video</p>
                                    </div>
                                </div>

                                <!-- Product cards -->
                                <template v-if="(activeTab === 'upload' ? uploadSelectedProducts : avatarSelectedProducts).length">
                                    <div
                                        v-for="p in (activeTab === 'upload' ? uploadSelectedProducts : avatarSelectedProducts).slice(0, 2)"
                                        :key="p.id"
                                        class="mb-1.5 flex items-center gap-2 rounded-2xl bg-white/15 px-2.5 py-2 backdrop-blur-md"
                                    >
                                        <div class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white/20">
                                            <img v-if="p.image_url" :src="p.image_url" class="h-full w-full object-cover">
                                            <ShoppingBag v-else class="size-4 text-white/50" />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-[10px] font-bold leading-tight text-white">{{ p.title }}</p>
                                            <p class="text-[9px] font-semibold text-emerald-400">{{ formatPrice(p.currency, p.sale_price || p.price) }}</p>
                                        </div>
                                        <span class="shrink-0 rounded-full bg-white px-2 py-0.5 text-[9px] font-bold text-black shadow">Buy</span>
                                    </div>
                                    <p
                                        v-if="(activeTab === 'upload' ? uploadSelectedProducts : avatarSelectedProducts).length > 2"
                                        class="mt-1.5 text-center text-[9px] text-white/50"
                                    >
                                        +{{ (activeTab === 'upload' ? uploadSelectedProducts : avatarSelectedProducts).length - 2 }} more products
                                    </p>
                                </template>
                                <div v-else class="flex items-center justify-center gap-2 rounded-2xl border border-white/10 bg-white/5 py-2.5">
                                    <ShoppingBag class="size-3.5 text-white/30" />
                                    <p class="text-[9px] text-white/30">Tag products to see them here</p>
                                </div>
                            </div>
                        </div>

                        <!-- Phone side buttons (decorative) -->
                        <div class="absolute -right-1.5 top-24 h-12 w-1 rounded-r-full bg-foreground/10" />
                        <div class="absolute -left-1.5 top-20 h-8 w-1 rounded-l-full bg-foreground/10" />
                        <div class="absolute -left-1.5 top-32 h-12 w-1 rounded-l-full bg-foreground/10" />
                    </div>

                    <!-- Product list below phone -->
                    <div
                        v-if="(activeTab === 'upload' ? uploadSelectedProducts : avatarSelectedProducts).length"
                        class="w-full max-w-[260px] space-y-2"
                    >
                        <div class="flex items-center gap-1.5 px-1">
                            <Tag class="size-3.5 text-orange-500" />
                            <p class="text-xs font-semibold">{{ (activeTab === 'upload' ? uploadSelectedProducts : avatarSelectedProducts).length }} product{{ (activeTab === 'upload' ? uploadSelectedProducts : avatarSelectedProducts).length > 1 ? 's' : '' }} tagged</p>
                        </div>
                        <div
                            v-for="p in (activeTab === 'upload' ? uploadSelectedProducts : avatarSelectedProducts)"
                            :key="p.id"
                            class="flex items-center gap-2.5 rounded-xl border bg-card p-2.5"
                        >
                            <div class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted">
                                <img v-if="p.image_url" :src="p.image_url" class="h-full w-full object-cover">
                                <ImageOff v-else class="size-3.5 text-muted-foreground" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-semibold">{{ p.title }}</p>
                                <p class="text-[10px] font-medium text-muted-foreground">{{ formatPrice(p.currency, p.sale_price || p.price) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Product selection modal (upload tab) -->
    <Dialog v-model:open="productModalOpen">
        <DialogContent class="flex max-h-[85vh] flex-col gap-0 p-0 sm:max-w-[520px]">
            <DialogHeader class="shrink-0 border-b px-6 py-4">
                <DialogTitle class="flex items-center gap-2">
                    <Package class="size-4 text-orange-500" />
                    Attach Products
                </DialogTitle>
                <DialogDescription>
                    Selected products are shown below the video for viewers to purchase.
                </DialogDescription>
            </DialogHeader>

            <div class="shrink-0 border-b px-4 py-3">
                <div class="relative">
                    <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="productSearch" placeholder="Search products…" class="pl-9" />
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-4 py-3">
                <div v-if="filteredProducts.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                    No products found.
                    <Link href="/products" class="mt-1 block text-primary underline">Add products first →</Link>
                </div>
                <div v-else class="space-y-2">
                    <button
                        v-for="product in filteredProducts"
                        :key="product.id"
                        type="button"
                        :class="[
                            'flex w-full items-center gap-3 rounded-xl border p-3 text-left transition-colors',
                            activeProductIds.includes(product.id) ? 'border-primary/60 bg-primary/5' : 'hover:bg-muted/50',
                        ]"
                        @click="toggleProductInModal(product.id)"
                    >
                        <div class="shrink-0">
                            <img v-if="product.image_url" :src="product.image_url" :alt="product.title" class="h-12 w-12 rounded-lg border object-cover">
                            <div v-else class="flex h-12 w-12 items-center justify-center rounded-lg border bg-muted">
                                <ImageOff class="size-5 text-muted-foreground" />
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ product.title }}</p>
                            <p v-if="product.description" class="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{{ product.description }}</p>
                            <p class="mt-1 text-sm font-semibold">
                                {{ formatPrice(product.currency, product.sale_price || product.price) }}
                                <span v-if="product.sale_price" class="ml-1 text-xs font-normal text-muted-foreground line-through">
                                    {{ formatPrice(product.currency, product.price) }}
                                </span>
                            </p>
                        </div>
                        <div :class="[
                            'flex size-6 shrink-0 items-center justify-center rounded-full border-2 transition-all',
                            activeProductIds.includes(product.id) ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/40',
                        ]">
                            <Check v-if="activeProductIds.includes(product.id)" class="size-3.5" />
                        </div>
                    </button>
                </div>
            </div>

            <DialogFooter class="shrink-0 border-t px-6 py-4">
                <p class="mr-auto text-sm text-muted-foreground">{{ activeProductIds.length }} selected</p>
                <Button @click="productModalOpen = false">Done</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.create-root {
    background-color: #F2EFEA;
}
</style>
