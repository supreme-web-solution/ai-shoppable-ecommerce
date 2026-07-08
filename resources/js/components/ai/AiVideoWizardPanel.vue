<script setup lang="ts">
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Clapperboard,
    ImageOff,
    Loader2,
    Package,
    Pause,
    Play,
    Search,
    Sparkles,
    Users,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    formatAiDurationLabel,
    HEYGEN_BACKGROUND_PRESETS,
    SHOPPABLE_VIDEO_DURATION_OPTIONS,
    useAiVideoWizard,
    type AiProductOption,
    type AiVideoGenerationPayload,
} from '@/composables/useAiVideoWizard';

const props = withDefaults(
    defineProps<{
        products?: AiProductOption[];
        productIds?: number[];
        defaultTitle?: string;
        topicHint?: string;
        defaultTopic?: string;
        defaultDurationSeconds?: number;
        durationOptions?: number[];
        attachProductTags?: boolean;
        /** When true, final step creates the live cast + queues video (create modal only). */
        createWithCast?: boolean;
        /** Whether cast title is filled (required before create-with-cast). */
        castTitleReady?: boolean;
        submitting?: boolean;
    }>(),
    {
        products: () => [],
        productIds: () => [],
        defaultTitle: '',
        topicHint: '',
        defaultTopic: 'product showcase',
        defaultDurationSeconds: undefined,
        durationOptions: () => [...SHOPPABLE_VIDEO_DURATION_OPTIONS],
        attachProductTags: false,
        createWithCast: false,
        castTitleReady: true,
        submitting: false,
    },
);

const emit = defineEmits<{
    generated: [{ videoId: number; title: string; durationSeconds: number }];
    'create-with-video': [AiVideoGenerationPayload];
}>();

const productIdsRef = computed(() => props.productIds);
const defaultTitleRef = computed(() => props.defaultTitle);
const topicHintRef = computed(() => props.topicHint);
const durationOptionsRef = computed(() => props.durationOptions);

const wizard = useAiVideoWizard({
    productIds: productIdsRef,
    defaultTitle: defaultTitleRef,
    topicHint: topicHintRef,
    defaultTopic: props.defaultTopic,
    defaultDurationSeconds: props.defaultDurationSeconds,
    durationOptions: durationOptionsRef,
    attachProductTags: props.attachProductTags,
    usageContext: props.createWithCast ? 'live_cast' : 'shoppable',
    onGenerated: (result) => emit('generated', result),
});

const {
    wizardStep,
    errorText,
    scriptGenerationNotice,
    scriptEntryMode,
    aiGenerating,
    heygenLoading,
    heygenError,
    heygenOptions,
    scriptForm,
    avatarForm,
    selectedProductIds,
    presenterTab,
    avatarSearch,
    avatarGenderFilter,
    avatarTypeFilter,
    avatarOwnershipFilter,
    playingVoiceId,
    loadingVoiceId,
    selectedBackgroundPreset,
    avatarTypes,
    filteredHeyGenAvatars,
    selectedHeyGenAvatar,
    selectedHeyGenVoice,
    scriptTargetWords,
    scriptWordCount,
    scriptEstimatedSeconds,
    showScriptEditor,
    canGenerateAvatar,
    toggleProduct,
    toggleVoicePreview,
    selectHeyGenAvatar,
    toggleCustomBackground,
    selectBackgroundColor,
    goWizardStep,
    generateScript,
    useManualScript,
    generateAvatarVideo,
    getGenerationPayload,
    loadHeyGenOptions,
    durationOptions,
} = wizard;

const productSearch = ref('');
const filteredProducts = computed(() => {
    const q = productSearch.value.trim().toLowerCase();

    if (!q) {
        return props.products;
    }

    return props.products.filter(
        (product) =>
            product.title.toLowerCase().includes(q)
            || (product.description?.toLowerCase().includes(q) ?? false),
    );
});

const selectedProducts = computed(() =>
    props.products.filter((product) => selectedProductIds.value.includes(product.id)),
);

const scriptEditorRows = computed(() =>
    scriptForm.value.duration_seconds >= 300 ? 18 : scriptForm.value.duration_seconds >= 120 ? 14 : 10,
);

const stepLabels = ['Script', 'Presenter', 'Generate'] as const;

async function handleFinalSubmit() {
    const payload = getGenerationPayload();

    if (!payload) {
        return;
    }

    if (props.createWithCast) {
        emit('create-with-video', payload);

        return;
    }

    await generateAvatarVideo();
}
</script>

<template>
    <div class="space-y-4 rounded-xl border-2 border-dashed border-[#E8563A]/30 bg-[#E8563A]/5 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-gray-900">Generate with AI</p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Write a script from your context, pick an avatar and voice, then queue a presenter video — same flow as shoppable videos.
                </p>
            </div>
            <div class="inline-flex rounded-xl border bg-muted/40 p-1">
                <button
                    v-for="(label, index) in stepLabels"
                    :key="label"
                    type="button"
                    :class="[
                        'rounded-lg px-3 py-1 text-xs font-medium transition-all',
                        wizardStep === index + 1
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    ]"
                    @click="goWizardStep(index + 1)"
                >
                    {{ index + 1 }}. {{ label }}
                </button>
            </div>
        </div>

        <div
            v-if="errorText"
            class="flex items-center gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
        >
            <XCircle class="size-4 shrink-0" />
            {{ errorText }}
        </div>

        <!-- Step 1: Script -->
        <div v-if="wizardStep === 1" class="space-y-4">
            <div v-if="products.length" class="space-y-2 rounded-xl border bg-background p-3">
                <div class="flex items-center justify-between gap-2">
                    <Label class="text-xs">Products for script context</Label>
                    <span class="text-[11px] text-muted-foreground">{{ selectedProductIds.length }} selected</span>
                </div>
                <div class="relative">
                    <Search class="absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="productSearch" placeholder="Search products…" class="h-8 pl-8 text-xs" />
                </div>
                <div class="max-h-32 space-y-1 overflow-y-auto">
                    <button
                        v-for="product in filteredProducts.slice(0, 20)"
                        :key="product.id"
                        type="button"
                        :class="[
                            'flex w-full items-center gap-2 rounded-lg border px-2 py-1.5 text-left text-xs transition-all',
                            selectedProductIds.includes(product.id)
                                ? 'border-[#E8563A] bg-[#E8563A]/5'
                                : 'border-border hover:border-[#E8563A]/30',
                        ]"
                        @click="toggleProduct(product.id)"
                    >
                        <img
                            v-if="product.image_url"
                            :src="product.image_url"
                            :alt="product.title"
                            class="size-7 rounded object-cover"
                        >
                        <Package v-else class="size-7 text-muted-foreground/40" />
                        <span class="min-w-0 flex-1 truncate font-medium">{{ product.title }}</span>
                        <Check
                            v-if="selectedProductIds.includes(product.id)"
                            class="size-3.5 shrink-0 text-[#E8563A]"
                        />
                    </button>
                </div>
            </div>

            <Card>
                <CardContent class="space-y-4 pt-4">
                    <div class="space-y-1.5">
                        <Label>Topic / angle</Label>
                        <Input v-model="scriptForm.topic" placeholder="e.g. Product launch, training intro, offer reveal…" />
                    </div>

                    <div class="space-y-1.5">
                        <Label>Tone</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="tone in ['engaging', 'luxury', 'urgent', 'friendly']"
                                :key="tone"
                                type="button"
                                :class="[
                                    'rounded-full border px-3 py-1 text-xs font-medium capitalize transition-all',
                                    scriptForm.tone === tone
                                        ? 'border-[#E8563A] bg-[#E8563A] text-white'
                                        : 'border-border text-muted-foreground hover:border-[#E8563A]/40',
                                ]"
                                @click="scriptForm.tone = tone"
                            >
                                {{ tone }}
                            </button>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <Label>Duration</Label>
                            <span class="text-xs text-muted-foreground">Target ~{{ scriptTargetWords }} words</span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="sec in durationOptions"
                                :key="sec"
                                type="button"
                                :class="[
                                    'rounded-full border px-3 py-1 text-xs font-medium transition-all',
                                    scriptForm.duration_seconds === sec
                                        ? 'border-[#E8563A] bg-[#E8563A] text-white'
                                        : 'border-border text-muted-foreground hover:border-[#E8563A]/40',
                                ]"
                                @click="scriptForm.duration_seconds = sec"
                            >
                                {{ formatAiDurationLabel(sec) }}
                            </button>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <Button class="flex-1" size="sm" :disabled="aiGenerating" @click="generateScript">
                            <Loader2 v-if="aiGenerating" class="mr-2 size-4 animate-spin" />
                            <Sparkles v-else class="mr-2 size-4" />
                            {{ aiGenerating ? 'Writing…' : 'Generate with AI' }}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            :variant="scriptEntryMode === 'manual' ? 'default' : 'outline'"
                            :disabled="aiGenerating"
                            @click="useManualScript"
                        >
                            Write manually
                        </Button>
                    </div>

                    <p
                        v-if="scriptGenerationNotice"
                        class="rounded-lg border px-3 py-2 text-xs"
                        :class="
                            scriptGenerationNotice.includes('OPENAI') || scriptGenerationNotice.includes('Template')
                                ? 'border-orange-300 bg-orange-50 text-orange-800'
                                : 'border-emerald-300 bg-emerald-50 text-emerald-800'
                        "
                    >
                        {{ scriptGenerationNotice }}
                    </p>

                    <div v-if="showScriptEditor" class="space-y-1.5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <Label>{{ scriptEntryMode === 'manual' ? 'Your script' : 'Script — edit freely' }}</Label>
                            <span v-if="scriptWordCount > 0" class="text-xs text-muted-foreground">
                                {{ scriptWordCount }} words · ~{{ scriptEstimatedSeconds }}s read
                            </span>
                        </div>
                        <textarea
                            v-model="avatarForm.script"
                            :rows="scriptEntryMode === 'manual' ? scriptEditorRows + 2 : scriptEditorRows"
                            class="w-full rounded-xl border bg-background px-3 py-2 text-sm leading-relaxed"
                            placeholder="Generate with AI or write your own script…"
                        />
                    </div>
                </CardContent>
            </Card>

            <div class="flex justify-end">
                <Button size="sm" :disabled="!avatarForm.script.trim()" @click="goWizardStep(2)">
                    Continue
                    <ChevronRight class="ml-1.5 size-4" />
                </Button>
            </div>
        </div>

        <!-- Step 2: Presenter -->
        <div v-else-if="wizardStep === 2" class="space-y-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold">Choose presenter & voice</p>
                    <p class="text-xs text-muted-foreground">Pick an avatar, then audition voices.</p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="heygenLoading"
                    class="h-7 text-xs"
                    @click="loadHeyGenOptions(true)"
                >
                    <Loader2 v-if="heygenLoading" class="mr-1 size-3 animate-spin" />
                    Refresh
                </Button>
            </div>

            <div
                v-if="heygenError || heygenOptions.message"
                class="rounded-xl border border-dashed bg-muted/30 py-4 text-center text-sm text-muted-foreground"
            >
                <Clapperboard class="mx-auto mb-2 size-5" />
                {{ heygenError || heygenOptions.message }}
            </div>

            <div class="inline-flex rounded-xl border bg-muted/40 p-1">
                <button
                    type="button"
                    :class="[
                        'rounded-lg px-3 py-1 text-xs font-medium transition-all',
                        presenterTab === 'avatars' ? 'bg-background shadow-sm' : 'text-muted-foreground',
                    ]"
                    @click="presenterTab = 'avatars'"
                >
                    Avatars
                </button>
                <button
                    type="button"
                    :class="[
                        'rounded-lg px-3 py-1 text-xs font-medium transition-all',
                        presenterTab === 'voices' ? 'bg-background shadow-sm' : 'text-muted-foreground',
                    ]"
                    @click="presenterTab = 'voices'"
                >
                    Voices
                </button>
            </div>

            <div v-if="avatarForm.avatar_id" class="rounded-xl border bg-background p-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <Label class="text-xs">Custom video background</Label>
                        <p class="text-[11px] text-muted-foreground">Replace the presenter scene with a solid color.</p>
                    </div>
                    <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                        <input
                            :checked="avatarForm.custom_background_enabled"
                            type="checkbox"
                            class="peer sr-only"
                            @change="toggleCustomBackground(($event.target as HTMLInputElement).checked)"
                        >
                        <span class="h-5 w-9 rounded-full bg-muted transition peer-checked:bg-[#E8563A]" />
                        <span class="absolute left-0.5 top-0.5 size-4 rounded-full bg-white shadow transition peer-checked:translate-x-4" />
                    </label>
                </div>
                <div v-if="avatarForm.custom_background_enabled" class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        v-for="preset in HEYGEN_BACKGROUND_PRESETS"
                        :key="preset.value"
                        type="button"
                        :title="preset.label"
                        :class="[
                            'size-7 rounded-lg border-2',
                            avatarForm.background_color === preset.value ? 'border-[#E8563A]' : 'border-border',
                        ]"
                        :style="{ backgroundColor: preset.value }"
                        @click="selectBackgroundColor(preset.value)"
                    />
                </div>
            </div>

            <div v-if="presenterTab === 'avatars'">
                <div class="mb-2 space-y-2 rounded-xl border bg-muted/20 p-2">
                    <div class="relative">
                        <Search class="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                        <Input v-model="avatarSearch" placeholder="Search avatars…" class="h-8 pl-8 text-xs" />
                    </div>
                    <div class="grid grid-cols-3 gap-1.5">
                        <select v-model="avatarGenderFilter" class="h-8 rounded-lg border bg-background px-2 text-[11px]">
                            <option value="all">All genders</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                        <select v-model="avatarTypeFilter" class="h-8 rounded-lg border bg-background px-2 text-[11px]">
                            <option value="all">All types</option>
                            <option v-for="type in avatarTypes" :key="type" :value="type">
                                {{ type.replace(/_/g, ' ') }}
                            </option>
                        </select>
                        <select v-model="avatarOwnershipFilter" class="h-8 rounded-lg border bg-background px-2 text-[11px]">
                            <option value="all">All library</option>
                            <option value="private">My avatars</option>
                            <option value="public">Public</option>
                        </select>
                    </div>
                </div>

                <div v-if="heygenLoading" class="grid grid-cols-3 gap-2">
                    <div v-for="n in 6" :key="n" class="aspect-3/4 animate-pulse rounded-xl bg-muted" />
                </div>
                <div v-else-if="filteredHeyGenAvatars.length" class="max-h-72 overflow-y-auto">
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="avatar in filteredHeyGenAvatars"
                            :key="avatar.id"
                            type="button"
                            :class="[
                                'overflow-hidden rounded-xl border-2 text-left transition-all',
                                avatarForm.avatar_id === avatar.id
                                    ? 'border-[#E8563A] shadow-md'
                                    : 'border-border hover:border-[#E8563A]/40',
                            ]"
                            @click="selectHeyGenAvatar(avatar); presenterTab = 'voices'"
                        >
                            <div class="relative aspect-3/4 bg-muted">
                                <img
                                    v-if="avatar.preview_image_url"
                                    :src="avatar.preview_image_url"
                                    :alt="avatar.name"
                                    class="h-full w-full object-cover"
                                >
                                <div v-else class="flex h-full items-center justify-center">
                                    <ImageOff class="size-5 text-muted-foreground/30" />
                                </div>
                            </div>
                            <p class="truncate px-1.5 py-1 text-[10px] font-semibold">{{ avatar.name }}</p>
                        </button>
                    </div>
                </div>
                <p v-else class="text-center text-xs text-muted-foreground">No avatars match these filters.</p>
            </div>

            <div v-else>
                <div v-if="heygenOptions.voices.length" class="max-h-72 space-y-1.5 overflow-y-auto">
                    <button
                        v-for="voice in heygenOptions.voices"
                        :key="voice.voice_id"
                        type="button"
                        :class="[
                            'flex w-full items-center gap-2 rounded-xl border-2 p-2 text-left text-xs transition-all',
                            avatarForm.voice_id === voice.voice_id
                                ? 'border-[#E8563A] bg-[#E8563A]/5'
                                : 'border-border hover:border-[#E8563A]/40',
                        ]"
                        @click="avatarForm.voice_id = voice.voice_id"
                    >
                        <button
                            v-if="voice.preview_audio_url"
                            type="button"
                            :class="[
                                'flex size-7 shrink-0 items-center justify-center rounded-full border',
                                playingVoiceId === voice.voice_id || loadingVoiceId === voice.voice_id
                                    ? 'border-[#E8563A] bg-[#E8563A] text-white'
                                    : 'border-border text-muted-foreground',
                            ]"
                            @click.stop="toggleVoicePreview(voice)"
                        >
                            <Loader2 v-if="loadingVoiceId === voice.voice_id" class="size-3 animate-spin" />
                            <Pause v-else-if="playingVoiceId === voice.voice_id" class="size-3" />
                            <Play v-else class="ml-0.5 size-3" />
                        </button>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ voice.name }}</p>
                            <p class="text-[10px] capitalize text-muted-foreground">{{ voice.gender }} {{ voice.language }}</p>
                        </div>
                        <Check v-if="avatarForm.voice_id === voice.voice_id" class="size-3.5 text-[#E8563A]" />
                    </button>
                </div>
                <p v-else class="text-xs text-muted-foreground">No voices available.</p>
            </div>

            <div class="flex items-center justify-between">
                <Button variant="ghost" size="sm" @click="goWizardStep(1)">
                    <ChevronLeft class="mr-1 size-4" />
                    Back
                </Button>
                <Button size="sm" :disabled="!avatarForm.avatar_id" @click="goWizardStep(3)">
                    Continue
                    <ChevronRight class="ml-1.5 size-4" />
                </Button>
            </div>
        </div>

        <!-- Step 3: Review & Generate -->
        <div v-else class="space-y-4">
            <div class="grid grid-cols-3 gap-2">
                <div class="rounded-xl border bg-background p-2 text-center">
                    <Package class="mx-auto mb-1 size-4 text-[#E8563A]" />
                    <p class="text-sm font-bold">{{ selectedProducts.length }}</p>
                    <p class="text-[10px] text-muted-foreground">Products</p>
                </div>
                <div class="overflow-hidden rounded-xl border bg-background">
                    <div v-if="selectedHeyGenAvatar?.preview_image_url" class="aspect-3/4 bg-muted">
                        <img
                            :src="selectedHeyGenAvatar.preview_image_url"
                            class="h-full w-full object-cover object-top"
                            :alt="selectedHeyGenAvatar.name"
                        >
                    </div>
                    <div v-else class="flex h-12 items-center justify-center">
                        <Users class="size-4 text-muted-foreground" />
                    </div>
                    <p class="truncate px-1 py-1 text-center text-[10px] font-medium">
                        {{ selectedHeyGenAvatar?.name ?? 'Avatar' }}
                    </p>
                </div>
                <div class="rounded-xl border bg-background p-2 text-center">
                    <Play class="mx-auto mb-1 size-4 text-[#E8563A]" />
                    <p class="truncate text-[10px] font-semibold">{{ selectedHeyGenVoice?.name ?? 'Voice' }}</p>
                </div>
            </div>

            <Card>
                <CardContent class="space-y-3 pt-4">
                    <div class="space-y-1.5">
                        <Label>Video title</Label>
                        <Input v-model="avatarForm.title" placeholder="AI presenter video" />
                    </div>
                    <div class="space-y-1.5">
                        <Label>Script</Label>
                        <textarea
                            v-model="avatarForm.script"
                            :rows="scriptEditorRows"
                            class="w-full rounded-xl border bg-background px-3 py-2 text-sm"
                        />
                    </div>
                </CardContent>
            </Card>

            <div class="flex items-center justify-between gap-3">
                <Button variant="ghost" size="sm" @click="goWizardStep(2)">
                    <ChevronLeft class="mr-1 size-4" />
                    Back
                </Button>
                <Button
                    size="sm"
                    class="bg-[#E8563A] text-white hover:bg-[#D44A2F]"
                    :disabled="aiGenerating || submitting || !canGenerateAvatar || (createWithCast && !castTitleReady)"
                    @click="handleFinalSubmit"
                >
                    <Loader2 v-if="aiGenerating || submitting" class="mr-2 size-4 animate-spin" />
                    <Sparkles v-else class="mr-2 size-4" />
                    {{
                        aiGenerating || submitting
                            ? (createWithCast ? 'Creating cast & queuing video…' : 'Queuing render…')
                            : createWithCast
                              ? 'Generate & create live cast'
                              : 'Generate AI video'
                    }}
                </Button>
            </div>

            <p class="text-center text-[11px] text-muted-foreground">
                <template v-if="createWithCast">
                    Creates the live cast first, then queues your AI presenter video. The cast appears in your list with a rendering status until HeyGen finishes.
                </template>
                <template v-else>
                    Rendering takes several minutes. The video will be linked to this cast when you save.
                </template>
            </p>
            <p
                v-if="createWithCast && !castTitleReady"
                class="text-center text-[11px] font-medium text-amber-700"
            >
                Add a live cast title on the Basics tab before generating.
            </p>
        </div>
    </div>
</template>
