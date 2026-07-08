import { computed, onMounted, onUnmounted, ref, watch, type ComputedRef, type Ref } from 'vue';
import { useAdminApi } from '@/composables/useAdminApi';

export type AiProductOption = {
    id: number;
    title: string;
    image_url?: string | null;
    price?: string | null;
    sale_price?: string | null;
    currency?: string;
    description?: string | null;
};

type AiGenerationItem = {
    id: number;
    type: string;
    status: string;
    provider: string;
    output?: { full_script?: string; hook?: string };
    error_message?: string | null;
    video_id?: number | null;
};

export type HeyGenAvatarOption = {
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

export type HeyGenVoiceOption = {
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

type VideoItem = { id: number; title: string };

export type AiVideoGenerationPayload = {
    avatarForm: {
        title: string;
        description: string;
        script: string;
        language: string;
        avatar_id: string;
        voice_id: string;
        product_ids: number[];
        custom_background_enabled: boolean;
        background_color: string;
    };
    durationSeconds: number;
};

export const HEYGEN_BACKGROUND_PRESETS = [
    { label: 'Warm cream', value: '#f2efea' },
    { label: 'White', value: '#ffffff' },
    { label: 'Soft gray', value: '#f3f4f6' },
    { label: 'Charcoal', value: '#111827' },
    { label: 'Black', value: '#000000' },
    { label: 'Brand coral', value: '#e8563a' },
    { label: 'Navy', value: '#1e3a5f' },
    { label: 'Sage', value: '#d1e7dd' },
] as const;

export function formatAiDurationLabel(seconds: number): string {
    if (seconds >= 60 && seconds % 60 === 0) {
        const minutes = seconds / 60;

        return minutes === 1 ? '1 min' : `${minutes} min`;
    }

    return `${seconds}s`;
}

export const SHOPPABLE_VIDEO_DURATION_OPTIONS = [15, 30, 45, 60] as const;

export const LIVE_CAST_DURATION_OPTIONS = [60, 90, 120, 180, 300, 600] as const;

export function useAiVideoWizard(options: {
    productIds: Ref<number[]> | ComputedRef<number[]>;
    defaultTitle?: Ref<string> | ComputedRef<string>;
    topicHint?: Ref<string> | ComputedRef<string>;
    defaultTopic?: string;
    defaultDurationSeconds?: number;
    durationOptions?: Ref<number[]> | ComputedRef<number[]>;
    attachProductTags?: boolean;
    usageContext?: 'live_cast' | 'shoppable';
    onGenerated?: (result: { videoId: number; title: string; durationSeconds: number }) => void;
}) {
    const { teamId, apiFetch, postJson, ensureTeam } = useAdminApi();

    const wizardStep = ref(1);
    const errorText = ref('');
    const scriptGenerationNotice = ref('');
    const scriptEntryMode = ref<'ai' | 'manual'>('ai');
    const manualScriptPanelOpen = ref(false);
    const aiGenerating = ref(false);
    const heygenLoading = ref(false);
    const heygenError = ref('');
    const heygenOptions = ref<HeyGenOptions>({
        enabled: false,
        avatars: [],
        voices: [],
        cached_at: null,
        message: null,
    });

    const durationOptions = computed(() => {
        const configured = options.durationOptions?.value ?? [...SHOPPABLE_VIDEO_DURATION_OPTIONS];

        return configured.length ? configured : [...SHOPPABLE_VIDEO_DURATION_OPTIONS];
    });

    const initialDuration =
        options.defaultDurationSeconds
        ?? options.durationOptions?.value?.[0]
        ?? SHOPPABLE_VIDEO_DURATION_OPTIONS[0];

    const scriptForm = ref({
        topic: options.defaultTopic ?? 'product showcase',
        tone: 'engaging',
        language: 'en',
        duration_seconds: initialDuration,
    });

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

    const selectedProductIds = ref<number[]>([...options.productIds.value]);
    const presenterTab = ref<'avatars' | 'voices'>('avatars');
    const avatarSearch = ref('');
    const avatarGenderFilter = ref('all');
    const avatarTypeFilter = ref('all');
    const avatarOwnershipFilter = ref('all');
    const enableEmbedOverlays = ref(false);

    const playingVoiceId = ref('');
    const loadingVoiceId = ref('');
    let audioInstance: HTMLAudioElement | null = null;

    watch(
        () => options.productIds.value,
        (ids) => {
            if (ids.length && selectedProductIds.value.length === 0) {
                selectedProductIds.value = [...ids];
            }
        },
        { immediate: true },
    );

    watch(
        selectedProductIds,
        (ids) => {
            avatarForm.value.product_ids = [...ids];
        },
        { immediate: true, deep: true },
    );

    watch(
        () => options.defaultTitle?.value ?? '',
        (title) => {
            if (title && !avatarForm.value.title) {
                avatarForm.value.title = title;
            }
        },
        { immediate: true },
    );

    watch(
        () => options.topicHint?.value ?? '',
        (hint) => {
            if (hint && scriptForm.value.topic === (options.defaultTopic ?? 'product showcase')) {
                scriptForm.value.topic = hint.slice(0, 200);
            }
        },
        { immediate: true },
    );

    const selectedBackgroundPreset = computed(
        () =>
            HEYGEN_BACKGROUND_PRESETS.find((preset) => preset.value === avatarForm.value.background_color)
            ?? HEYGEN_BACKGROUND_PRESETS[0],
    );

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
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return (
                (!query || haystack.includes(query))
                && (avatarGenderFilter.value === 'all' || gender === avatarGenderFilter.value)
                && (avatarTypeFilter.value === 'all' || type === avatarTypeFilter.value)
                && (avatarOwnershipFilter.value === 'all' || ownership === avatarOwnershipFilter.value)
            );
        });
    });

    const selectedHeyGenAvatar = computed(
        () => heygenOptions.value.avatars.find((avatar) => avatar.id === avatarForm.value.avatar_id) ?? null,
    );

    const selectedHeyGenVoice = computed(
        () => heygenOptions.value.voices.find((voice) => voice.voice_id === avatarForm.value.voice_id) ?? null,
    );

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

    const canGenerateAvatar = computed(() => Boolean(avatarForm.value.title && avatarForm.value.script));

    function toggleProduct(productId: number) {
        const idx = selectedProductIds.value.indexOf(productId);

        if (idx === -1) {
            selectedProductIds.value = [...selectedProductIds.value, productId];
        } else {
            selectedProductIds.value = selectedProductIds.value.filter((id) => id !== productId);
        }
    }

    function clearVoicePreview() {
        if (audioInstance) {
            audioInstance.pause();
            audioInstance.onended = null;
            audioInstance.oncanplay = null;
            audioInstance.onplaying = null;
            audioInstance.onerror = null;
            audioInstance = null;
        }

        playingVoiceId.value = '';
        loadingVoiceId.value = '';
    }

    function toggleVoicePreview(voice: HeyGenVoiceOption) {
        if (!voice.preview_audio_url) {
            return;
        }

        if (playingVoiceId.value === voice.voice_id || loadingVoiceId.value === voice.voice_id) {
            clearVoicePreview();

            return;
        }

        clearVoicePreview();

        loadingVoiceId.value = voice.voice_id;
        const audio = new Audio(voice.preview_audio_url);
        audioInstance = audio;

        const markPlaying = () => {
            if (loadingVoiceId.value !== voice.voice_id) {
                return;
            }

            loadingVoiceId.value = '';
            playingVoiceId.value = voice.voice_id;
        };

        audio.oncanplay = markPlaying;
        audio.onplaying = markPlaying;
        audio.onended = () => {
            if (playingVoiceId.value === voice.voice_id) {
                clearVoicePreview();
            }
        };
        audio.onerror = () => {
            if (loadingVoiceId.value === voice.voice_id || playingVoiceId.value === voice.voice_id) {
                clearVoicePreview();
            }
        };

        void audio.play().catch(() => {
            if (loadingVoiceId.value === voice.voice_id) {
                clearVoicePreview();
            }
        });
    }

    function syncVoiceForAvatar(avatar: HeyGenAvatarOption | null) {
        if (
            avatar?.default_voice_id
            && heygenOptions.value.voices.some((voice) => voice.voice_id === avatar.default_voice_id)
        ) {
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

    function goWizardStep(step: number) {
        wizardStep.value = Math.max(1, Math.min(3, step));
    }

    function unwrapVideo(payload: unknown): VideoItem | null {
        if (!payload || typeof payload !== 'object') {
            return null;
        }

        if ('data' in payload) {
            const data = (payload as { data?: unknown }).data;

            if (data && typeof data === 'object' && 'id' in data) {
                return data as VideoItem;
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
        if (!productIds.length || options.attachProductTags === false) {
            return;
        }

        await postJson(`/api/v1/admin/videos/${videoId}/product-tags/sync`, {
            tags: buildProductTags(productIds),
        });
    }

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
                avatarForm.value.title =
                    options.defaultTitle?.value?.trim()
                    || `AI Video — ${scriptForm.value.topic}`;
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

            const payload = await postJson<unknown>('/api/v1/admin/ai/avatar-videos', {
                ...avatarForm.value,
                enable_embed_overlays: enableEmbedOverlays.value,
                language: avatarForm.value.language,
                usage_context: options.usageContext ?? 'shoppable',
            });

            const responseVideo =
                payload && typeof payload === 'object' && 'video' in payload
                    ? unwrapVideo((payload as { video?: unknown }).video)
                    : null;

            if (responseVideo?.id) {
                await attachProducts(responseVideo.id, avatarForm.value.product_ids);
                options.onGenerated?.({
                    videoId: responseVideo.id,
                    title: avatarForm.value.title || responseVideo.title,
                    durationSeconds: scriptForm.value.duration_seconds,
                });

                return {
                    videoId: responseVideo.id,
                    title: avatarForm.value.title || responseVideo.title,
                    durationSeconds: scriptForm.value.duration_seconds,
                };
            }

            throw new Error('Video record was not returned from the server.');
        } catch (err) {
            errorText.value = err instanceof Error ? err.message : 'Avatar video generation failed.';

            return null;
        } finally {
            aiGenerating.value = false;
        }
    }

    function getGenerationPayload(): AiVideoGenerationPayload | null {
        if (!canGenerateAvatar.value) {
            return null;
        }

        return {
            avatarForm: { ...avatarForm.value },
            durationSeconds: scriptForm.value.duration_seconds,
        };
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
        void loadHeyGenOptions();
    });

    onUnmounted(() => {
        clearVoicePreview();
    });

    return {
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
        enableEmbedOverlays,
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
    };
}
