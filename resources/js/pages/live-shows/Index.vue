<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Bot,
    BookOpen,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    Copy,
    Edit3,
    ExternalLink,
    Eye,
    Film,
    ImageOff,
    Layers,
    Link2,
    Loader2,
    Mail,
    MessageSquare,
    Package,
    Plus,
    PlusCircle,
    Radio,
    Search,
    Trash2,
    Upload,
    UserRound,
    Users,
    XCircle,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DailyBroadcastPanel from '@/components/daily/DailyBroadcastPanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { extractUploadToken, useAdminApi } from '@/composables/useAdminApi';
import { isEmbedPlayback, parseExternalVideoUrl } from '@/lib/externalVideoUrl';
import { setPendingVideoUpload } from '@/lib/pendingVideoUpload';

// ── Types ──────────────────────────────────────────────────────────────────

type VideoOption = {
    id: number;
    title: string;
    thumbnail_url?: string | null;
    playback_url?: string | null;
    metadata?: Record<string, unknown> | null;
};

type OfferAppearance = 'pin' | 'in_chat' | 'popup';

type DailyConfig = {
    room_name?: string | null;
    room_url?: string | null;
};

type LiveSourceType = 'ai' | 'upload' | 'url' | 'daily';

function isGoLiveSourceType(sourceType?: string | null): boolean {
    return sourceType === 'daily';
}

type ProductOption = {
    id: number;
    title: string;
    image_url?: string | null;
    price?: string | null;
    sale_price?: string | null;
    currency?: string;
};

type WebinarFeaturedProduct = ProductOption & {
    starts_at_ms?: number;
    ends_at_ms?: number | null;
    appearance?: OfferAppearance | string;
    cta_url?: string | null;
    default_checkout_url?: string | null;
    checkout_url?: string | null;
    pin_order?: number;
};

type FeaturedOffer = {
    product_id: number;
    starts_at_sec: number;
    ends_at_sec: number | null;
    appearance: OfferAppearance;
    cta_url: string;
    pin_order: number;
    default_checkout_url?: string;
};

const OFFER_APPEARANCE_OPTIONS: { value: OfferAppearance; label: string; hint: string }[] = [
    { value: 'pin', label: 'Pin on chat', hint: 'Pinned above the chat while active' },
    { value: 'in_chat', label: 'In chat', hint: 'Appears as a shoppable message in the chat feed' },
    { value: 'popup', label: 'Popup modal', hint: 'Modal overlay on the video for checkout' },
];

type KnowledgeSource = {
    title: string;
    content: string;
};

type WebinarSettings = {
    host_name?: string | null;
    thumbnail_url?: string | null;
    video_url?: string | null;
    source_type?: LiveSourceType | null;
    registration_title?: string | null;
    registration_description?: string | null;
    room_title?: string | null;
    chat_enabled?: boolean;
    ai_assistant_enabled?: boolean;
    knowledge_base_text?: string | null;
    knowledge_sources?: KnowledgeSource[];
    video_duration_seconds?: number | null;
    daily?: DailyConfig | null;
};

type WebinarItem = {
    id: number;
    title: string;
    description?: string | null;
    video_id?: number | null;
    status: 'scheduled' | 'live' | 'ended' | 'cancelled';
    starts_at: string;
    ends_at?: string | null;
    settings?: WebinarSettings;
    daily?: DailyConfig | null;
    host_name?: string | null;
    thumbnail_url?: string | null;
    video_url?: string | null;
    source_type?: LiveSourceType | null;
    registration_title?: string | null;
    registration_description?: string | null;
    room_title?: string | null;
    chat_enabled?: boolean;
    ai_assistant_enabled?: boolean;
    registration_url?: string;
    room_url?: string;
    registrants_count?: number;
    messages_count?: number;
    watched_half_count?: number;
    watched_end_count?: number;
    views_count?: number;
    featured_products?: WebinarFeaturedProduct[];
    video_duration_seconds?: number | null;
    video?: VideoOption | null;
};

type WebinarAttendee = {
    id: number;
    full_name: string;
    email: string;
    registered_at?: string | null;
    last_joined_at?: string | null;
    join_count?: number;
    max_watch_ms?: number;
    reached_half_at?: string | null;
    watched_to_end_at?: string | null;
};

type WebinarFormSettings = {
    host_name: string;
    thumbnail_url: string;
    video_url: string;
    source_type: LiveSourceType;
    registration_title: string;
    registration_description: string;
    room_title: string;
    chat_enabled: boolean;
    ai_assistant_enabled: boolean;
    knowledge_sources: KnowledgeSource[];
    video_duration_seconds: number | null;
    daily: DailyConfig;
};

type WebinarForm = {
    title: string;
    description: string;
    video_id: number | null;
    starts_at: string;
    ends_at: string;
    status: 'scheduled' | 'live' | 'ended' | 'cancelled';
    featured_offers: FeaturedOffer[];
    settings: WebinarFormSettings;
};

type TabItem = {
    id: string;
    label: string;
    icon: unknown;
};

// ── Tab definitions ────────────────────────────────────────────────────────

// CREATE: single "source" tab replaces separate video + streaming tabs.
const CREATE_TABS: TabItem[] = [
    { id: 'basics', label: 'Basics', icon: Layers },
    { id: 'source', label: 'Video', icon: Film },
    { id: 'registration', label: 'Registration', icon: UserRound },
    { id: 'offers', label: 'Offers', icon: Package },
];

const WEBINAR_STATUS_OPTIONS: { value: WebinarForm['status']; label: string }[] = [
    { value: 'live', label: 'Live' },
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'ended', label: 'Ended' },
    { value: 'cancelled', label: 'Cancelled' },
];

// EDIT: "streaming" tab for go-live casts, "video" tab for pre-recorded ones.
const editTabs = computed<TabItem[]>(() => {
    const isLive = isGoLiveSourceType(form.value.settings.source_type);

    return [
        { id: 'basics', label: 'Basics', icon: Layers },
        isLive
            ? { id: 'streaming', label: 'Go Live', icon: Radio }
            : { id: 'video', label: 'Video', icon: Film },
        { id: 'registration', label: 'Registration', icon: UserRound },
        { id: 'attendees', label: 'Attendees', icon: Users },
        { id: 'chat', label: 'Chat & Automation', icon: MessageSquare },
        { id: 'offers', label: 'Offers', icon: Package },
        { id: 'ai', label: 'AI Assistant', icon: Bot },
    ];
});

// ── defineOptions ──────────────────────────────────────────────────────────

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Live Cast', href: '/live-shows' },
        ],
    },
});

// ── Composables ────────────────────────────────────────────────────────────

const { teamId, getList, postJson, putJson, apiFetch, uploadVideoChunks, deleteResource, ensureTeam } = useAdminApi();
const page = usePage();
const dailyLiveEnabled = computed(
    () => Boolean((page.props as { dailyLiveEnabled?: boolean }).dailyLiveEnabled),
);

// ── State ──────────────────────────────────────────────────────────────────

const loading = ref(false);
const saving = ref(false);
const deleting = ref<number | null>(null);
const errorText = ref('');
const modalError = ref('');
const search = ref('');
const productSearch = ref('');

const webinars = ref<WebinarItem[]>([]);
const videos = ref<VideoOption[]>([]);
const products = ref<ProductOption[]>([]);
const ATTENDEES_PER_PAGE = 50;

const attendees = ref<WebinarAttendee[]>([]);
const attendeePage = ref(1);
const attendeeLastPage = ref(1);
const attendeeTotal = ref(0);
const loadingAttendees = ref(false);
const notifyingAttendees = ref(false);
const importingAttendees = ref(false);
const importAttendeesInputRef = ref<HTMLInputElement | null>(null);

const attendeePageRangeLabel = computed(() => {
    if (attendeeTotal.value === 0) {
        return '0 attendees';
    }

    const start = (attendeePage.value - 1) * ATTENDEES_PER_PAGE + 1;
    const end = Math.min(attendeePage.value * ATTENDEES_PER_PAGE, attendeeTotal.value);

    return `Showing ${start.toLocaleString()}–${end.toLocaleString()} of ${attendeeTotal.value.toLocaleString()}`;
});

const hasAttendeeRegistrations = computed(
    () =>
        attendeeTotal.value > 0
        || (editingWebinar.value?.registrants_count ?? 0) > 0,
);

const createModalOpen = ref(false);
const editModalOpen = ref(false);
const editingWebinar = ref<WebinarItem | null>(null);

const activeTab = ref('basics');

const selectedVideoFile = ref<File | null>(null);
const previewVideoUrl = ref<string | null>(null);
const uploadingVideo = ref(false);
const videoUploadError = ref('');
const dailyHostJoined = ref(false);

// Knowledge source state
const addingSource = ref(false);
const expandedSourceIndex = ref<number | null>(null);
const sourceForm = ref<KnowledgeSource>({ title: '', content: '' });

// ── Form ───────────────────────────────────────────────────────────────────

const form = ref<WebinarForm>(newForm());

function newForm(): WebinarForm {
    return {
        title: '',
        description: '',
        video_id: null,
        starts_at: '',
        ends_at: '',
        status: 'scheduled',
        featured_offers: [],
        settings: {
            host_name: '',
            thumbnail_url: '',
            video_url: '',
            source_type: 'upload',
            registration_title: 'Join Webinar',
            registration_description: 'Enter your details to join. Registered attendees get instant access.',
            room_title: 'In-call chat',
            chat_enabled: true,
            ai_assistant_enabled: false,
            knowledge_sources: [],
            video_duration_seconds: null,
            daily: {
                room_name: null,
                room_url: null,
            },
        },
    };
}

// ── Computed ───────────────────────────────────────────────────────────────

const scheduleDatesDisabled = computed(() => form.value.status === 'live');

const videoDurationSeconds = computed(() => {
    const n = Number(form.value.settings.video_duration_seconds ?? 0);

    return Number.isFinite(n) && n > 0 ? Math.floor(n) : null;
});

const filteredWebinars = computed(() => {
    const q = search.value.trim().toLowerCase();

    if (!q) {
return webinars.value;
}

    return webinars.value.filter(
        (item) =>
            item.title.toLowerCase().includes(q) ||
            (item.host_name ?? '').toLowerCase().includes(q) ||
            item.status.toLowerCase().includes(q),
    );
});

const filteredProducts = computed(() => {
    const q = productSearch.value.trim().toLowerCase();

    if (!q) {
return products.value;
}

    return products.value.filter((item) => item.title.toLowerCase().includes(q));
});

const statusCounts = computed(() => ({
    live: webinars.value.filter((item) => item.status === 'live').length,
    scheduled: webinars.value.filter((item) => item.status === 'scheduled').length,
    ended: webinars.value.filter((item) => item.status === 'ended').length,
    total: webinars.value.length,
}));

const currentTabs = computed(() => (editModalOpen.value ? editTabs.value : CREATE_TABS));

const activeTabIndex = computed(() =>
    currentTabs.value.findIndex((t) => t.id === activeTab.value),
);

const canGoNext = computed(() => activeTabIndex.value < currentTabs.value.length - 1);
const canGoPrev = computed(() => activeTabIndex.value > 0);
const canAddSource = computed(() => form.value.settings.knowledge_sources.length < 3);

// ── Helpers ────────────────────────────────────────────────────────────────

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'live') {
return 'destructive';
}

    if (status === 'scheduled') {
return 'default';
}

    if (status === 'ended') {
return 'secondary';
}

    return 'outline';
}

function statusLabel(status: string): string {
    const map: Record<string, string> = {
        scheduled: 'Scheduled',
        live: 'Live',
        ended: 'Ended',
        cancelled: 'Cancelled',
    };

    return map[status] ?? status;
}

function formatDate(value?: string | null): string {
    if (!value) {
return '—';
}

    const d = new Date(value);

    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

function normalizeDateTimeLocal(value?: string | null): string {
    if (!value) {
return '';
}

    const d = new Date(value);

    if (Number.isNaN(d.getTime())) {
return '';
}

    const local = new Date(d.getTime() - d.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 16);
}

function nowDateTimeLocal(): string {
    return normalizeDateTimeLocal(new Date().toISOString());
}

watch(
    () => form.value.status,
    (status) => {
        if (status !== 'live') {
            return;
        }

        if (!form.value.starts_at) {
            form.value.starts_at = nowDateTimeLocal();
        }

        form.value.ends_at = '';
    },
);

function webinarThumbnail(item: WebinarItem): string | null {
    return item.thumbnail_url ?? item.settings?.thumbnail_url ?? item.video?.thumbnail_url ?? null;
}

function selectedThumbnailUrl(): string {
    if (form.value.settings.thumbnail_url.trim()) {
return form.value.settings.thumbnail_url.trim();
}

    return videos.value.find((v) => v.id === form.value.video_id)?.thumbnail_url ?? '';
}

function selectedVideoUrl(): string {
    if (previewVideoUrl.value) {
return previewVideoUrl.value;
}

    if (form.value.settings.video_url.trim()) {
return form.value.settings.video_url.trim();
}

    return videos.value.find((v) => v.id === form.value.video_id)?.playback_url ?? '';
}

const selectedPlayback = computed(() => parseExternalVideoUrl(selectedVideoUrl() || null));

const isDailyCast = computed(() => form.value.settings.source_type === 'daily');
const dailySettings = computed<DailyConfig>(() => form.value.settings.daily ?? {});
const hasDailyRoom = computed(() => Boolean((dailySettings.value.room_url ?? '').trim()));

function mergeDailyConfig(
    ...sources: Array<DailyConfig | null | undefined>
): DailyConfig {
    return sources.reduce<DailyConfig>(
        (merged, source) => ({ ...merged, ...(source ?? {}) }),
        {},
    );
}

function chatsPageUrl(webinarId?: number | null): string {
    if (!webinarId) {
return '/live-shows/chats';
}

    return `/live-shows/chats?webinar=${webinarId}`;
}

function registerUrl(item: WebinarItem): string {
    return item.registration_url ?? `${window.location.origin}/webinars/${item.id}/register`;
}

function joinUrl(item: WebinarItem): string {
    return item.room_url ?? `${window.location.origin}/webinars/${item.id}/room`;
}

const copiedLinkHint = ref<string | null>(null);
let copiedLinkTimeout: number | null = null;

async function copyWebinarLink(label: string, url: string) {
    await copyToClipboard(url);
    copiedLinkHint.value = label;

    if (copiedLinkTimeout !== null) {
        window.clearTimeout(copiedLinkTimeout);
    }

    copiedLinkTimeout = window.setTimeout(() => {
        copiedLinkHint.value = null;
        copiedLinkTimeout = null;
    }, 2000);
}

function onVideoFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
return;
}

    selectedVideoFile.value = file;
    form.value.settings.source_type = 'upload';

    if (previewVideoUrl.value?.startsWith('blob:')) {
        URL.revokeObjectURL(previewVideoUrl.value);
    }

    previewVideoUrl.value = URL.createObjectURL(file);
    videoUploadError.value = '';
}

function clearSelectedVideoFile() {
    selectedVideoFile.value = null;

    if (previewVideoUrl.value?.startsWith('blob:')) {
        URL.revokeObjectURL(previewVideoUrl.value);
    }

    previewVideoUrl.value = null;
    videoUploadError.value = '';
}

function unwrapVideo(payload: unknown): VideoOption | null {
    if (!payload || typeof payload !== 'object') {
return null;
}

    if ('data' in payload) {
        const data = (payload as { data?: unknown }).data;

        if (data && typeof data === 'object' && 'id' in data) {
            return data as VideoOption;
        }
    }

    if ('id' in payload) {
return payload as VideoOption;
}

    return null;
}

async function uploadWebinarVideo() {
    if (!selectedVideoFile.value) {
        videoUploadError.value = 'Choose a video file first.';

        return;
    }

    uploadingVideo.value = true;
    videoUploadError.value = '';

    try {
        await ensureTeam();
        const title =
            form.value.title.trim() ||
            selectedVideoFile.value.name.replace(/\.[^.]+$/, '');
        const payload = await postJson<unknown>('/api/v1/admin/videos', {
            title,
            description: form.value.description.trim() || null,
            source: 'uploaded',
            visibility: 'public',
            awaiting_upload: true,
        });
        const created = unwrapVideo(payload);

        if (!created?.id) {
            throw new Error('Video record could not be created.');
        }

        const uploadToken = extractUploadToken(created.metadata);

        if (!uploadToken) {
            throw new Error('Upload token missing for background video upload.');
        }

        setPendingVideoUpload(created.id, selectedVideoFile.value);

        const videoPayload = await getList<VideoOption>('/api/v1/admin/videos');
        videos.value = videoPayload.data ?? [];
        form.value.video_id = created.id;
        form.value.settings.source_type = 'upload';

        void uploadVideoChunks(created.id, selectedVideoFile.value, uploadToken).catch((error: unknown) => {
            videoUploadError.value = error instanceof Error ? error.message : 'Background video upload failed.';
        });

        selectedVideoFile.value = null;
    } catch (error) {
        videoUploadError.value = error instanceof Error ? error.message : 'Video upload failed.';
    } finally {
        uploadingVideo.value = false;
    }
}

function isProductSelected(productId: number): boolean {
    return form.value.featured_offers.some((offer) => offer.product_id === productId);
}

function offerForProduct(productId: number): FeaturedOffer | undefined {
    return form.value.featured_offers.find((offer) => offer.product_id === productId);
}

function toggleProduct(productId: number) {
    const idx = form.value.featured_offers.findIndex((offer) => offer.product_id === productId);

    if (idx === -1) {
        form.value.featured_offers.push({
            product_id: productId,
            starts_at_sec: 0,
            ends_at_sec: null,
            appearance: 'popup',
            cta_url: '',
            pin_order: form.value.featured_offers.length,
        });
    } else {
        form.value.featured_offers.splice(idx, 1);
    }
}

function updateOffer(
    productId: number,
    patch: Partial<Pick<FeaturedOffer, 'starts_at_sec' | 'ends_at_sec' | 'appearance' | 'cta_url'>>,
) {
    const offer = offerForProduct(productId);

    if (!offer) {
        return;
    }

    Object.assign(offer, patch);
}

function offerCheckoutHint(productId: number): string {
    const offer = offerForProduct(productId);

    if (!offer) {
        return '';
    }

    if (offer.cta_url.trim()) {
        return offer.cta_url.trim();
    }

    return offer.default_checkout_url || 'Uses your integration checkout link when attendees click Shop';
}

function mapFeaturedProductsToOffers(products: WebinarFeaturedProduct[]): FeaturedOffer[] {
    return products.map((product, index) => ({
        product_id: product.id,
        starts_at_sec: Math.max(0, Math.round((product.starts_at_ms ?? 0) / 1000)),
        ends_at_sec:
            product.ends_at_ms != null && product.ends_at_ms > 0
                ? Math.round(product.ends_at_ms / 1000)
                : null,
        appearance:
            product.appearance === 'pin' ||
            product.appearance === 'in_chat' ||
            product.appearance === 'popup'
                ? product.appearance
                : 'popup',
        cta_url: product.cta_url ?? '',
        pin_order: product.pin_order ?? index,
        default_checkout_url: product.default_checkout_url ?? undefined,
    }));
}

function validateForm(): string | null {
    if (!form.value.title.trim()) {
return 'Webinar title is required.';
}

    if (form.value.status !== 'live' && !form.value.starts_at) {
        return 'Start date is required.';
    }

    if (form.value.ends_at && form.value.starts_at) {
        const s = new Date(form.value.starts_at);
        const e = new Date(form.value.ends_at);

        if (!Number.isNaN(s.getTime()) && !Number.isNaN(e.getTime()) && e < s) {
return 'End date must be after start date.';
}
    }

    const duration = videoDurationSeconds.value;

    for (const offer of form.value.featured_offers) {
        if (offer.starts_at_sec < 0) {
            return 'Offer show times cannot be negative.';
        }

        if (duration !== null && offer.starts_at_sec > duration) {
            return 'An offer is scheduled after the video duration ends.';
        }

        if (
            offer.ends_at_sec !== null &&
            offer.ends_at_sec >= 0 &&
            offer.ends_at_sec < offer.starts_at_sec
        ) {
            return 'Offer hide time must be after the show time.';
        }

        if (duration !== null && offer.ends_at_sec !== null && offer.ends_at_sec > duration) {
            return 'An offer hide time is after the video duration ends.';
        }
    }

    return null;
}

function buildPayload(forCreate = false) {
    return {
        title: form.value.title.trim(),
        description: form.value.description.trim() || null,
        video_id: form.value.video_id,
        starts_at:
            form.value.status === 'live'
                ? form.value.starts_at || nowDateTimeLocal()
                : form.value.starts_at,
        ends_at: form.value.status === 'live' ? null : form.value.ends_at || null,
        status: form.value.status,
        is_premiere: false,
        featured_products: form.value.featured_offers.map((offer, index) => ({
            product_id: offer.product_id,
            starts_at_ms: Math.max(0, Math.round(offer.starts_at_sec * 1000)),
            ends_at_ms:
                offer.ends_at_sec !== null && offer.ends_at_sec >= 0
                    ? Math.round(offer.ends_at_sec * 1000)
                    : null,
            appearance: offer.appearance,
            cta_url: offer.cta_url.trim() || null,
            pin_order: index,
        })),
        settings: {
            host_name: form.value.settings.host_name.trim() || null,
            thumbnail_url: selectedThumbnailUrl() || null,
            video_url: selectedVideoUrl() || null,
            source_type: form.value.settings.source_type,
            registration_title: form.value.settings.registration_title.trim() || null,
            registration_description: form.value.settings.registration_description.trim() || null,
            room_title: form.value.settings.room_title.trim() || null,
            chat_enabled: forCreate ? true : form.value.settings.chat_enabled,
            ai_assistant_enabled: forCreate ? false : form.value.settings.ai_assistant_enabled,
            knowledge_sources: forCreate ? [] : form.value.settings.knowledge_sources,
            video_duration_seconds: videoDurationSeconds.value,
            daily: form.value.settings.daily,
        },
    };
}

// ── Tab navigation ─────────────────────────────────────────────────────────

function goToTab(tabId: string) {
    activeTab.value = tabId;
    addingSource.value = false;
    expandedSourceIndex.value = null;
}

function nextTab() {
    if (canGoNext.value) {
goToTab(currentTabs.value[activeTabIndex.value + 1].id);
}
}

function prevTab() {
    if (canGoPrev.value) {
goToTab(currentTabs.value[activeTabIndex.value - 1].id);
}
}

// ── Knowledge base sources ─────────────────────────────────────────────────

function addKnowledgeSource() {
    if (!canAddSource.value) {
return;
}

    if (!sourceForm.value.title.trim() || !sourceForm.value.content.trim()) {
return;
}

    form.value.settings.knowledge_sources.push({
        title: sourceForm.value.title.trim(),
        content: sourceForm.value.content.trim(),
    });
    sourceForm.value = { title: '', content: '' };
    addingSource.value = false;
}

function removeKnowledgeSource(index: number) {
    form.value.settings.knowledge_sources.splice(index, 1);

    if (expandedSourceIndex.value === index) {
expandedSourceIndex.value = null;
}
}

function toggleSourceExpanded(index: number) {
    expandedSourceIndex.value = expandedSourceIndex.value === index ? null : index;
}

function cancelAddingSource() {
    addingSource.value = false;
    sourceForm.value = { title: '', content: '' };
}

// ── Data loading ───────────────────────────────────────────────────────────

async function loadData() {
    loading.value = true;
    errorText.value = '';

    try {
        await ensureTeam();
        const [wPayload, vPayload, pPayload] = await Promise.all([
            getList<WebinarItem>('/api/v1/admin/live-shows'),
            getList<VideoOption>('/api/v1/admin/videos'),
            getList<ProductOption>('/api/v1/admin/products'),
        ]);
        webinars.value = wPayload.data ?? [];
        videos.value = vPayload.data ?? [];
        products.value = pPayload.data ?? [];
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not load webinars.';
    } finally {
        loading.value = false;
    }
}

async function refreshEditingWebinarStats(webinarId: number) {
    try {
        const payload = await apiFetch<{ data: WebinarItem }>(
            `/api/v1/admin/live-shows/${webinarId}?team_id=${teamId.value}`,
        );

        if (editingWebinar.value?.id === webinarId) {
            editingWebinar.value = {
                ...editingWebinar.value,
                ...payload.data,
            };
        }

        const index = webinars.value.findIndex((item) => item.id === webinarId);

        if (index >= 0) {
            webinars.value[index] = {
                ...webinars.value[index],
                ...payload.data,
            };
        }
    } catch {
        // Keep existing stats if refresh fails.
    }
}

function resetAttendeePagination() {
    attendeePage.value = 1;
    attendeeLastPage.value = 1;
    attendeeTotal.value = 0;
    attendees.value = [];
}

async function loadAttendees(webinarId: number, page = attendeePage.value) {
    loadingAttendees.value = true;

    try {
        const payload = await apiFetch<{
            data: WebinarAttendee[];
            current_page: number;
            last_page: number;
            total: number;
        }>(
            `/api/v1/admin/live-shows/${webinarId}/attendees?per_page=${ATTENDEES_PER_PAGE}&page=${page}`,
        );

        attendees.value = payload.data ?? [];
        attendeePage.value = payload.current_page ?? page;
        attendeeLastPage.value = Math.max(1, payload.last_page ?? 1);
        attendeeTotal.value = payload.total ?? attendees.value.length;
    } catch (error) {
        modalError.value = error instanceof Error ? error.message : 'Could not load attendees.';
    } finally {
        loadingAttendees.value = false;
    }
}

function goToAttendeePage(page: number) {
    if (!editingWebinar.value || loadingAttendees.value) {
        return;
    }

    const nextPage = Math.min(Math.max(1, page), attendeeLastPage.value);

    if (nextPage === attendeePage.value && attendees.value.length > 0) {
        return;
    }

    void loadAttendees(editingWebinar.value.id, nextPage);
}

async function notifyAllAttendees() {
    if (!editingWebinar.value || notifyingAttendees.value) {
        return;
    }

    notifyingAttendees.value = true;
    modalError.value = '';

    try {
        const payload = await postJson<{
            data: { attendees: number; email_batches_queued: number };
            message?: string;
        }>(`/api/v1/admin/live-shows/${editingWebinar.value.id}/attendees/notify`, {});

        toast.success(
            payload.message
                ?? `Queued ${payload.data.email_batches_queued} email batch(es) for ${payload.data.attendees} attendee(s).`,
        );
    } catch (error) {
        modalError.value = error instanceof Error ? error.message : 'Could not queue attendee emails.';
    } finally {
        notifyingAttendees.value = false;
    }
}

function openAttendeeImportPicker() {
    importAttendeesInputRef.value?.click();
}

async function onAttendeeImportSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    input.value = '';

    if (!file || !editingWebinar.value || importingAttendees.value) {
        return;
    }

    importingAttendees.value = true;
    modalError.value = '';

    try {
        const formData = new FormData();
        formData.append('file', file);

        const payload = await apiFetch<{
            data: {
                imported: number;
                updated: number;
                attendees: number;
                email_batches_queued: number;
            };
            message?: string;
        }>(`/api/v1/admin/live-shows/${editingWebinar.value.id}/attendees/import`, {
            method: 'POST',
            body: formData,
        });

        await Promise.all([
            loadAttendees(editingWebinar.value.id, 1),
            refreshEditingWebinarStats(editingWebinar.value.id),
        ]);

        toast.success(
            payload.message
                ?? `Imported ${payload.data.imported} new attendee(s), updated ${payload.data.updated}. ${payload.data.email_batches_queued} email batch(es) queued.`,
        );
    } catch (error) {
        modalError.value = error instanceof Error ? error.message : 'Could not import attendees.';
    } finally {
        importingAttendees.value = false;
    }
}

// ── CRUD ───────────────────────────────────────────────────────────────────

function clearSearch() {
    search.value = '';
}

// ── Edit modal close guard (prevents accidental close while broadcasting) ──

function handleEditModalClose(open: boolean) {
    if (!open && dailyHostJoined.value) {
        if (!window.confirm('You are currently live in the host room. Close this panel anyway? Use Leave in the room to end your broadcast.')) {
            return;
        }
    }

    editModalOpen.value = open;
}

// ── Fullscreen ESC handler ─────────────────────────────────────────────────

function handleKeyDown(event: KeyboardEvent) {
    if (event.key === 'Escape' && document.fullscreenElement) {
        void document.exitFullscreen();
        event.stopPropagation();
    }
}

function openCreateModal() {
    form.value = newForm();
    modalError.value = '';
    productSearch.value = '';
    addingSource.value = false;
    sourceForm.value = { title: '', content: '' };
    expandedSourceIndex.value = null;
    clearSelectedVideoFile();
    activeTab.value = 'basics';
    createModalOpen.value = true;
}

async function createWebinar() {
    modalError.value = '';
    const err = validateForm();

    if (err) {
        modalError.value = err;

        return;
    }

    saving.value = true;
    const isGoLive = form.value.settings.source_type === 'daily';

    try {
        const result = await postJson<{ data: WebinarItem }>('/api/v1/admin/live-shows', buildPayload(true));
        createModalOpen.value = false;
        await loadData();

        if (isGoLive && result?.data?.id) {
            const created = webinars.value.find((item) => item.id === result.data.id) ?? result.data;
            await openEditModal(created);
            activeTab.value = 'streaming';
            toast.success('Live cast created. Click Join as host on the Go Live tab to start broadcasting.');
        }
    } catch (error) {
        modalError.value = error instanceof Error ? error.message : 'Could not create live cast.';
    } finally {
        saving.value = false;
    }
}

async function openEditModal(item: WebinarItem) {
    editingWebinar.value = item;
    const s = item.settings ?? {};
    const dailyConfig = mergeDailyConfig(item.daily, s.daily);
    form.value = {
        title: item.title ?? '',
        description: item.description ?? '',
        video_id: item.video_id ?? null,
        starts_at: normalizeDateTimeLocal(item.starts_at),
        ends_at: normalizeDateTimeLocal(item.ends_at),
        status: item.status,
        featured_offers: mapFeaturedProductsToOffers(item.featured_products ?? []),
        settings: {
            host_name: item.host_name ?? s.host_name ?? '',
            thumbnail_url: item.thumbnail_url ?? s.thumbnail_url ?? '',
            video_url: item.video_url ?? s.video_url ?? '',
            source_type: item.source_type ?? s.source_type ?? 'upload',
            registration_title: item.registration_title ?? s.registration_title ?? 'Join Live Cast',
            registration_description:
                item.registration_description ??
                s.registration_description ??
                'Enter your details to join. Registered attendees get instant access.',
            room_title: item.room_title ?? s.room_title ?? 'In-call chat',
            chat_enabled: item.chat_enabled ?? s.chat_enabled ?? true,
            ai_assistant_enabled: item.ai_assistant_enabled ?? s.ai_assistant_enabled ?? false,
            knowledge_sources: Array.isArray(s.knowledge_sources) ? [...s.knowledge_sources] : [],
            video_duration_seconds:
                item.video_duration_seconds ?? s.video_duration_seconds ?? null,
            daily: {
                room_name: dailyConfig.room_name ?? null,
                room_url: dailyConfig.room_url ?? null,
            },
        },
    };
    modalError.value = '';
    productSearch.value = '';
    addingSource.value = false;
    sourceForm.value = { title: '', content: '' };
    expandedSourceIndex.value = null;
    dailyHostJoined.value = false;
    clearSelectedVideoFile();
    // Default go-live casts to the streaming tab so keys are immediately visible.
    activeTab.value = isGoLiveSourceType(item.source_type ?? item.settings?.source_type)
        ? 'streaming'
        : 'basics';
    resetAttendeePagination();
    editModalOpen.value = true;
    await Promise.all([loadAttendees(item.id, 1), refreshEditingWebinarStats(item.id)]);
}

async function saveWebinar() {
    if (!editingWebinar.value) {
return;
}

    modalError.value = '';
    const err = validateForm();

    if (err) {
        modalError.value = err;

        return;
    }

    saving.value = true;

    try {
        await putJson(`/api/v1/admin/live-shows/${editingWebinar.value.id}`, buildPayload());
        editModalOpen.value = false;
        await loadData();
    } catch (error) {
        modalError.value = error instanceof Error ? error.message : 'Could not update live cast.';
    } finally {
        saving.value = false;
    }
}

async function removeWebinar(item: WebinarItem) {
    if (!window.confirm(`Delete live cast "${item.title}"?`)) {
return;
}

    deleting.value = item.id;

    try {
        await deleteResource(`/api/v1/admin/live-shows/${item.id}`);
        await loadData();
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not delete live cast.';
    } finally {
        deleting.value = null;
    }
}

// ── Clipboard ──────────────────────────────────────────────────────────────

async function copyToClipboard(text?: string | null) {
    if (!text) {
return;
}

    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const el = document.createElement('input');
        el.value = text;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
}

onMounted(() => {
    void loadData();
    window.addEventListener('keydown', handleKeyDown, { capture: true });
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeyDown, { capture: true });

    if (previewVideoUrl.value?.startsWith('blob:')) {
        URL.revokeObjectURL(previewVideoUrl.value);
    }

    if (copiedLinkTimeout !== null) {
        window.clearTimeout(copiedLinkTimeout);
    }
});
</script>

<template>
    <Head title="Live Cast" />

    <!-- ═══════════════════════════════════════ Main list page ══════════════════════════════════════ -->
    <div class="live-root flex h-full flex-1 flex-col gap-5 p-4 md:p-5">

        <!-- Header -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <div class="page-icon flex size-10 shrink-0 items-center justify-center rounded-xl">
                    <Radio class="size-5 text-white" />
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-[#E8563A]">Live Commerce</p>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900">Live Cast</h1>
                    <p class="mt-0.5 text-sm text-gray-500">
                    Manage live and prerecorded shows, registration flows, room chat, and offer assignments.
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" class="ghost-btn" :disabled="loading" @click="loadData">
                    {{ loading ? 'Refreshing...' : 'Refresh' }}
                </Button>
                <Button size="sm" class="cta-btn" @click="openCreateModal">
                    <PlusCircle class="mr-1.5 size-4" />
                    New Live Cast
                </Button>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="stat-card rounded-2xl p-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Scheduled</p>
                    <div class="stat-icon flex size-9 items-center justify-center rounded-xl">
                        <Film class="size-4 text-[#E8563A]" />
                    </div>
                </div>
                <p class="mt-1 text-3xl font-black text-gray-900">{{ statusCounts.scheduled }}</p>
            </div>
            <div class="stat-card rounded-2xl p-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Live</p>
                    <div class="stat-icon flex size-9 items-center justify-center rounded-xl">
                        <Users class="size-4 text-[#E8563A]" />
                    </div>
                </div>
                <p class="mt-1 text-3xl font-black text-[#E8563A]">{{ statusCounts.live }}</p>
            </div>
            <div class="stat-card rounded-2xl p-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Ended</p>
                    <div class="stat-icon flex size-9 items-center justify-center rounded-xl">
                        <MessageSquare class="size-4 text-[#E8563A]" />
                    </div>
                </div>
                <p class="mt-1 text-3xl font-black text-gray-900">{{ statusCounts.ended }}</p>
            </div>
        </div>

        <!-- Error -->
        <div
            v-if="errorText"
            class="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            <XCircle class="size-4 shrink-0" />
            {{ errorText }}
        </div>

        <div
            v-if="copiedLinkHint"
            class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700"
        >
            {{ copiedLinkHint === 'register' ? 'Register link copied to clipboard.' : 'Join link copied to clipboard.' }}
        </div>

        <!-- Table -->
        <div class="table-card rounded-2xl">
            <div class="flex flex-col gap-3 border-b border-[#F0EDE8] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="font-bold text-gray-900">All Live Casts</p>
                    <p class="text-xs text-gray-500">
                        {{ filteredWebinars.length }} shown / {{ webinars.length }} total
                    </p>
                </div>
                <div class="relative w-full max-w-xs">
                    <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <Input v-model="search" placeholder="Search live casts..." class="search-input pl-9" />
                </div>
            </div>

            <div v-if="loading" class="space-y-2 p-4">
                <Skeleton v-for="n in 6" :key="n" class="h-14 rounded-lg" />
            </div>

            <!-- Empty: no search results -->
            <div
                v-else-if="filteredWebinars.length === 0 && search.trim()"
                class="flex flex-col items-center justify-center gap-5 px-6 py-16 text-center"
            >
                <div class="flex size-16 items-center justify-center rounded-2xl border border-[#F0EDE8] bg-[#FAF8F5]">
                    <Search class="size-8 text-gray-400" />
                </div>
                <div class="max-w-sm">
                    <p class="text-base font-bold text-gray-900">No live casts match your search</p>
                    <p class="mt-1.5 text-sm text-gray-500">
                        Nothing found for
                        <span class="font-medium text-gray-700">“{{ search.trim() }}”</span>.
                        Try a different title, host name, or status.
                    </p>
                </div>
                <Button variant="outline" size="sm" class="ghost-btn" @click="clearSearch">
                    Clear search
                </Button>
            </div>

            <!-- Empty: no webinars yet -->
            <div
                v-else-if="filteredWebinars.length === 0"
                class="flex flex-col items-center justify-center gap-5 px-6 py-16 text-center"
            >
                <div class="page-icon flex size-16 items-center justify-center rounded-2xl shadow-lg">
                    <Film class="size-8 text-white" />
                </div>
                <div class="max-w-md">
                    <p class="text-base font-bold text-gray-900">No live casts yet</p>
                    <p class="mt-1.5 text-sm text-gray-500">
                        Create your first live cast funnel — registration page, viewer room, offers, and optional AI chat after publish.
                    </p>
                </div>
                <Button size="sm" class="cta-btn" @click="openCreateModal">
                    <PlusCircle class="mr-1.5 size-4" />
                    Create your first live cast
                </Button>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-[#FAF8F5] text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Live cast</th>
                            <th class="px-4 py-3 text-left">Host</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Registrants</th>
                            <th class="px-4 py-3 text-left">Views</th>
                            <th class="px-4 py-3 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in filteredWebinars"
                            :key="item.id"
                            class="border-t border-[#F0EDE8] transition-colors hover:bg-[#FAF8F5]"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-gray-100 bg-gray-100">
                                        <img
                                            v-if="webinarThumbnail(item)"
                                            :src="webinarThumbnail(item) ?? ''"
                                            :alt="item.title"
                                            class="h-full w-full object-cover"
                                        >
                                        <ImageOff v-else class="size-4 text-gray-400" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-bold text-gray-900">{{ item.title }}</p>
                                        <p class="truncate text-xs text-gray-500">
                                            {{ formatDate(item.starts_at) }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-gray-500">
                                {{ item.host_name || item.settings?.host_name || '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="statusVariant(item.status)" class="status-badge">
                                    {{ statusLabel(item.status) }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3 font-medium">{{ item.registrants_count ?? 0 }}</td>
                            <td class="px-4 py-3 font-medium">{{ item.views_count ?? 0 }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="action-icon"
                                        title="Edit live cast"
                                        @click="openEditModal(item)"
                                    >
                                        <Edit3 class="size-4" />
                                    </Button>

                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="action-icon"
                                                title="Live cast links"
                                            >
                                                <Link2 class="size-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" class="w-56">
                                            <DropdownMenuLabel>Live cast links</DropdownMenuLabel>
                                            <DropdownMenuItem
                                                class="cursor-pointer gap-2"
                                                @click="copyWebinarLink('register', registerUrl(item))"
                                            >
                                                <Copy class="size-4 shrink-0" />
                                                Copy register link
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="cursor-pointer gap-2"
                                                @click="copyWebinarLink('join', joinUrl(item))"
                                            >
                                                <Copy class="size-4 shrink-0" />
                                                Copy join link
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem as-child>
                                                <a
                                                    :href="registerUrl(item)"
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    class="flex cursor-pointer items-center gap-2"
                                                >
                                                    <ExternalLink class="size-4 shrink-0" />
                                                    Open registration page
                                                </a>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem as-child>
                                                <a
                                                    :href="joinUrl(item)"
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    class="flex cursor-pointer items-center gap-2"
                                                >
                                                    <ExternalLink class="size-4 shrink-0" />
                                                    Open live room
                                                </a>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>

                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="text-red-400 hover:bg-red-50 hover:text-red-600"
                                        title="Delete live cast"
                                        :disabled="deleting === item.id"
                                        @click="removeWebinar(item)"
                                    >
                                        <Loader2 v-if="deleting === item.id" class="size-4 animate-spin" />
                                        <Trash2 v-else class="size-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════ Create Dialog ══════════════════════════════════════ -->
    <Dialog v-model:open="createModalOpen">
        <DialogContent class="flex max-h-[min(92dvh,calc(100vh-2rem))] min-h-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-4xl">
            <!-- Header -->
            <DialogHeader class="shrink-0 border-b px-6 py-4">
            <DialogTitle>Create Live Cast</DialogTitle>
                <DialogDescription>
                    Set up host, video, registration page, and offers. Enable AI assistant, chat, and knowledge sources after the live cast is created.
                </DialogDescription>
            </DialogHeader>

            <!-- Tab nav -->
            <div class="shrink-0 overflow-x-auto border-b border-[#F0EDE8] bg-[#FAF8F5]">
                <div class="flex min-w-max">
                    <button
                        v-for="tab in CREATE_TABS"
                        :key="tab.id"
                        type="button"
                        :class="[
                            'flex items-center gap-2 border-b-2 px-5 py-3 text-sm font-medium transition-colors',
                            activeTab === tab.id
                                ? 'border-[#E8563A] text-[#E8563A]'
                                : 'border-transparent text-gray-500 hover:text-gray-900',
                        ]"
                        @click="goToTab(tab.id)"
                    >
                        <component :is="tab.icon" class="size-3.5" />
                        {{ tab.label }}
                    </button>
                </div>
            </div>

            <!-- Step indicator -->
            <div class="flex shrink-0 items-center justify-between border-b border-[#F0EDE8] bg-white px-6 py-2 text-xs text-gray-500">
                <span>Step {{ activeTabIndex + 1 }} of {{ CREATE_TABS.length }}</span>
                <span class="font-semibold text-foreground">{{ CREATE_TABS[activeTabIndex]?.label }}</span>
                <span>{{ activeTabIndex + 1 }} / {{ CREATE_TABS.length }} completed</span>
            </div>

            <!-- Tab content -->
            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-5">

                <!-- Global error -->
                <div
                    v-if="modalError"
                    class="mb-4 flex items-center gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                >
                    <XCircle class="size-4 shrink-0" />
                    {{ modalError }}
                </div>

                <!-- ── Basics ── -->
                <div v-show="activeTab === 'basics'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-3 text-sm font-semibold">Basics <span class="font-normal text-muted-foreground">— Configure the core live cast details</span></p>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label>Live Cast Title <span class="text-destructive">*</span></Label>
                                <Input v-model="form.title" placeholder="How to Scale Your Agency in 2026" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Host Name</Label>
                                <Input v-model="form.settings.host_name" placeholder="VIP Training" />
                            </div>
                        </div>
                        <div class="mt-4 space-y-1.5">
                            <Label>Description</Label>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                class="w-full rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                placeholder="Tell attendees what this live cast covers..."
                            />
                        </div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div class="space-y-1.5">
                                <Label>Status</Label>
                                <select v-model="form.status" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                    <option
                                        v-for="opt in WEBINAR_STATUS_OPTIONS"
                                        :key="opt.value"
                                        :value="opt.value"
                                    >
                                        {{ opt.label }}
                                    </option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <Label>
                                    Starts At
                                    <span v-if="!scheduleDatesDisabled" class="text-destructive">*</span>
                                </Label>
                                <Input
                                    v-model="form.starts_at"
                                    type="datetime-local"
                                    :disabled="scheduleDatesDisabled"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Ends At</Label>
                                <Input
                                    v-model="form.ends_at"
                                    type="datetime-local"
                                    :disabled="scheduleDatesDisabled"
                                />
                            </div>
                        </div>
                        <p
                            v-if="scheduleDatesDisabled"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Live webinars are open now — start and end dates are not used.
                        </p>
                    </div>
                </div>

                <!-- ── Source (CREATE only) ── -->
                <div v-show="activeTab === 'source'" class="space-y-4">

                    <!-- Mode selector cards -->
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            type="button"
                            :class="[
                                'group flex flex-col items-start gap-3 rounded-xl border-2 p-5 text-left transition-all',
                                !isGoLiveSourceType(form.settings.source_type)
                                    ? 'border-[#E8563A] bg-[#E8563A]/5 shadow-sm'
                                    : 'border-border bg-white hover:border-[#E8563A]/40 hover:bg-[#E8563A]/5',
                            ]"
                            @click="form.settings.source_type = 'upload'"
                        >
                            <div :class="['flex size-10 items-center justify-center rounded-xl', !isGoLiveSourceType(form.settings.source_type) ? 'bg-[#E8563A]' : 'bg-gray-100 group-hover:bg-[#E8563A]/10']">
                                <Film :class="['size-5', !isGoLiveSourceType(form.settings.source_type) ? 'text-white' : 'text-gray-500']" />
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900">Pre-recorded Video</p>
                                <p class="mt-1 text-xs text-gray-500">Upload a video file or pick one from your library. Perfect for scheduled or evergreen content.</p>
                            </div>
                            <div :class="['mt-auto flex h-5 w-5 items-center justify-center rounded-full border-2', !isGoLiveSourceType(form.settings.source_type) ? 'border-[#E8563A] bg-[#E8563A]' : 'border-gray-300']">
                                <div v-if="!isGoLiveSourceType(form.settings.source_type)" class="size-2 rounded-full bg-white" />
                            </div>
                        </button>

                        <button
                            type="button"
                            :class="[
                                'group flex flex-col items-start gap-3 rounded-xl border-2 p-5 text-left transition-all',
                                form.settings.source_type === 'daily'
                                    ? 'border-[#E8563A] bg-[#E8563A]/5 shadow-sm'
                                    : 'border-border bg-white hover:border-[#E8563A]/40 hover:bg-[#E8563A]/5',
                            ]"
                            @click="form.settings.source_type = 'daily'"
                        >
                            <div :class="['flex size-10 items-center justify-center rounded-xl', form.settings.source_type === 'daily' ? 'bg-[#E8563A]' : 'bg-gray-100 group-hover:bg-[#E8563A]/10']">
                                <Radio :class="['size-5', form.settings.source_type === 'daily' ? 'text-white' : 'text-gray-500']" />
                            </div>
                            <div>
                                <p class="font-semibold text-gray-900">Go Live</p>
                                <p class="mt-1 text-xs text-gray-500">Broadcast in real-time from your browser. No OBS or stream keys required.</p>
                            </div>
                            <div :class="['mt-auto flex h-5 w-5 items-center justify-center rounded-full border-2', form.settings.source_type === 'daily' ? 'border-[#E8563A] bg-[#E8563A]' : 'border-gray-300']">
                                <div v-if="form.settings.source_type === 'daily'" class="size-2 rounded-full bg-white" />
                            </div>
                        </button>
                    </div>

                    <!-- Go Live notice -->
                    <div v-if="form.settings.source_type === 'daily'" class="space-y-3">
                        <div
                            v-if="!dailyLiveEnabled"
                            class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
                        >
                            <p class="font-semibold">Daily is not configured on this server</p>
                            <p class="mt-1 text-xs text-red-800">
                                Add <code class="rounded bg-red-100 px-1">DAILY_API_KEY</code> to your production
                                environment before creating a go-live cast.
                            </p>
                        </div>
                        <div class="rounded-xl border border-[#E8563A]/30 bg-[#E8563A]/5 p-4">
                            <div class="flex items-start gap-3">
                                <Radio class="mt-0.5 size-4 shrink-0 text-[#E8563A]" />
                                <div class="text-sm">
                                    <p class="font-semibold text-gray-900">Your live room is created when you save</p>
                                    <p class="mt-1 text-xs text-gray-500">
                                        After you click <strong>Create</strong>, a private live room is provisioned automatically.
                                        The edit modal opens on the <strong>Go Live</strong> tab where you can enable your camera and start broadcasting.
                                        Viewers watch inside your webinar room in real time.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pre-recorded video options -->
                    <div v-if="!isGoLiveSourceType(form.settings.source_type)" class="space-y-4 rounded-xl border p-4">
                        <p class="text-sm font-semibold text-gray-900">Video source</p>

                        <div class="rounded-xl border-2 border-dashed border-[#E8563A]/30 bg-[#E8563A]/5 p-4">
                            <p class="mb-1 text-sm font-semibold">Upload video file</p>
                            <p class="mb-3 text-xs text-muted-foreground">MP4, MOV, or WebM. File is saved to your video library and linked automatically.</p>
                            <input
                                type="file"
                                accept="video/mp4,video/quicktime,video/webm,video/*"
                                class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-[#E8563A] file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-white"
                                @change="onVideoFileSelected"
                            >
                            <p v-if="selectedVideoFile" class="mt-2 text-xs text-muted-foreground">
                                Selected: <strong>{{ selectedVideoFile.name }}</strong>
                            </p>
                            <p v-if="videoUploadError" class="mt-2 text-xs text-destructive">{{ videoUploadError }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <Button size="sm" :disabled="uploadingVideo || !selectedVideoFile" @click="uploadWebinarVideo">
                                    <Loader2 v-if="uploadingVideo" class="mr-2 size-4 animate-spin" />
                                    <Upload v-else class="mr-2 size-4" />
                                    {{ uploadingVideo ? 'Uploading...' : 'Upload & link video' }}
                                </Button>
                                <Button v-if="selectedVideoFile" size="sm" variant="ghost" @click="clearSelectedVideoFile">
                                    Clear
                                </Button>
                            </div>
                            <p v-if="form.video_id" class="mt-2 text-xs font-medium text-green-600">
                                ✓ Linked to library video #{{ form.video_id }}
                            </p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label>Or pick from library</Label>
                                <select v-model="form.video_id" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                    <option :value="null">No linked video</option>
                                    <option v-for="video in videos" :key="video.id" :value="video.id">{{ video.title }}</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Or paste a video URL</Label>
                                <Input v-model="form.settings.video_url" placeholder="YouTube, Vimeo, or direct MP4 URL" />
                                <p class="text-xs text-muted-foreground">Paste a YouTube/Vimeo link to embed, or a direct .mp4 URL.</p>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Thumbnail URL</Label>
                                <Input v-model="form.settings.thumbnail_url" placeholder="https://.../thumb.png" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Video duration (seconds)</Label>
                                <Input
                                    :model-value="form.settings.video_duration_seconds ?? ''"
                                    type="number"
                                    min="1"
                                    placeholder="e.g. 3600"
                                    @update:model-value="(v) => { form.settings.video_duration_seconds = v === '' ? null : Number(v); }"
                                />
                                <p class="text-xs text-muted-foreground">Used for offer timers and watch-tracking.</p>
                            </div>
                        </div>

                        <!-- Previews -->
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-md border bg-muted/30 p-3">
                                <p class="mb-2 text-xs font-medium text-muted-foreground">Thumbnail preview</p>
                                <div class="flex h-28 items-center justify-center overflow-hidden rounded-md border bg-muted">
                                    <img v-if="selectedThumbnailUrl()" :src="selectedThumbnailUrl()" class="h-full w-full object-cover">
                                    <ImageOff v-else class="size-5 text-muted-foreground" />
                                </div>
                            </div>
                            <div class="rounded-md border bg-muted/30 p-3">
                                <p class="mb-2 text-xs font-medium text-muted-foreground">Video preview</p>
                                <div class="flex aspect-video items-center justify-center overflow-hidden rounded-md border bg-black/90">
                                    <iframe
                                        v-if="isEmbedPlayback(selectedPlayback)"
                                        :src="selectedPlayback.embed_url"
                                        title="Video preview"
                                        class="h-full w-full border-0"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                        allowfullscreen
                                    />
                                    <video
                                        v-else-if="selectedPlayback?.direct_url"
                                        :src="selectedPlayback.direct_url"
                                        controls
                                        playsinline
                                        class="h-full w-full object-contain"
                                    />
                                    <Film v-else class="size-8 text-muted-foreground" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Registration ── -->
                <div v-show="activeTab === 'registration'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-3 text-sm font-semibold">Registration Page <span class="font-normal text-muted-foreground">— Customize the public registration form</span></p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label>Registration Page Title</Label>
                                <Input v-model="form.settings.registration_title" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Room Title (shown in header)</Label>
                                <Input v-model="form.settings.room_title" placeholder="In-call chat" />
                            </div>
                        </div>
                        <div class="mt-4 space-y-1.5">
                            <Label>Registration Page Description</Label>
                            <textarea
                                v-model="form.settings.registration_description"
                                rows="3"
                                class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            />
                        </div>
                        <div class="mt-4 rounded-lg border border-dashed bg-muted/20 p-3 text-xs text-muted-foreground">
                                Registration and room URLs will be generated after the live cast is created.
                        </div>
                    </div>
                </div>

                <!-- ── Offers ── -->
                <div v-show="activeTab === 'offers'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-1 text-sm font-semibold">Offers</p>
                        <p class="mb-3 text-xs text-muted-foreground">
                            Assign products and schedule when each offer appears in the live room (requires video duration on the Video tab).
                        </p>
                        <div class="relative mb-3">
                            <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input v-model="productSearch" placeholder="Search products..." class="pl-9" />
                        </div>
                        <div v-if="products.length === 0" class="rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground">
                            No products yet. Add products in the Products section.
                        </div>
                        <div v-else class="max-h-[min(28rem,55vh)] space-y-2 overflow-y-auto">
                            <div
                                v-for="product in filteredProducts"
                                :key="product.id"
                                :class="[
                                    'overflow-hidden rounded-lg border transition-colors',
                                    isProductSelected(product.id) ? 'border-[#E8563A]/40 shadow-sm' : 'border-border',
                                ]"
                            >
                                <button
                                    type="button"
                                    :class="[
                                        'flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors',
                                        isProductSelected(product.id) ? 'bg-[#E8563A]/5' : 'hover:bg-muted/40',
                                    ]"
                                    @click="toggleProduct(product.id)"
                                >
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted">
                                        <img v-if="product.image_url" :src="product.image_url" class="h-full w-full object-cover">
                                        <ImageOff v-else class="size-4 text-muted-foreground" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium">{{ product.title }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ product.sale_price ? `$${product.sale_price}` : product.price ? `$${product.price}` : '' }}
                                        </p>
                                    </div>
                                    <div :class="[
                                        'flex size-5 shrink-0 items-center justify-center rounded-full border-2',
                                        isProductSelected(product.id) ? 'border-[#E8563A] bg-[#E8563A] text-white' : 'border-muted-foreground/30',
                                    ]">
                                        <svg v-if="isProductSelected(product.id)" class="size-3" viewBox="0 0 12 12" fill="none">
                                            <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </div>
                                    <ChevronDown
                                        :class="[
                                            'size-4 shrink-0 text-muted-foreground transition-transform',
                                            isProductSelected(product.id) ? 'rotate-180 text-[#E8563A]' : '',
                                        ]"
                                    />
                                </button>

                                <div
                                    v-if="isProductSelected(product.id) && offerForProduct(product.id)"
                                    class="space-y-3 border-t border-[#E8563A]/15 bg-muted/20 px-3 py-3"
                                >
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div class="space-y-1.5">
                                            <Label>Show after (seconds)</Label>
                                            <Input
                                                :model-value="offerForProduct(product.id)!.starts_at_sec"
                                                type="number"
                                                min="0"
                                                :max="videoDurationSeconds ?? undefined"
                                                @update:model-value="(v) => updateOffer(product.id, { starts_at_sec: Number(v) || 0 })"
                                            />
                                        </div>
                                        <div class="space-y-1.5">
                                            <Label>Hide after (optional, seconds)</Label>
                                            <Input
                                                :model-value="offerForProduct(product.id)!.ends_at_sec ?? ''"
                                                type="number"
                                                min="0"
                                                :max="videoDurationSeconds ?? undefined"
                                                placeholder="Until video ends"
                                                @update:model-value="(v) => updateOffer(product.id, { ends_at_sec: v === '' || v == null ? null : Number(v) })"
                                            />
                                        </div>
                                    </div>
                                    <div class="space-y-1.5">
                                        <Label>How it appears</Label>
                                        <select
                                            :value="offerForProduct(product.id)!.appearance"
                                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                            @change="(e) => updateOffer(product.id, { appearance: (e.target as HTMLSelectElement).value as OfferAppearance })"
                                        >
                                            <option
                                                v-for="opt in OFFER_APPEARANCE_OPTIONS"
                                                :key="opt.value"
                                                :value="opt.value"
                                            >
                                                {{ opt.label }}
                                            </option>
                                        </select>
                                        <p class="text-xs text-muted-foreground">
                                            {{ OFFER_APPEARANCE_OPTIONS.find((o) => o.value === offerForProduct(product.id)!.appearance)?.hint }}
                                        </p>
                                    </div>
                                    <div class="space-y-1.5">
                                        <Label class="flex items-center gap-1.5">
                                            <Link2 class="size-3.5" />
                                            Checkout link
                                        </Label>
                                        <p class="rounded-md border bg-background px-2 py-1.5 text-xs text-muted-foreground break-all">
                                            Default: {{ offerCheckoutHint(product.id) }}
                                        </p>
                                        <Input
                                            :model-value="offerForProduct(product.id)!.cta_url"
                                            placeholder="Override URL (leave empty for default checkout)"
                                            @update:model-value="(v) => updateOffer(product.id, { cta_url: String(v ?? '') })"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p v-if="form.featured_offers.length" class="mt-3 text-xs text-muted-foreground">
                            {{ form.featured_offers.length }} offer(s) assigned — expand each product to edit its schedule.
                        </p>
                        <div class="mt-4 rounded-lg border border-dashed bg-muted/20 p-3 text-xs text-muted-foreground">
                            After you create this webinar, open <strong>Edit</strong> to enable attendee chat, AI auto-replies, and knowledge base sources.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <DialogFooter class="shrink-0 border-t px-6 py-4">
                <div class="flex w-full items-center justify-between">
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" :disabled="!canGoPrev" @click="prevTab">
                            <ChevronLeft class="mr-1 size-4" />
                            Previous
                        </Button>
                        <Button variant="outline" size="sm" :disabled="!canGoNext" @click="nextTab">
                            Next
                            <ChevronRight class="ml-1 size-4" />
                        </Button>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="ghost" @click="createModalOpen = false">Cancel</Button>
                <Button class="cta-btn" :disabled="saving" @click="createWebinar">
                            <Loader2 v-if="saving" class="mr-2 size-4 animate-spin" />
                            {{ saving ? 'Creating...' : 'Create Live Cast' }}
                        </Button>
                    </div>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- ═══════════════════════════════════════ Edit Dialog ══════════════════════════════════════ -->
    <Dialog :open="editModalOpen" @update:open="handleEditModalClose">
        <DialogContent class="flex max-h-[min(92dvh,calc(100vh-2rem))] min-h-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-5xl">

            <!-- Header -->
            <div class="shrink-0 border-b px-6 py-4">
                <p v-if="editingWebinar" class="truncate text-xs text-muted-foreground">{{ editingWebinar.title }}</p>
                <DialogTitle class="text-lg font-semibold">Edit Live Cast</DialogTitle>
                <DialogDescription>Update settings, automation, and publishing options.</DialogDescription>
            </div>

            <!-- Stats row -->
            <div v-if="editingWebinar" class="grid shrink-0 grid-cols-2 gap-px border-b bg-border sm:grid-cols-4 xl:grid-cols-8">
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">Registrants</p>
                    <p class="text-base font-bold">{{ (attendeeTotal || editingWebinar.registrants_count || 0).toLocaleString() }}</p>
                </div>
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">Views</p>
                    <p class="text-base font-bold">{{ editingWebinar.views_count || 0 }}</p>
                </div>
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">Watched 50%</p>
                    <p class="text-base font-bold">{{ editingWebinar.watched_half_count ?? 0 }}</p>
                </div>
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">Watched to end</p>
                    <p class="text-base font-bold">{{ editingWebinar.watched_end_count ?? 0 }}</p>
                </div>
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">Messages</p>
                    <p class="text-base font-bold">{{ editingWebinar.messages_count || 0 }}</p>
                </div>
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">Offers</p>
                    <p class="text-base font-bold">{{ form.featured_offers.length }}</p>
                </div>
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">AI Sources</p>
                    <p class="text-base font-bold">{{ form.settings.knowledge_sources.length }} / 3</p>
                </div>
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">Status</p>
                    <Badge :variant="statusVariant(form.status)">{{ statusLabel(form.status) }}</Badge>
                </div>
            </div>

            <!-- Tab nav -->
            <div class="shrink-0 overflow-x-auto border-b border-[#F0EDE8] bg-[#FAF8F5]">
                <div class="flex min-w-max">
                    <button
                        v-for="tab in editTabs"
                        :key="tab.id"
                        type="button"
                        :class="[
                            'flex items-center gap-2 border-b-2 px-5 py-3 text-sm font-medium transition-colors',
                            activeTab === tab.id
                                ? 'border-[#E8563A] text-[#E8563A]'
                                : 'border-transparent text-gray-500 hover:text-gray-900',
                        ]"
                        @click="goToTab(tab.id)"
                    >
                        <component :is="tab.icon" class="size-3.5" />
                        {{ tab.label }}
                    </button>
                </div>
            </div>

            <!-- Step indicator -->
            <div class="flex shrink-0 items-center justify-between border-b border-[#F0EDE8] bg-white px-6 py-2 text-xs text-gray-500">
                <span>Step {{ activeTabIndex + 1 }} of {{ editTabs.length }}</span>
                <span class="font-semibold text-foreground">{{ editTabs[activeTabIndex]?.label }}</span>
                <span>{{ activeTabIndex + 1 }} / {{ editTabs.length }} completed</span>
            </div>

            <!-- Tab content -->
            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-5">

                <!-- Global error -->
                <div
                    v-if="modalError"
                    class="mb-4 flex items-center gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                >
                    <XCircle class="size-4 shrink-0" />
                    {{ modalError }}
                </div>

                <!-- ── Basics ── -->
                <div v-show="activeTab === 'basics'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-3 text-sm font-semibold">Basics</p>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label>Webinar Title <span class="text-destructive">*</span></Label>
                                <Input v-model="form.title" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Host Name</Label>
                                <Input v-model="form.settings.host_name" />
                            </div>
                        </div>
                        <div class="mt-4 space-y-1.5">
                            <Label>Description</Label>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            />
                        </div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div class="space-y-1.5">
                                <Label>Status</Label>
                                <select v-model="form.status" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                    <option
                                        v-for="opt in WEBINAR_STATUS_OPTIONS"
                                        :key="opt.value"
                                        :value="opt.value"
                                    >
                                        {{ opt.label }}
                                    </option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <Label>
                                    Starts At
                                    <span v-if="!scheduleDatesDisabled" class="text-destructive">*</span>
                                </Label>
                                <Input
                                    v-model="form.starts_at"
                                    type="datetime-local"
                                    :disabled="scheduleDatesDisabled"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Ends At</Label>
                                <Input
                                    v-model="form.ends_at"
                                    type="datetime-local"
                                    :disabled="scheduleDatesDisabled"
                                />
                            </div>
                        </div>
                        <p
                            v-if="scheduleDatesDisabled"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Live webinars are open now — start and end dates are not used.
                        </p>
                    </div>
                </div>

                <!-- ── Streaming (Go Live) ── -->
                <div v-show="activeTab === 'streaming'" class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#E8563A]/30 bg-[#E8563A]/5 px-4 py-3">
                        <div class="flex items-start gap-3">
                            <Radio class="mt-0.5 size-4 shrink-0 text-[#E8563A]" />
                            <div class="text-sm">
                                <p class="font-semibold text-gray-900">How to go live with Daily</p>
                                <p class="mt-1 text-xs text-gray-600">
                                    Click <strong>Join as host</strong>, allow camera and microphone, then present.
                                    When the badge shows <strong>On air</strong>, share the viewer room link below.
                                </p>
                            </div>
                        </div>
                        <Badge :variant="dailyHostJoined ? 'default' : 'secondary'" class="shrink-0">
                            {{ dailyHostJoined ? 'On air' : 'Not joined' }}
                        </Badge>
                    </div>

                    <div
                        v-if="isDailyCast && !dailyLiveEnabled"
                        class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900"
                    >
                        <p class="font-semibold">Daily is not configured on this server</p>
                        <p class="mt-1 text-xs text-red-800">
                            Add <code class="rounded bg-red-100 px-1">DAILY_API_KEY</code> in your production environment
                            (Laravel Forge → Environment), then redeploy or run
                            <code class="rounded bg-red-100 px-1">php artisan config:cache</code>.
                            This is a server setting — not part of the frontend build.
                        </p>
                    </div>

                    <div v-if="isDailyCast" class="space-y-4">
                        <DailyBroadcastPanel
                            v-if="editingWebinar?.id && dailyLiveEnabled"
                            :live-show-id="editingWebinar.id"
                            :room-url="dailySettings.room_url"
                            :host-name="form.settings.host_name"
                            :active="editModalOpen && activeTab === 'streaming'"
                            @joined-change="dailyHostJoined = $event"
                        />

                        <div v-if="editingWebinar?.room_url" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">Viewer room</p>
                                <p class="text-xs text-muted-foreground">Share this link with attendees or open it to preview as a viewer.</p>
                            </div>
                            <div class="flex gap-2">
                                <Button size="sm" variant="outline" as-child>
                                    <a :href="editingWebinar.room_url" target="_blank" rel="noopener noreferrer">
                                        <ExternalLink class="mr-1.5 size-4" />
                                        Open viewer room
                                    </a>
                                </Button>
                                <Button size="sm" variant="ghost" @click="copyToClipboard(editingWebinar.room_url)">
                                    <Copy class="mr-1.5 size-4" />
                                    Copy link
                                </Button>
                            </div>
                        </div>

                        <div
                            v-if="!hasDailyRoom"
                            class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                        >
                            Live room is still provisioning. Save the cast again or contact your administrator if this persists.
                        </div>
                    </div>

                    <div
                        v-else
                        class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                    >
                        This cast is not set up for browser go-live. Create a new live cast and choose
                        <strong>Go Live</strong> as the source type.
                    </div>
                </div>

                <!-- ── Video (Pre-recorded) ── -->
                <div v-show="activeTab === 'video'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-3 text-sm font-semibold">Video</p>

                        <div class="mb-4 rounded-xl border-2 border-dashed border-[#E8563A]/30 bg-[#E8563A]/5 p-4">
                            <p class="mb-1 text-sm font-semibold">Upload video file</p>
                            <p class="mb-3 text-xs text-muted-foreground">MP4, MOV, or WebM. Saved to your library and linked to this webinar.</p>
                            <input
                                type="file"
                                accept="video/mp4,video/quicktime,video/webm,video/*"
                                class="block w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-[#E8563A] file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-white"
                                @change="onVideoFileSelected"
                            >
                            <p v-if="selectedVideoFile" class="mt-2 text-xs text-muted-foreground">
                                Selected: <strong>{{ selectedVideoFile.name }}</strong>
                            </p>
                            <p v-if="videoUploadError" class="mt-2 text-xs text-destructive">{{ videoUploadError }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    :disabled="uploadingVideo || !selectedVideoFile"
                                    @click="uploadWebinarVideo"
                                >
                                    <Loader2 v-if="uploadingVideo" class="mr-2 size-4 animate-spin" />
                                    <Upload v-else class="mr-2 size-4" />
                                    {{ uploadingVideo ? 'Uploading...' : 'Upload & link video' }}
                                </Button>
                                <Button
                                    v-if="selectedVideoFile"
                                    size="sm"
                                    variant="ghost"
                                    @click="clearSelectedVideoFile"
                                >
                                    Clear file
                                </Button>
                            </div>
                            <p v-if="form.video_id" class="mt-2 text-xs font-medium text-green-600 dark:text-green-400">
                                ✓ Linked to library video #{{ form.video_id }}
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label>Video source</Label>
                                <select v-model="form.settings.source_type" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                    <option value="upload">Uploaded Video</option>
                                    <option value="url">Direct URL / Embed</option>
                                    <option value="ai">AI Generated Video</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Linked library video</Label>
                                <select v-model="form.video_id" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                    <option :value="null">No linked video</option>
                                    <option v-for="video in videos" :key="video.id" :value="video.id">{{ video.title }}</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Thumbnail URL</Label>
                                <Input v-model="form.settings.thumbnail_url" placeholder="https://.../thumb.png" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Video URL override</Label>
                                <Input
                                    v-model="form.settings.video_url"
                                    placeholder="YouTube, Vimeo, or direct MP4 URL"
                                />
                            </div>
                            <div class="space-y-1.5 sm:col-span-2">
                                <Label>Video duration (seconds)</Label>
                                <Input
                                    :model-value="form.settings.video_duration_seconds ?? ''"
                                    type="number"
                                    min="1"
                                    placeholder="e.g. 3600"
                                    @update:model-value="(v) => { form.settings.video_duration_seconds = v === '' ? null : Number(v); }"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Used for offer timers and 50%/watched-to-end tracking.
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-md border bg-muted/30 p-3">
                                <p class="mb-2 text-xs font-medium text-muted-foreground">Thumbnail Preview</p>
                                <div class="flex h-36 items-center justify-center overflow-hidden rounded-md border bg-muted">
                                    <img v-if="selectedThumbnailUrl()" :src="selectedThumbnailUrl()" class="h-full w-full object-cover">
                                    <ImageOff v-else class="size-5 text-muted-foreground" />
                                </div>
                            </div>
                            <div class="rounded-md border bg-muted/30 p-3">
                                <p class="mb-2 text-xs font-medium text-muted-foreground">Video preview</p>
                                <div class="flex aspect-video items-center justify-center overflow-hidden rounded-md border bg-black/90">
                                    <iframe
                                        v-if="isEmbedPlayback(selectedPlayback)"
                                        :src="selectedPlayback.embed_url"
                                        title="Video preview"
                                        class="h-full w-full border-0"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                        allowfullscreen
                                    />
                                    <video
                                        v-else-if="selectedPlayback?.direct_url"
                                        :src="selectedPlayback.direct_url"
                                        controls
                                        playsinline
                                        class="h-full w-full object-contain"
                                    />
                                    <Film v-else class="size-8 text-muted-foreground" />
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Registration ── -->
                <div v-show="activeTab === 'registration'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-3 text-sm font-semibold">Registration Page</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label>Registration Page Title</Label>
                                <Input v-model="form.settings.registration_title" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Room Title</Label>
                                <Input v-model="form.settings.room_title" />
                            </div>
                        </div>
                        <div class="mt-4 space-y-1.5">
                            <Label>Registration Description</Label>
                            <textarea
                                v-model="form.settings.registration_description"
                                rows="3"
                                class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            />
                        </div>
                    </div>
                </div>

                <!-- ── Attendees ── -->
                <div v-show="activeTab === 'attendees'" class="space-y-4">
                    <div class="flex max-h-[min(28rem,52vh)] min-h-0 flex-col rounded-lg border p-4">
                        <div class="mb-3 flex shrink-0 flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-semibold">
                                Registered Attendees
                                <span class="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                    {{ (attendeeTotal || editingWebinar?.registrants_count || 0).toLocaleString() }}
                                </span>
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <input
                                    ref="importAttendeesInputRef"
                                    type="file"
                                    accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                    class="hidden"
                                    @change="onAttendeeImportSelected"
                                >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="importingAttendees || !editingWebinar"
                                    @click="openAttendeeImportPicker"
                                >
                                    <Loader2 v-if="importingAttendees" class="mr-1.5 size-3.5 animate-spin" />
                                    <Upload v-else class="mr-1.5 size-3.5" />
                                    Import CSV / Excel
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="notifyingAttendees || !hasAttendeeRegistrations"
                                    @click="notifyAllAttendees"
                                >
                                    <Loader2 v-if="notifyingAttendees" class="mr-1.5 size-3.5 animate-spin" />
                                    <Mail v-else class="mr-1.5 size-3.5" />
                                    Notify all attendees
                                </Button>
                            </div>
                        </div>
                        <p class="mb-3 shrink-0 text-xs text-muted-foreground">
                            Import CSV or Excel with an <strong>email</strong> column (required). A
                            <strong>full_name</strong> column is optional — if missing, names are generated from the email.
                            One email per line also works. Emails are sent in the background (10 per batch).
                        </p>
                        <div v-if="loadingAttendees" class="min-h-0 flex-1 space-y-2 overflow-y-auto">
                            <Skeleton v-for="n in 5" :key="n" class="h-12 rounded-md" />
                        </div>
                        <div
                            v-else-if="attendeeTotal === 0"
                            class="flex flex-1 items-center justify-center rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground"
                        >
                            No registrations yet. Share the registration link to get attendees.
                        </div>
                        <template v-else>
                            <div class="min-h-0 flex-1 space-y-2 overflow-y-auto overscroll-contain pr-1">
                                <div
                                    v-for="attendee in attendees"
                                    :key="attendee.id"
                                    class="flex items-center justify-between rounded-md border px-3 py-2"
                                >
                                    <div class="min-w-0 pr-3">
                                        <p class="truncate text-sm font-medium">{{ attendee.full_name }}</p>
                                        <p class="truncate text-xs text-muted-foreground">{{ attendee.email }}</p>
                                    </div>
                                    <div class="shrink-0 text-right text-xs text-muted-foreground">
                                        <div class="mb-1 flex flex-wrap justify-end gap-1">
                                            <Badge
                                                v-if="attendee.watched_to_end_at"
                                                variant="default"
                                                class="bg-[#E8563A] hover:bg-[#E8563A]"
                                            >
                                                Watched to end
                                            </Badge>
                                            <Badge
                                                v-else-if="attendee.reached_half_at"
                                                variant="secondary"
                                            >
                                                Watched 50%+
                                            </Badge>
                                        </div>
                                        <p>{{ attendee.join_count ?? 0 }} join(s)</p>
                                        <p>{{ formatDate(attendee.registered_at) }}</p>
                                    </div>
                                </div>
                            </div>
                            <div
                                class="mt-3 flex shrink-0 flex-wrap items-center justify-between gap-2 border-t pt-3"
                                :class="attendeeLastPage <= 1 ? 'mt-2 border-t-0 pt-0' : ''"
                            >
                                <p class="text-xs text-muted-foreground">
                                    {{ attendeePageRangeLabel }}
                                </p>
                                <div v-if="attendeeLastPage > 1" class="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        :disabled="loadingAttendees || attendeePage <= 1"
                                        @click="goToAttendeePage(attendeePage - 1)"
                                    >
                                        <ChevronLeft class="mr-1 size-3.5" />
                                        Previous
                                    </Button>
                                    <span class="text-xs text-muted-foreground">
                                        Page {{ attendeePage }} of {{ attendeeLastPage }}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        :disabled="loadingAttendees || attendeePage >= attendeeLastPage"
                                        @click="goToAttendeePage(attendeePage + 1)"
                                    >
                                        Next
                                        <ChevronRight class="ml-1 size-3.5" />
                                    </Button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- ── Chat & Automation ── -->
                <div v-show="activeTab === 'chat'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-3 text-sm font-semibold">Chat Settings</p>
                        <label class="flex items-start gap-3 rounded-md border bg-muted/20 p-3">
                            <input v-model="form.settings.chat_enabled" type="checkbox" class="mt-0.5 h-4 w-4 rounded">
                            <div>
                                <p class="text-sm font-medium">Enable attendee chat</p>
                                <p class="text-xs text-muted-foreground">Allow attendees to send messages in the webinar room.</p>
                            </div>
                        </label>
                    </div>

                    <div class="rounded-lg border bg-muted/20 p-6 text-center">
                        <MessageSquare class="mx-auto mb-3 size-10 text-[#E8563A] opacity-80" />
                        <p class="font-semibold">Manage attendee chats</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Reply to attendees in a WhatsApp-style inbox. Your replies appear live in the webinar room.
                        </p>
                        <Button class="mt-4" as-child>
                            <Link :href="chatsPageUrl(editingWebinar?.id)">
                                <MessageSquare class="mr-2 size-4" />
                                Open Webinar Chats
                            </Link>
                        </Button>
                    </div>
                </div>

                <!-- ── Offers ── -->
                <div v-show="activeTab === 'offers'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-1 text-sm font-semibold">Offers</p>
                        <p class="mb-3 text-xs text-muted-foreground">
                            Schedule when each product appears in the room player. Set video duration on the Video tab first.
                        </p>
                        <div v-if="!videoDurationSeconds" class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            Add <strong>video duration</strong> on the Video tab so offer timers can be validated.
                        </div>
                        <div class="relative mb-3">
                            <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input v-model="productSearch" placeholder="Search products..." class="pl-9" />
                        </div>
                        <div v-if="products.length === 0" class="rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground">
                            No products yet.
                        </div>
                        <div v-else class="max-h-[min(28rem,55vh)] space-y-2 overflow-y-auto">
                            <div
                                v-for="product in filteredProducts"
                                :key="product.id"
                                :class="[
                                    'overflow-hidden rounded-lg border transition-colors',
                                    isProductSelected(product.id) ? 'border-[#E8563A]/40 shadow-sm' : 'border-border',
                                ]"
                            >
                                <button
                                    type="button"
                                    :class="[
                                        'flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors',
                                        isProductSelected(product.id) ? 'bg-[#E8563A]/5' : 'hover:bg-muted/40',
                                    ]"
                                    @click="toggleProduct(product.id)"
                                >
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted">
                                        <img v-if="product.image_url" :src="product.image_url" class="h-full w-full object-cover">
                                        <ImageOff v-else class="size-4 text-muted-foreground" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium">{{ product.title }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            {{ product.sale_price ? `$${product.sale_price}` : product.price ? `$${product.price}` : '' }}
                                        </p>
                                    </div>
                                    <div :class="[
                                        'flex size-5 shrink-0 items-center justify-center rounded-full border-2',
                                        isProductSelected(product.id) ? 'border-[#E8563A] bg-[#E8563A] text-white' : 'border-muted-foreground/30',
                                    ]">
                                        <svg v-if="isProductSelected(product.id)" class="size-3" viewBox="0 0 12 12" fill="none">
                                            <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </div>
                                    <ChevronDown
                                        :class="[
                                            'size-4 shrink-0 text-muted-foreground transition-transform',
                                            isProductSelected(product.id) ? 'rotate-180 text-[#E8563A]' : '',
                                        ]"
                                    />
                                </button>

                                <div
                                    v-if="isProductSelected(product.id) && offerForProduct(product.id)"
                                    class="space-y-3 border-t border-[#E8563A]/15 bg-muted/20 px-3 py-3"
                                >
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div class="space-y-1.5">
                                            <Label>Show after (seconds)</Label>
                                            <Input
                                                :model-value="offerForProduct(product.id)!.starts_at_sec"
                                                type="number"
                                                min="0"
                                                :max="videoDurationSeconds ?? undefined"
                                                @update:model-value="(v) => updateOffer(product.id, { starts_at_sec: Number(v) || 0 })"
                                            />
                                        </div>
                                        <div class="space-y-1.5">
                                            <Label>Hide after (optional, seconds)</Label>
                                            <Input
                                                :model-value="offerForProduct(product.id)!.ends_at_sec ?? ''"
                                                type="number"
                                                min="0"
                                                :max="videoDurationSeconds ?? undefined"
                                                placeholder="Until video ends"
                                                @update:model-value="(v) => updateOffer(product.id, { ends_at_sec: v === '' || v == null ? null : Number(v) })"
                                            />
                                        </div>
                                    </div>
                                    <div class="space-y-1.5">
                                        <Label>How it appears</Label>
                                        <select
                                            :value="offerForProduct(product.id)!.appearance"
                                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                            @change="(e) => updateOffer(product.id, { appearance: (e.target as HTMLSelectElement).value as OfferAppearance })"
                                        >
                                            <option
                                                v-for="opt in OFFER_APPEARANCE_OPTIONS"
                                                :key="opt.value"
                                                :value="opt.value"
                                            >
                                                {{ opt.label }}
                                            </option>
                                        </select>
                                        <p class="text-xs text-muted-foreground">
                                            {{ OFFER_APPEARANCE_OPTIONS.find((o) => o.value === offerForProduct(product.id)!.appearance)?.hint }}
                                        </p>
                                    </div>
                                    <div class="space-y-1.5">
                                        <Label class="flex items-center gap-1.5">
                                            <Link2 class="size-3.5" />
                                            Checkout link
                                        </Label>
                                        <p class="rounded-md border bg-background px-2 py-1.5 text-xs text-muted-foreground break-all">
                                            Default: {{ offerCheckoutHint(product.id) }}
                                        </p>
                                        <Input
                                            :model-value="offerForProduct(product.id)!.cta_url"
                                            placeholder="Override URL (leave empty for default checkout)"
                                            @update:model-value="(v) => updateOffer(product.id, { cta_url: String(v ?? '') })"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p v-if="form.featured_offers.length" class="mt-3 text-xs text-muted-foreground">
                            {{ form.featured_offers.length }} offer(s) assigned — expand each product to edit its schedule.
                        </p>
                    </div>
                </div>

                <!-- ── AI Assistant ── -->
                <div v-show="activeTab === 'ai'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-3 text-sm font-semibold">AI Assistant</p>
                        <label class="flex items-start gap-3 rounded-md border bg-muted/20 p-3">
                            <input v-model="form.settings.ai_assistant_enabled" type="checkbox" class="mt-0.5 h-4 w-4 rounded">
                            <div>
                                <p class="text-sm font-medium">Enable AI assistant auto-replies</p>
                                <p class="text-xs text-muted-foreground">
                                    AI will automatically respond to attendee messages using the knowledge base sources below.
                                </p>
                            </div>
                        </label>
                    </div>

                    <div class="rounded-lg border p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <div>
                                <p class="text-sm font-semibold">Knowledge Base Sources</p>
                                <p class="text-xs text-muted-foreground">Max 3 sources. Click Review to inspect ingested content.</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span :class="[
                                    'rounded-full border px-2 py-0.5 text-xs font-medium',
                                    form.settings.knowledge_sources.length >= 3 ? 'border-amber-400/50 text-amber-600' : '',
                                ]">
                                    {{ form.settings.knowledge_sources.length }} / 3
                                </span>
                                <Button
                                    v-if="canAddSource && !addingSource"
                                    variant="outline"
                                    size="sm"
                                    @click="addingSource = true"
                                >
                                    <Plus class="mr-1 size-3.5" />
                                    Add Source
                                </Button>
                            </div>
                        </div>

                        <!-- Existing sources -->
                        <div class="space-y-2">
                            <div
                                v-for="(source, sourceIndex) in form.settings.knowledge_sources"
                                :key="sourceIndex"
                                class="overflow-hidden rounded-lg border"
                            >
                                <div class="flex items-center justify-between gap-2 bg-muted/20 px-4 py-3">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <BookOpen class="size-3.5 shrink-0 text-[#E8563A]" />
                                        <span class="truncate text-sm font-medium">{{ source.title }}</span>
                                        <span class="shrink-0 rounded-full bg-green-500/15 px-1.5 py-0.5 text-xs text-green-700 dark:text-green-400">
                                            ✓ Ingested
                                        </span>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="h-7 gap-1 text-xs"
                                            @click="toggleSourceExpanded(sourceIndex)"
                                        >
                                            <Eye class="size-3.5" />
                                            {{ expandedSourceIndex === sourceIndex ? 'Hide' : 'Review' }}
                                            <ChevronUp v-if="expandedSourceIndex === sourceIndex" class="size-3" />
                                            <ChevronDown v-else class="size-3" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                            @click="removeKnowledgeSource(sourceIndex)"
                                        >
                                            <Trash2 class="size-3.5" />
                                        </Button>
                                    </div>
                                </div>
                                <div v-if="expandedSourceIndex !== sourceIndex" class="px-4 py-2">
                                    <p class="truncate text-xs text-muted-foreground">
                                        {{ source.content.slice(0, 120) }}{{ source.content.length > 120 ? '...' : '' }}
                                    </p>
                                </div>
                                <div v-else class="border-t bg-muted/10 px-4 py-3">
                                    <div class="mb-2 flex items-center gap-2">
                                        <span class="text-xs font-medium text-muted-foreground">Full content</span>
                                        <span class="text-xs text-muted-foreground">· {{ source.content.length }} chars</span>
                                    </div>
                                    <pre class="max-h-56 overflow-y-auto whitespace-pre-wrap rounded-md bg-muted p-3 text-xs">{{ source.content }}</pre>
                                </div>
                            </div>
                        </div>

                        <!-- Add source form -->
                        <div v-if="addingSource" class="mt-3 space-y-3 rounded-lg border bg-muted/10 p-4">
                            <div class="space-y-1.5">
                                <Label>Source Title</Label>
                                <Input v-model="sourceForm.title" placeholder="e.g. FAQ, Pricing, Common Objections" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Content</Label>
                                <textarea
                                    v-model="sourceForm.content"
                                    rows="7"
                                    class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                    placeholder="Paste or type the knowledge content that the AI will use to reply to attendees..."
                                />
                            </div>
                            <div class="flex gap-2">
                                <Button
                                    size="sm"
                                    :disabled="!sourceForm.title.trim() || !sourceForm.content.trim()"
                                    @click="addKnowledgeSource"
                                >
                                    Add Source
                                </Button>
                                <Button size="sm" variant="ghost" @click="cancelAddingSource">
                                    Cancel
                                </Button>
                            </div>
                        </div>

                        <div
                            v-if="form.settings.knowledge_sources.length === 0 && !addingSource"
                            class="mt-2 rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground"
                        >
                            No knowledge sources yet. Add up to 3 sources for AI-assisted replies.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <DialogFooter class="shrink-0 border-t px-6 py-4">
                <div class="flex w-full items-center justify-between">
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" :disabled="!canGoPrev" @click="prevTab">
                            <ChevronLeft class="mr-1 size-4" />
                            Previous
                        </Button>
                        <Button variant="outline" size="sm" :disabled="!canGoNext" @click="nextTab">
                            Next
                            <ChevronRight class="ml-1 size-4" />
                        </Button>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="ghost" @click="handleEditModalClose(false)">Cancel</Button>
                        <Button class="cta-btn" :disabled="saving" @click="saveWebinar">
                            <Loader2 v-if="saving" class="mr-2 size-4 animate-spin" />
                            {{ saving ? 'Saving...' : 'Save Changes' }}
                        </Button>
                    </div>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>

</template>

<style scoped>
.live-root {
    background-color: #F2EFEA;
}

.page-icon {
    background: linear-gradient(135deg, #E8563A, #ff8c42);
    box-shadow: 0 4px 12px rgba(232,86,58,0.35);
}

.stat-card,
.table-card {
    background: #fff;
    border: 1px solid #F0EDE8;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 16px rgba(0,0,0,0.06);
}

.stat-icon {
    background: rgba(232,86,58,0.10);
    box-shadow: inset 0 0 0 1px rgba(232,86,58,0.12);
}

.cta-btn {
    background: #E8563A;
    color: #fff;
    border: none;
    box-shadow: 0 4px 16px rgba(232,86,58,0.30);
    transition: all 0.15s;
}
.cta-btn:hover:not(:disabled) {
    background: #D44A2F;
    box-shadow: 0 6px 20px rgba(232,86,58,0.40);
    transform: translateY(-1px);
}
.cta-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.ghost-btn {
    background: #fff;
    border-color: #e5e7eb;
    color: #4b5563;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.ghost-btn:hover:not(:disabled) {
    border-color: rgba(232,86,58,0.40);
    color: #E8563A;
    background: rgba(232,86,58,0.04);
}

.action-icon {
    color: #6b7280;
}
.action-icon:hover {
    background: rgba(232,86,58,0.08);
    color: #E8563A;
}

.search-input {
    border-color: #e5e7eb;
    background: #fff;
    border-radius: 12px;
}
.search-input:focus {
    border-color: #E8563A;
    box-shadow: 0 0 0 3px rgba(232,86,58,0.10);
}

.status-badge {
    border-radius: 9999px;
    font-weight: 700;
    text-transform: capitalize;
}

:deep([role="dialog"]) {
    border-color: #F0EDE8;
}

:deep([role="dialog"] input),
:deep([role="dialog"] select),
:deep([role="dialog"] textarea) {
    border-color: #e5e7eb;
    background-color: #fff;
}

:deep([role="dialog"] input:focus),
:deep([role="dialog"] select:focus),
:deep([role="dialog"] textarea:focus) {
    border-color: #E8563A;
    box-shadow: 0 0 0 3px rgba(232,86,58,0.10);
    outline: none;
}
</style>
