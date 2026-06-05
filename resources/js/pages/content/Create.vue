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
    Globe,
    Heart,
    ImageOff,
    Link2,
    Lock,
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
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
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
import { setPendingVideoUpload } from '@/lib/pendingVideoUpload';
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

const { teamId, apiFetch, getList, postJson, ensureTeam } = useAdminApi();

type CreateMode = 'upload' | 'ai';
const createMode = ref<CreateMode | null>(null);
const wizardStep = ref(1);

const wizardSteps = computed(() => {
    const base = [
        { n: 1, label: 'Method', icon: Clapperboard },
        { n: 2, label: 'Products', icon: ShoppingBag },
    ];

    if (createMode.value === 'upload') {
        return [
            ...base,
            { n: 3, label: 'Video', icon: Film },
            { n: 4, label: 'Publish', icon: Upload },
        ];
    }

    if (createMode.value === 'ai') {
        return [
            ...base,
            { n: 3, label: 'Script', icon: Wand2 },
            { n: 4, label: 'Presenter', icon: Users },
            { n: 5, label: 'Generate', icon: Sparkles },
        ];
    }

    return [{ n: 1, label: 'Method', icon: Clapperboard }];
});

const maxWizardStep = computed(() => wizardSteps.value.length);

function goWizardStep(n: number) {
    wizardStep.value = Math.max(1, Math.min(maxWizardStep.value, n));
}

function selectCreateMode(mode: CreateMode) {
    createMode.value = mode;
    wizardStep.value = 2;
}

function syncSharedProducts(ids: number[]) {
    uploadForm.value.product_ids = [...ids];
    avatarForm.value.product_ids = [...ids];
    scriptForm.value.product_ids = [...ids];
}

function sharedProductIds(): number[] {
    return createMode.value === 'ai'
        ? avatarForm.value.product_ids
        : uploadForm.value.product_ids;
}

const selectedProducts = computed(() => {
    const ids = sharedProductIds();

    return products.value.filter((p) => ids.includes(p.id));
});

const canContinueWizard = computed(() => {
    if (wizardStep.value === 1) {
        return createMode.value !== null;
    }

    if (wizardStep.value === 2) {
        return true;
    }

    if (createMode.value === 'upload' && wizardStep.value === 3) {
        return Boolean(selectedFile.value && uploadForm.value.title.trim());
    }

    if (createMode.value === 'ai' && wizardStep.value === 3) {
        return Boolean(avatarForm.value.script.trim());
    }

    if (createMode.value === 'ai' && wizardStep.value === 4) {
        return Boolean(avatarForm.value.avatar_id);
    }

    return true;
});

function continueWizard() {
    if (!canContinueWizard.value) {
        return;
    }

    goWizardStep(wizardStep.value + 1);
}

function backWizard() {
    if (wizardStep.value === 2 && createMode.value) {
        createMode.value = null;
        wizardStep.value = 1;

        return;
    }

    goWizardStep(wizardStep.value - 1);
}

/* legacy alias for AI sub-navigation inside template */
const aiStep = computed({
    get: () => Math.max(1, wizardStep.value - 1),
    set: (n: number) => {
        wizardStep.value = n + 1;
    },
});

function goStep(n: number) {
    wizardStep.value = n + 1;
}

/* ── global error / notice ── */
const errorText = ref('');
const scriptGenerationNotice = ref('');
const scriptEntryMode = ref<'ai' | 'manual'>('ai');
const manualScriptPanelOpen = ref(false);

/* ── upload form state ── */
const uploading = ref(false);
const products = ref<ProductOption[]>([]);
const heygenOptions = ref<HeyGenOptions>({ enabled: false, avatars: [], voices: [], cached_at: null, message: null });
const heygenLoading = ref(false);
const heygenError = ref('');
const aiGenerating = ref(false);

/* ── video file + preview ── */
const selectedFile = ref<File | null>(null);
const videoFileInputRef = ref<HTMLInputElement | null>(null);
const previewVideoUrl = ref<string | null>(null);
const thumbnailPreviewUrl = ref<string | null>(null);
/* ── product modal ── */
const productModalOpen = ref(false);
const productSearch = ref('');
const aiProductSearch = ref('');
const PRODUCTS_PER_PAGE = 5;
const aiProductPage = ref(1);
const modalProductPage = ref(1);

function filterProductsByQuery(list: ProductOption[], query: string): ProductOption[] {
    const q = query.trim().toLowerCase();

    if (!q) {
        return list;
    }

    return list.filter(
        (p) =>
            p.title.toLowerCase().includes(q)
            || (p.description?.toLowerCase().includes(q) ?? false),
    );
}

const filteredProducts = computed(() => filterProductsByQuery(products.value, productSearch.value));

const filteredAiProducts = computed(() => filterProductsByQuery(products.value, aiProductSearch.value));

const aiProductTotalPages = computed(() =>
    Math.max(1, Math.ceil(filteredAiProducts.value.length / PRODUCTS_PER_PAGE)),
);

const modalProductTotalPages = computed(() =>
    Math.max(1, Math.ceil(filteredProducts.value.length / PRODUCTS_PER_PAGE)),
);

const paginatedAiProducts = computed(() => {
    const start = (aiProductPage.value - 1) * PRODUCTS_PER_PAGE;

    return filteredAiProducts.value.slice(start, start + PRODUCTS_PER_PAGE);
});

const paginatedModalProducts = computed(() => {
    const start = (modalProductPage.value - 1) * PRODUCTS_PER_PAGE;

    return filteredProducts.value.slice(start, start + PRODUCTS_PER_PAGE);
});

watch(aiProductSearch, () => {
    aiProductPage.value = 1;
});

watch(productSearch, () => {
    modalProductPage.value = 1;
});

watch(aiProductTotalPages, (total) => {
    if (aiProductPage.value > total) {
        aiProductPage.value = total;
    }
});

watch(modalProductTotalPages, (total) => {
    if (modalProductPage.value > total) {
        modalProductPage.value = total;
    }
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

const visibilityOptions = [
    { value: 'public', label: 'Public', icon: Globe },
    { value: 'unlisted', label: 'Unlisted', icon: Link2 },
    { value: 'private', label: 'Private', icon: Lock },
] as const;

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
    product_ids: [] as number[],
    custom_background_enabled: false,
    background_color: '#f2efea',
});

const HEYGEN_BACKGROUND_PRESETS = [
    { label: 'Warm cream', value: '#f2efea' },
    { label: 'White', value: '#ffffff' },
    { label: 'Soft gray', value: '#f3f4f6' },
    { label: 'Charcoal', value: '#111827' },
    { label: 'Black', value: '#000000' },
    { label: 'Brand coral', value: '#e8563a' },
    { label: 'Navy', value: '#1e3a5f' },
    { label: 'Sage', value: '#d1e7dd' },
] as const;

const selectedBackgroundPreset = computed(() =>
    HEYGEN_BACKGROUND_PRESETS.find((preset) => preset.value === avatarForm.value.background_color)
        ?? HEYGEN_BACKGROUND_PRESETS[0],
);

function toggleCustomBackground(enabled: boolean) {
    avatarForm.value.custom_background_enabled = enabled;
    if (enabled && !avatarForm.value.background_color) {
        avatarForm.value.background_color = HEYGEN_BACKGROUND_PRESETS[0].value;
    }
}

function selectBackgroundColor(color: string) {
    avatarForm.value.background_color = color;
    avatarForm.value.custom_background_enabled = true;
}

const enableEmbedOverlays = ref(true);

const SUPPORTED_LANGUAGES = [
    { code: 'en', label: 'English' },
    { code: 'es', label: 'Spanish' },
    { code: 'fr', label: 'French' },
    { code: 'de', label: 'German' },
    { code: 'pt', label: 'Portuguese' },
    { code: 'it', label: 'Italian' },
    { code: 'ar', label: 'Arabic' },
    { code: 'hi', label: 'Hindi' },
    { code: 'zh', label: 'Chinese' },
    { code: 'ja', label: 'Japanese' },
] as const;

const multilingualMode = ref(false);
const selectedLanguages = ref<string[]>(['en']);

function toggleTargetLanguage(code: string) {
    if (selectedLanguages.value.includes(code)) {
        if (selectedLanguages.value.length > 1) {
            selectedLanguages.value = selectedLanguages.value.filter(
                (lang) => lang !== code,
            );
        }

        return;
    }

    if (selectedLanguages.value.length >= 8) {
        return;
    }

    selectedLanguages.value = [...selectedLanguages.value, code];
}

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
    if (!voice.preview_audio_url) {
return;
}

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

const activeProductIds = computed(() => sharedProductIds());

function toggleProductInModal(productId: number) {
    const ids = sharedProductIds();
    const idx = ids.indexOf(productId);

    if (idx === -1) {
        syncSharedProducts([...ids, productId]);
    } else {
        syncSharedProducts(ids.filter((id) => id !== productId));
    }
}

function toggleSharedProduct(productId: number) {
    const ids = sharedProductIds();
    const idx = ids.indexOf(productId);

    if (idx === -1) {
        syncSharedProducts([...ids, productId]);
    } else {
        syncSharedProducts(ids.filter((id) => id !== productId));
    }
}

/* In-step product toggle (wizard step 2) */
function toggleAiProduct(productId: number) {
    toggleSharedProduct(productId);
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
    if (!price) {
return '';
}

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

function applySelectedVideoFile(file: File) {
    selectedFile.value = file;

    if (previewVideoUrl.value) {
        URL.revokeObjectURL(previewVideoUrl.value);
    }

    previewVideoUrl.value = URL.createObjectURL(file);

    if (!uploadForm.value.title) {
        uploadForm.value.title = file.name.replace(/\.[^.]+$/, '');
    }
}

function openVideoFilePicker() {
    videoFileInputRef.value?.click();
}

function onFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    applySelectedVideoFile(file);
}

function onVideoDrop(event: DragEvent) {
    event.preventDefault();
    const file = event.dataTransfer?.files?.[0];

    if (!file || !file.type.startsWith('video/')) {
        return;
    }

    applySelectedVideoFile(file);

    if (videoFileInputRef.value) {
        videoFileInputRef.value.value = '';
    }
}

function onThumbnailFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
return;
}

    if (thumbnailPreviewUrl.value?.startsWith('blob:')) {
URL.revokeObjectURL(thumbnailPreviewUrl.value);
}

    thumbnailPreviewUrl.value = URL.createObjectURL(file);
}

onUnmounted(() => {
    if (previewVideoUrl.value) {
URL.revokeObjectURL(previewVideoUrl.value);
}

    if (thumbnailPreviewUrl.value?.startsWith('blob:')) {
URL.revokeObjectURL(thumbnailPreviewUrl.value);
}

    audioInstance?.pause();
});

function unwrapVideo(payload: unknown): VideoItem | null {
    if (!payload || typeof payload !== 'object') {
return null;
}

    if ('data' in payload) {
        const d = (payload as { data?: unknown }).data;

        if (d && typeof d === 'object' && 'id' in d) {
return d as VideoItem;
}
    }

    if ('id' in payload) {
return payload as VideoItem;
}

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
    if (!productIds.length) {
return;
}

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

        const payload = await postJson<unknown>('/api/v1/admin/videos', {
            title: uploadForm.value.title || selectedFile.value.name.replace(/\.[^.]+$/, ''),
            description: uploadForm.value.description || null,
            source: 'uploaded',
            visibility: uploadForm.value.visibility,
            thumbnail_url: uploadForm.value.thumbnail_url || null,
            awaiting_upload: true,
            metadata: viewerSim.value.enabled ? {
                viewer_sim_enabled: true,
                viewer_sim_min: viewerSim.value.min,
                viewer_sim_max: viewerSim.value.max,
            } : null,
        });

        const created = unwrapVideo(payload);

        if (created?.id) {
            await attachProducts(created.id, uploadForm.value.product_ids);
            setPendingVideoUpload(created.id, selectedFile.value);
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

const scriptTargetWords = computed(() => Math.max(30, Math.round(scriptForm.value.duration_seconds * 2.4)));

const scriptWordCount = computed(() => {
    const text = avatarForm.value.script.trim();

    if (!text) {
return 0;
}

    return text.split(/\s+/).filter(Boolean).length;
});

const scriptEstimatedSeconds = computed(() =>
    scriptWordCount.value > 0 ? Math.round(scriptWordCount.value / 2.4) : 0,
);

const showScriptEditor = computed(
    () =>
        manualScriptPanelOpen.value
        || (scriptEntryMode.value === 'ai' && avatarForm.value.script.trim().length > 0),
);

const canGenerateAvatar = computed(() =>
    Boolean(avatarForm.value.title && avatarForm.value.script),
);

async function generateScript() {
    scriptEntryMode.value = 'ai';
    manualScriptPanelOpen.value = false;
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

        if (gen.output?.full_script) {
avatarForm.value.script = gen.output.full_script;
}

        if (!avatarForm.value.title) {
avatarForm.value.title = `AI Video — ${scriptForm.value.topic}`;
}

        if (gen.provider && gen.provider !== 'openai') {
            scriptGenerationNotice.value = `Template script (~${scriptTargetWords.value} words for ${scriptForm.value.duration_seconds}s). Set OPENAI_API_KEY for AI-written scripts.`;
        } else {
            scriptGenerationNotice.value = `✓ Script generated for ~${scriptForm.value.duration_seconds}s (${scriptTargetWords.value} words target).`;
        }
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Script generation failed.';
    } finally {
        aiGenerating.value = false;
    }
}

function useManualScript() {
    scriptEntryMode.value = 'manual';
    manualScriptPanelOpen.value = true;
    scriptGenerationNotice.value = `Write or paste your own script below. Aim for ~${scriptTargetWords.value} words (~${scriptForm.value.duration_seconds}s when read aloud).`;
}

async function generateAvatarVideo() {
    aiGenerating.value = true;
    errorText.value = '';

    try {
        await ensureTeam();

        const useMultilingual =
            multilingualMode.value && selectedLanguages.value.length > 1;

        if (useMultilingual) {
            const payload = await postJson<{
                videos?: Array<{ video?: unknown; language?: string }>;
            }>('/api/v1/admin/ai/multilingual-videos', {
                ...avatarForm.value,
                enable_embed_overlays: enableEmbedOverlays.value,
                languages: selectedLanguages.value,
            });

            for (const item of payload.videos ?? []) {
                const video = unwrapVideo(item.video);

                if (video?.id) {
                    await attachProducts(video.id, avatarForm.value.product_ids);
                }
            }
        } else {
            const language = multilingualMode.value
                ? selectedLanguages.value[0] ?? 'en'
                : avatarForm.value.language;

            const payload = await postJson<unknown>(
                '/api/v1/admin/ai/avatar-videos',
                {
                    ...avatarForm.value,
                    enable_embed_overlays: enableEmbedOverlays.value,
                    language,
                },
            );

            const responseVideo =
                payload && typeof payload === 'object' && 'video' in payload
                    ? unwrapVideo((payload as { video?: unknown }).video)
                    : null;

            if (responseVideo?.id) {
                await attachProducts(
                    responseVideo.id,
                    avatarForm.value.product_ids,
                );
            }
        }

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

onMounted(() => {
    const mode = new URLSearchParams(window.location.search).get('mode');

    if (mode === 'upload' || mode === 'ai') {
        selectCreateMode(mode);
    }

    return Promise.all([loadProducts(), loadHeyGenOptions()]);
});
</script>

<template>
    <Head title="Create Shoppable Video" />

    <div class="create-root flex min-h-screen flex-1 flex-col gap-6 p-4 md:p-6">
    <!-- <div class="create-root flex h-full flex-1 flex-col gap-6 p-4 md:p-6"> -->

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
                <p class="mt-0.5 text-sm text-muted-foreground">One guided flow — pick how you create, tag products, then publish.</p>
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

        <!-- Unified wizard stepper -->
        <div v-if="createMode" class="mb-2">
            <div class="flex items-center gap-0">
                <template v-for="(step, i) in wizardSteps" :key="step.n">
                    <button
                        type="button"
                        :class="[
                            'flex flex-col items-center gap-1 px-1',
                            step.n <= wizardStep ? 'cursor-pointer' : 'cursor-default',
                        ]"
                        :disabled="step.n > wizardStep"
                        @click="step.n < wizardStep && goWizardStep(step.n)"
                    >
                        <div :class="[
                            'flex size-9 items-center justify-center rounded-full border-2 text-sm font-bold transition-all',
                            step.n < wizardStep
                                ? 'border-[#E8563A] bg-[#E8563A] text-white'
                                : step.n === wizardStep
                                    ? 'border-[#E8563A] bg-[#E8563A] text-white shadow-lg shadow-[#E8563A]/30'
                                    : 'border-muted-foreground/30 bg-muted text-muted-foreground',
                        ]">
                            <Check v-if="step.n < wizardStep" class="size-4" />
                            <component :is="step.icon" v-else class="size-4" />
                        </div>
                        <span :class="[
                            'text-[11px] font-medium',
                            step.n === wizardStep ? 'text-foreground' : 'text-muted-foreground',
                        ]">{{ step.label }}</span>
                    </button>
                    <div
                        v-if="i < wizardSteps.length - 1"
                        :class="[
                            'mb-4 h-0.5 flex-1 transition-colors',
                            step.n < wizardStep ? 'bg-[#E8563A]' : 'bg-[#E8563A]/20',
                        ]"
                    />
                </template>
            </div>
        </div>

        <!-- ═══════════════ MAIN GRID ═══════════════ -->
        <div class="grid gap-6 lg:grid-cols-5">

            <!-- ── LEFT COLUMN ── -->
            <div class="lg:col-span-3">

                <!-- ═══ STEP 1: Choose method ═══ -->
                <div v-if="wizardStep === 1" class="space-y-5">
                    <div>
                        <h2 class="text-lg font-semibold">How do you want to create?</h2>
                        <p class="text-sm text-muted-foreground">Both paths share product tagging and the same publish preview.</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <button
                            type="button"
                            class="group flex flex-col items-start gap-3 rounded-2xl border-2 border-border bg-white p-5 text-left transition-all hover:border-[#E8563A]/50 hover:shadow-md"
                            @click="selectCreateMode('upload')"
                        >
                            <div class="flex size-12 items-center justify-center rounded-xl bg-[#E8563A]/10 text-[#E8563A] transition-colors group-hover:bg-[#E8563A] group-hover:text-white">
                                <Upload class="size-6" />
                            </div>
                            <div>
                                <p class="font-semibold text-foreground">Upload a video</p>
                                <p class="mt-1 text-sm text-muted-foreground">Use your own clip — MP4, MOV, or WEBM. Tag products and publish to your shop feed.</p>
                            </div>
                        </button>
                        <button
                            type="button"
                            class="group flex flex-col items-start gap-3 rounded-2xl border-2 border-border bg-white p-5 text-left transition-all hover:border-[#E8563A]/50 hover:shadow-md"
                            @click="selectCreateMode('ai')"
                        >
                            <div class="flex size-12 items-center justify-center rounded-xl bg-linear-to-br from-[#E8563A] to-[#ff8c42] text-white shadow-sm">
                                <Sparkles class="size-6" />
                            </div>
                            <div>
                                <p class="font-semibold text-foreground">Generate with AI</p>
                                <p class="mt-1 text-sm text-muted-foreground">Pick products, write a script, choose a HeyGen presenter — we render the video for you.</p>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- ═══ STEP 2: Products (shared) ═══ -->
                <div v-else-if="wizardStep === 2" class="space-y-5">
                    <div>
                        <h2 class="text-lg font-semibold">What are you selling?</h2>
                        <p class="text-sm text-muted-foreground">
                            Select products to feature in this video. Viewers can buy directly from the player.
                        </p>
                    </div>

                    <div v-if="products.length === 0" class="rounded-xl border border-dashed bg-muted/30 py-12 text-center">
                        <ShoppingBag class="mx-auto mb-3 size-8 text-muted-foreground" />
                        <p class="text-sm font-medium">No products yet</p>
                        <p class="mt-1 text-xs text-muted-foreground">Add products first, then come back to create a video.</p>
                        <Button as-child variant="outline" size="sm" class="mt-4">
                            <Link href="/products">Add products →</Link>
                        </Button>
                    </div>

                    <template v-else>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs text-muted-foreground">
                                {{ filteredAiProducts.length }} of {{ products.length }} products
                                <span v-if="sharedProductIds().length">
                                    · {{ sharedProductIds().length }} selected
                                </span>
                            </p>
                            <p
                                v-if="filteredAiProducts.length > PRODUCTS_PER_PAGE"
                                class="text-xs font-medium text-muted-foreground"
                            >
                                Page {{ aiProductPage }} of {{ aiProductTotalPages }}
                            </p>
                        </div>

                        <div class="relative">
                            <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                v-model="aiProductSearch"
                                placeholder="Search products by name…"
                                class="h-10 pl-9 !bg-white"
                            />
                        </div>

                        <div
                            v-if="filteredAiProducts.length === 0"
                            class="rounded-xl border border-dashed py-8 text-center text-sm text-muted-foreground"
                        >
                            No products match your search.
                        </div>

                        <div v-else class="space-y-2">
                            <button
                                v-for="p in paginatedAiProducts"
                                :key="p.id"
                                type="button"
                                :class="[
                                    'flex w-full items-center gap-3 rounded-2xl border-2 bg-background p-3 text-left transition-all',
                                    sharedProductIds().includes(p.id)
                                        ? 'border-[#E8563A] bg-[#E8563A]/5 shadow-sm shadow-[#E8563A]/10'
                                        : 'border-border hover:border-[#E8563A]/35 hover:bg-muted/30',
                                ]"
                                @click="toggleSharedProduct(p.id)"
                            >
                                <div class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-muted">
                                    <img
                                        v-if="p.image_url"
                                        :src="p.image_url"
                                        :alt="p.title"
                                        class="h-full w-full object-cover"
                                    >
                                    <ImageOff v-else class="size-5 text-muted-foreground/50" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold text-foreground">{{ p.title }}</p>
                                    <p class="mt-0.5 text-sm font-medium text-[#E8563A]">
                                        {{ formatPrice(p.currency, p.sale_price || p.price) }}
                                    </p>
                                    <p
                                        v-if="p.description"
                                        class="mt-0.5 line-clamp-1 text-xs text-muted-foreground"
                                    >
                                        {{ p.description }}
                                    </p>
                                </div>
                                <div
                                    :class="[
                                        'flex size-7 shrink-0 items-center justify-center rounded-full border-2 transition-all',
                                        sharedProductIds().includes(p.id)
                                            ? 'border-[#E8563A] bg-[#E8563A] text-white'
                                            : 'border-muted-foreground/30 bg-background',
                                    ]"
                                >
                                    <Check v-if="sharedProductIds().includes(p.id)" class="size-4" />
                                </div>
                            </button>

                            <div
                                v-if="aiProductTotalPages > 1"
                                class="flex items-center justify-between gap-2 pt-1"
                            >
                                <Button type="button" variant="outline" size="sm" :disabled="aiProductPage <= 1" @click="aiProductPage--">
                                    Previous
                                </Button>
                                <span class="text-xs text-muted-foreground">
                                    Showing {{ paginatedAiProducts.length }} of {{ filteredAiProducts.length }}
                                </span>
                                <Button type="button" variant="outline" size="sm" :disabled="aiProductPage >= aiProductTotalPages" @click="aiProductPage++">
                                    Next
                                </Button>
                            </div>
                        </div>
                    </template>

                    <div class="flex items-center justify-between pt-2">
                        <Button variant="ghost" @click="backWizard">
                            <ChevronLeft class="mr-1.5 size-4" />
                            Back
                        </Button>
                        <Button @click="continueWizard">
                            {{ sharedProductIds().length ? `Continue with ${sharedProductIds().length} product${sharedProductIds().length > 1 ? 's' : ''}` : 'Skip & continue' }}
                            <ChevronRight class="ml-1.5 size-4" />
                        </Button>
                    </div>
                </div>

                <!-- ═══ UPLOAD: Step 3 — Video file & details ═══ -->
                <template v-else-if="createMode === 'upload' && wizardStep === 3">
                    <div class="space-y-5">
                        <div>
                            <h2 class="text-lg font-semibold">Upload your video</h2>
                            <p class="text-sm text-muted-foreground">Add your clip, set title and visibility, then review before publishing.</p>
                        </div>

                        <!-- ── Big drop zone at the top ── -->
                        <div
                            :class="[
                                'group relative flex min-h-52 cursor-pointer flex-col items-center justify-center gap-4 overflow-hidden rounded-2xl border-2 border-dashed transition-all',
                                selectedFile
                                    ? 'border-[#E8563A] bg-[#E8563A]/5'
                                    : 'border-border bg-white hover:border-[#E8563A]/50 hover:bg-[#E8563A]/5',
                            ]"
                            role="button"
                            tabindex="0"
                            @click="openVideoFilePicker"
                            @keydown.enter.prevent="openVideoFilePicker"
                            @keydown.space.prevent="openVideoFilePicker"
                            @dragover.prevent
                            @drop.prevent="onVideoDrop"
                        >
                            <!-- Background video preview when file selected -->
                            <video
                                v-if="previewVideoUrl"
                                :src="previewVideoUrl"
                                class="pointer-events-none absolute inset-0 z-0 h-full w-full object-cover opacity-30"
                                autoplay muted loop playsinline
                            />

                            <div class="pointer-events-none relative z-10 flex flex-col items-center gap-3 text-center">
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
                                ref="videoFileInputRef"
                                type="file"
                                accept="video/mp4,video/quicktime,video/webm,video/*"
                                class="sr-only"
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
                                            v-for="vis in visibilityOptions"
                                            :key="vis.value"
                                            type="button"
                                            :class="[
                                                'flex flex-1 items-center justify-center gap-1.5 rounded-xl border py-2 text-sm font-medium transition-all',
                                                uploadForm.visibility === vis.value
                                                    ? 'border-[#E8563A] bg-[#E8563A]/10 text-[#E8563A] font-semibold'
                                                    : 'border-border text-muted-foreground hover:border-[#E8563A]/40',
                                            ]"
                                            @click="uploadForm.visibility = vis.value"
                                        >
                                            <component :is="vis.icon" class="size-3.5 shrink-0" />
                                            {{ vis.label }}
                                        </button>
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

                        <!-- ── Tagged products summary ── -->
                        <Card v-if="selectedProducts.length">
                            <CardHeader class="pb-3">
                                <div class="flex items-center justify-between">
                                    <CardTitle class="text-base">{{ selectedProducts.length }} product{{ selectedProducts.length > 1 ? 's' : '' }} tagged</CardTitle>
                                    <Button type="button" variant="ghost" size="sm" @click="goWizardStep(2)">Edit</Button>
                                </div>
                            </CardHeader>
                            <CardContent class="pt-0">
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="p in selectedProducts.slice(0, 4)"
                                        :key="p.id"
                                        class="inline-flex items-center gap-1.5 rounded-full border bg-muted px-3 py-1 text-xs font-medium"
                                    >
                                        {{ p.title }}
                                    </span>
                                    <span v-if="selectedProducts.length > 4" class="text-xs text-muted-foreground">
                                        +{{ selectedProducts.length - 4 }} more
                                    </span>
                                </div>
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
                                        <div class="h-6 w-11 rounded-full bg-muted transition-colors peer-checked:bg-[#E8563A] after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-all peer-checked:after:translate-x-5" />
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

                        <div class="flex items-center justify-between pt-2">
                            <Button variant="ghost" @click="backWizard">
                                <ChevronLeft class="mr-1.5 size-4" />
                                Back
                            </Button>
                            <Button :disabled="!canContinueWizard" @click="continueWizard">
                                Review & publish
                                <ChevronRight class="ml-1.5 size-4" />
                            </Button>
                        </div>
                    </div>
                </template>

                <!-- ═══ UPLOAD: Step 4 — Review & publish ═══ -->
                <template v-else-if="createMode === 'upload' && wizardStep === 4">
                    <div class="space-y-5">
                        <div>
                            <h2 class="text-lg font-semibold">Review & publish</h2>
                            <p class="text-sm text-muted-foreground">Confirm everything looks right before going live.</p>
                        </div>

                        <Card>
                            <CardContent class="space-y-4 pt-5">
                                <div class="flex items-start gap-3">
                                    <Film class="mt-0.5 size-5 shrink-0 text-[#E8563A]" />
                                    <div>
                                        <p class="font-semibold">{{ uploadForm.title || 'Untitled video' }}</p>
                                        <p class="text-sm text-muted-foreground">{{ selectedFile?.name }} · {{ selectedFile ? (selectedFile.size / 1024 / 1024).toFixed(1) : 0 }} MB</p>
                                    </div>
                                </div>
                                <div class="grid gap-2 text-sm sm:grid-cols-2">
                                    <div class="rounded-xl bg-muted/50 px-3 py-2">
                                        <p class="text-xs text-muted-foreground">Visibility</p>
                                        <p class="font-medium capitalize">{{ uploadForm.visibility }}</p>
                                    </div>
                                    <div class="rounded-xl bg-muted/50 px-3 py-2">
                                        <p class="text-xs text-muted-foreground">Products</p>
                                        <p class="font-medium">{{ selectedProducts.length }} tagged</p>
                                    </div>
                                </div>
                                <p v-if="uploadForm.description" class="text-sm text-muted-foreground">{{ uploadForm.description }}</p>
                            </CardContent>
                        </Card>

                        <Button
                            class="h-12 w-full bg-[#E8563A] text-base font-semibold text-white shadow-lg shadow-[#E8563A]/30 hover:bg-[#D44A2F]"
                            :disabled="uploading || !selectedFile"
                            @click="submitUpload"
                        >
                            <Loader2 v-if="uploading" class="mr-2 size-5 animate-spin" />
                            <Upload v-else class="mr-2 size-5" />
                            {{ uploading ? 'Publishing…' : 'Publish shoppable video' }}
                        </Button>

                        <div class="flex justify-start">
                            <Button variant="ghost" @click="backWizard">
                                <ChevronLeft class="mr-1.5 size-4" />
                                Back
                            </Button>
                        </div>
                    </div>
                </template>

                <!-- ═══ AI: Steps 3–5 ═══ -->
                <template v-else-if="createMode === 'ai' && wizardStep >= 3">

                    <!-- ═══ STEP 2: Script ═══ -->
                    <div v-if="aiStep === 2" class="space-y-5">
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
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <Label>Duration</Label>
                                        <span class="text-xs text-muted-foreground">
                                            Target ~{{ scriptTargetWords }} words at natural pace
                                        </span>
                                    </div>
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

                                <!-- Generate + manual row -->
                                <div class="flex gap-2">
                                    <Button class="flex-1" :disabled="aiGenerating" @click="generateScript">
                                        <Loader2 v-if="aiGenerating" class="mr-2 size-4 animate-spin" />
                                        <Sparkles v-else class="mr-2 size-4" />
                                        {{ aiGenerating ? 'Writing…' : 'Generate with AI' }}
                                    </Button>
                                    <Button
                                        type="button"
                                        :variant="scriptEntryMode === 'manual' ? 'default' : 'outline'"
                                        :disabled="aiGenerating"
                                        class="shrink-0"
                                        @click="useManualScript"
                                    >
                                        Write manually
                                    </Button>
                                </div>

                                <!-- Notice -->
                                <p
                                    v-if="scriptGenerationNotice"
                                    :class="[
                                        'rounded-lg border px-3 py-2 text-xs',
                                        scriptGenerationNotice.includes('OPENAI')
                                            || scriptGenerationNotice.includes('Template')
                                            ? 'border-orange-300 bg-orange-50 text-orange-800 dark:bg-orange-950/30 dark:text-orange-400'
                                            : scriptEntryMode === 'manual'
                                                ? 'border-blue-300 bg-blue-50 text-blue-900 dark:bg-blue-950/30 dark:text-blue-300'
                                                : 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-400',
                                    ]"
                                >
                                    {{ scriptGenerationNotice }}
                                </p>

                                <!-- Script editor (AI: after generate; manual: after "Write manually") -->
                                <div v-if="showScriptEditor" class="space-y-1.5">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <Label>
                                            {{ scriptEntryMode === 'manual' ? 'Your script' : 'Script — edit freely' }}
                                        </Label>
                                        <span
                                            v-if="scriptWordCount > 0"
                                            class="text-xs"
                                            :class="Math.abs(scriptEstimatedSeconds - scriptForm.duration_seconds) <= 8
                                                ? 'text-muted-foreground'
                                                : 'font-medium text-amber-700 dark:text-amber-400'"
                                        >
                                            {{ scriptWordCount }} words · ~{{ scriptEstimatedSeconds }}s read
                                            <template v-if="Math.abs(scriptEstimatedSeconds - scriptForm.duration_seconds) > 8">
                                                (target {{ scriptForm.duration_seconds }}s)
                                            </template>
                                        </span>
                                    </div>
                                    <textarea
                                        v-model="avatarForm.script"
                                        :rows="scriptEntryMode === 'manual' ? 12 : 10"
                                        :placeholder="scriptEntryMode === 'manual'
                                            ? `Paste or type your script here… Aim for about ${scriptTargetWords} words for a ${scriptForm.duration_seconds}-second video.`
                                            : 'Generate with AI or switch to Write manually to paste your own script…'"
                                        class="w-full rounded-xl border bg-background px-3 py-2.5 text-sm leading-relaxed placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    />
                                </div>
                            </CardContent>
                        </Card>

                        <div class="flex items-center justify-between pt-2">
                            <Button variant="ghost" @click="backWizard">
                                <ChevronLeft class="mr-1.5 size-4" />
                                Back
                            </Button>
                            <Button :disabled="!avatarForm.script.trim()" @click="goStep(3)">
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

                        <div
                            v-if="avatarForm.avatar_id"
                            class="space-y-3 rounded-2xl border bg-white p-3"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="space-y-1">
                                    <Label for="custom-background-toggle">Custom video background</Label>
                                    <p class="text-xs text-muted-foreground">
                                        Replaces the presenter&apos;s scene with your color (HeyGen removes the original background first).
                                    </p>
                                    <p
                                        v-if="selectedHeyGenAvatar?.avatar_type === 'photo_avatar'"
                                        class="text-xs text-amber-800 dark:text-amber-200"
                                    >
                                        Photo avatars like {{ selectedHeyGenAvatar.name }} include a room in the source image — results vary; plain-backdrop studio avatars work best.
                                    </p>
                                </div>
                                <label class="relative mt-0.5 inline-flex shrink-0 cursor-pointer items-center">
                                    <input
                                        id="custom-background-toggle"
                                        :checked="avatarForm.custom_background_enabled"
                                        type="checkbox"
                                        class="peer sr-only"
                                        @change="toggleCustomBackground(($event.target as HTMLInputElement).checked)"
                                    >
                                    <span
                                        class="h-6 w-11 rounded-full bg-muted transition peer-checked:bg-[#E8563A] peer-focus-visible:ring-2 peer-focus-visible:ring-ring"
                                    />
                                    <span
                                        class="absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"
                                    />
                                </label>
                            </div>

                            <div v-if="avatarForm.custom_background_enabled" class="space-y-2">
                                <p class="text-xs font-medium text-foreground">Background color</p>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="preset in HEYGEN_BACKGROUND_PRESETS"
                                        :key="preset.value"
                                        type="button"
                                        :title="preset.label"
                                        :class="[
                                            'flex size-10 items-center justify-center rounded-xl border-2 transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            avatarForm.background_color === preset.value
                                                ? 'border-[#E8563A] ring-2 ring-[#E8563A]/30'
                                                : 'border-border hover:border-[#E8563A]/40',
                                        ]"
                                        @click="selectBackgroundColor(preset.value)"
                                    >
                                        <span
                                            class="size-7 rounded-lg border border-black/10 shadow-inner"
                                            :style="{ backgroundColor: preset.value }"
                                        />
                                    </button>
                                </div>
                                <p class="text-[11px] text-muted-foreground">
                                    Selected: <strong>{{ selectedBackgroundPreset.label }}</strong>
                                    ({{ avatarForm.background_color }})
                                </p>
                            </div>
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
                                        class="h-9 pl-9 text-sm !bg-white"
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
                                    Showing {{ filteredHeyGenAvatars.length }} API-ready avatars (legacy studio looks without API support are hidden). Click Refresh to update the catalog.
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
                                            'flex items-center gap-3 rounded-2xl border-2 p-3 text-left transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring bg-background',
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
                            <Button variant="ghost" @click="backWizard">
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
                                <p
                                    v-if="avatarForm.custom_background_enabled"
                                    class="truncate px-2 pb-1.5 text-center text-[10px] text-muted-foreground"
                                >
                                    BG: {{ selectedBackgroundPreset.label }}
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

                        <!-- <div class="flex gap-2 rounded-xl border border-[#E8563A]/20 bg-[#E8563A]/5 px-3 py-2.5 text-xs text-foreground">
                            <Info class="mt-0.5 size-4 shrink-0 text-[#E8563A]" />
                            <p>
                                HeyGen renders your presenter on a clean background. Tagged products appear as
                                shoppable buy cards in your embed player — not composited into the video.
                            </p>
                        </div> -->

                        <Card>
                            <CardContent class="space-y-3 pt-5">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="space-y-1">
                                        <Label>Shoppable embed overlays</Label>
                                        <p class="text-xs text-muted-foreground">
                                            Product buy cards in the phone preview are always available when you tag products.
                                            After the video renders, add <strong>timed overlays</strong> (coupons, CTAs, extra tags) on the video edit page.
                                        </p>
                                    </div>
                                    <label class="relative mt-0.5 inline-flex shrink-0 cursor-pointer items-center">
                                        <input v-model="enableEmbedOverlays" type="checkbox" class="peer sr-only">
                                        <div class="h-6 w-11 rounded-full bg-muted transition-colors peer-checked:bg-[#E8563A] after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-all peer-checked:after:translate-x-5" />
                                    </label>
                                </div>
                                <p v-if="enableEmbedOverlays && avatarSelectedProducts.length" class="rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                                    {{ avatarSelectedProducts.length }} product{{ avatarSelectedProducts.length > 1 ? 's' : '' }} will appear as buy cards in the embed.
                                    Plan timed coupon or CTA overlays in Edit after publish.
                                </p>
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

                        <Card>
                            <CardContent class="space-y-3 pt-5">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold">Multilingual versions</p>
                                        <p class="text-xs text-muted-foreground">
                                            Translate the script and queue one render per language (auto voice per locale).
                                        </p>
                                    </div>
                                    <label class="relative inline-flex cursor-pointer items-center">
                                        <input
                                            v-model="multilingualMode"
                                            type="checkbox"
                                            class="peer sr-only"
                                        >
                                        <div class="h-6 w-11 rounded-full bg-muted transition-colors peer-checked:bg-[#E8563A] after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-all peer-checked:after:translate-x-5" />
                                    </label>
                                </div>
                                <div
                                    v-if="multilingualMode"
                                    class="flex flex-wrap gap-2"
                                >
                                    <button
                                        v-for="lang in SUPPORTED_LANGUAGES"
                                        :key="lang.code"
                                        type="button"
                                        :class="[
                                            'rounded-full border px-3 py-1 text-xs font-semibold transition-colors',
                                            selectedLanguages.includes(lang.code)
                                                ? 'border-[#E8563A] bg-[#E8563A]/10 text-[#E8563A]'
                                                : 'border-border bg-background text-muted-foreground hover:border-[#E8563A]/40',
                                        ]"
                                        @click="toggleTargetLanguage(lang.code)"
                                    >
                                        {{ lang.label }}
                                    </button>
                                </div>
                                <p
                                    v-if="multilingualMode && selectedLanguages.length > 1"
                                    class="text-xs font-medium text-[#E8563A]"
                                >
                                    Will create {{ selectedLanguages.length }} localized videos.
                                </p>
                                <p
                                    v-if="multilingualMode && selectedLanguages.length > 1 && avatarForm.voice_id"
                                    class="text-xs text-muted-foreground"
                                >
                                    Your selected voice ({{ selectedHeyGenVoice?.name ?? 'presenter' }}) will be used for every language version.
                                </p>
                                <p
                                    v-else-if="multilingualMode && selectedLanguages.length > 1"
                                    class="text-xs text-muted-foreground"
                                >
                                    Pick a voice on the Presenter step — otherwise each language may auto-select a different HeyGen voice.
                                </p>
                            </CardContent>
                        </Card>

                        <!-- Generate button -->
                        <Button
                            class="w-full bg-[#E8563A] text-white shadow-lg shadow-[#E8563A]/30 hover:bg-[#D44A2F]"
                            size="lg"
                            :disabled="aiGenerating || !canGenerateAvatar"
                            @click="generateAvatarVideo"
                        >
                            <Loader2 v-if="aiGenerating" class="mr-2 size-5 animate-spin" />
                            <Sparkles v-else class="mr-2 size-5" />
                            {{
                                aiGenerating
                                    ? 'Queuing your video render…'
                                    : multilingualMode && selectedLanguages.length > 1
                                      ? `Generate ${selectedLanguages.length} language versions`
                                      : 'Generate AI avatar video'
                            }}
                        </Button>

                        <!-- <p class="text-center text-xs text-muted-foreground">
                            Powered by HeyGen · renders in 1–5 min · you'll see them on the Videos page
                        </p> -->

                        <div class="flex justify-start pt-1">
                            <Button variant="ghost" @click="backWizard">
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
                                <template v-if="selectedProducts.length">
                                    <div
                                        v-for="p in selectedProducts.slice(0, 2)"
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
                                        v-if="selectedProducts.length > 2"
                                        class="mt-1.5 text-center text-[9px] text-white/50"
                                    >
                                        +{{ selectedProducts.length - 2 }} more products
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
                        v-if="selectedProducts.length"
                        class="w-full max-w-[260px] space-y-2"
                    >
                        <div class="flex items-center gap-1.5 px-1">
                            <Tag class="size-3.5 text-orange-500" />
                            <p class="text-xs font-semibold">{{ selectedProducts.length }} product{{ selectedProducts.length > 1 ? 's' : '' }} tagged</p>
                        </div>
                        <div
                            v-for="p in selectedProducts"
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
        <DialogContent class="flex max-h-[min(90dvh,calc(100vh-2rem))] flex-col gap-0 overflow-hidden p-0 sm:max-w-[560px]">
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

            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-3">
                <div v-if="filteredProducts.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                    No products found.
                    <Link href="/products" class="mt-1 block text-[#E8563A] underline">Add products first →</Link>
                </div>
                <div v-else class="space-y-2">
                    <p
                        v-if="filteredProducts.length > PRODUCTS_PER_PAGE"
                        class="pb-1 text-center text-xs text-muted-foreground"
                    >
                        Page {{ modalProductPage }} of {{ modalProductTotalPages }} · {{ PRODUCTS_PER_PAGE }} per page
                    </p>
                    <button
                        v-for="product in paginatedModalProducts"
                        :key="product.id"
                        type="button"
                        :class="[
                            'flex w-full items-center gap-3 rounded-2xl border-2 p-3 text-left transition-all',
                            activeProductIds.includes(product.id)
                                ? 'border-[#E8563A] bg-[#E8563A]/5 shadow-sm'
                                : 'border-border hover:border-[#E8563A]/35 hover:bg-muted/30',
                        ]"
                        @click="toggleProductInModal(product.id)"
                    >
                        <div class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border bg-muted">
                            <img
                                v-if="product.image_url"
                                :src="product.image_url"
                                :alt="product.title"
                                class="h-full w-full object-cover"
                            >
                            <ImageOff v-else class="size-5 text-muted-foreground" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ product.title }}</p>
                            <p class="mt-0.5 text-sm font-medium text-[#E8563A]">
                                {{ formatPrice(product.currency, product.sale_price || product.price) }}
                                <span
                                    v-if="product.sale_price"
                                    class="ml-1 text-xs font-normal text-muted-foreground line-through"
                                >
                                    {{ formatPrice(product.currency, product.price) }}
                                </span>
                            </p>
                            <p
                                v-if="product.description"
                                class="mt-0.5 line-clamp-1 text-xs text-muted-foreground"
                            >
                                {{ product.description }}
                            </p>
                        </div>
                        <div
                            :class="[
                                'flex size-7 shrink-0 items-center justify-center rounded-full border-2 transition-all',
                                activeProductIds.includes(product.id)
                                    ? 'border-[#E8563A] bg-[#E8563A] text-white'
                                    : 'border-muted-foreground/30',
                            ]"
                        >
                            <Check v-if="activeProductIds.includes(product.id)" class="size-4" />
                        </div>
                    </button>
                    <div
                        v-if="modalProductTotalPages > 1"
                        class="flex items-center justify-between gap-2 pt-2"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="modalProductPage <= 1"
                            @click="modalProductPage--"
                        >
                            Previous
                        </Button>
                        <span class="text-xs text-muted-foreground">
                            {{ paginatedModalProducts.length }} on this page
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="modalProductPage >= modalProductTotalPages"
                            @click="modalProductPage++"
                        >
                            Next
                        </Button>
                    </div>
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
