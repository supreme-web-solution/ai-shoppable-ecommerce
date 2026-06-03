<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
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
    MessageSquare,
    Package,
    Plus,
    PlusCircle,
    Search,
    Trash2,
    Upload,
    UserRound,
    Users,
    XCircle,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
import { useAdminApi } from '@/composables/useAdminApi';

// ── Types ──────────────────────────────────────────────────────────────────

type VideoOption = {
    id: number;
    title: string;
    thumbnail_url?: string | null;
    playback_url?: string | null;
};

type ProductOption = {
    id: number;
    title: string;
    image_url?: string | null;
    price?: string | null;
    sale_price?: string | null;
    currency?: string;
};

type KnowledgeSource = {
    title: string;
    content: string;
};

type WebinarSettings = {
    host_name?: string | null;
    thumbnail_url?: string | null;
    video_url?: string | null;
    source_type?: 'ai' | 'upload' | 'url' | null;
    registration_title?: string | null;
    registration_description?: string | null;
    room_title?: string | null;
    chat_enabled?: boolean;
    ai_assistant_enabled?: boolean;
    knowledge_base_text?: string | null;
    knowledge_sources?: KnowledgeSource[];
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
    host_name?: string | null;
    thumbnail_url?: string | null;
    video_url?: string | null;
    source_type?: 'ai' | 'upload' | 'url' | null;
    registration_title?: string | null;
    registration_description?: string | null;
    room_title?: string | null;
    chat_enabled?: boolean;
    ai_assistant_enabled?: boolean;
    registration_url?: string;
    room_url?: string;
    registrants_count?: number;
    messages_count?: number;
    views_count?: number;
    featured_products?: ProductOption[];
    video?: VideoOption | null;
};

type WebinarAttendee = {
    id: number;
    full_name: string;
    email: string;
    registered_at?: string | null;
    last_joined_at?: string | null;
    join_count?: number;
};

type WebinarFormSettings = {
    host_name: string;
    thumbnail_url: string;
    video_url: string;
    source_type: 'ai' | 'upload' | 'url';
    registration_title: string;
    registration_description: string;
    room_title: string;
    chat_enabled: boolean;
    ai_assistant_enabled: boolean;
    knowledge_sources: KnowledgeSource[];
};

type WebinarForm = {
    title: string;
    description: string;
    video_id: number | null;
    starts_at: string;
    ends_at: string;
    status: 'scheduled' | 'live' | 'ended' | 'cancelled';
    featured_product_ids: number[];
    settings: WebinarFormSettings;
};

type TabItem = {
    id: string;
    label: string;
    icon: unknown;
};

// ── Tab definitions ────────────────────────────────────────────────────────

const CREATE_TABS: TabItem[] = [
    { id: 'basics', label: 'Basics', icon: Layers },
    { id: 'video', label: 'Video', icon: Film },
    { id: 'registration', label: 'Registration', icon: UserRound },
    { id: 'offers', label: 'Offers', icon: Package },
    { id: 'ai', label: 'AI Assistant', icon: Bot },
];

const EDIT_TABS: TabItem[] = [
    { id: 'basics', label: 'Basics', icon: Layers },
    { id: 'video', label: 'Video', icon: Film },
    { id: 'registration', label: 'Registration', icon: UserRound },
    { id: 'attendees', label: 'Attendees', icon: Users },
    { id: 'chat', label: 'Chat & Automation', icon: MessageSquare },
    { id: 'offers', label: 'Offers', icon: Package },
    { id: 'ai', label: 'AI Assistant', icon: Bot },
];

// ── defineOptions ──────────────────────────────────────────────────────────

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Webinars', href: '/live-shows' },
        ],
    },
});

// ── Composables ────────────────────────────────────────────────────────────

const { getList, postJson, putJson, apiFetch, uploadFile, deleteResource, ensureTeam } = useAdminApi();

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
const attendees = ref<WebinarAttendee[]>([]);
const loadingAttendees = ref(false);

const createModalOpen = ref(false);
const editModalOpen = ref(false);
const editingWebinar = ref<WebinarItem | null>(null);

const activeTab = ref('basics');

const selectedVideoFile = ref<File | null>(null);
const previewVideoUrl = ref<string | null>(null);
const uploadingVideo = ref(false);
const videoUploadError = ref('');

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
        featured_product_ids: [],
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
        },
    };
}

// ── Computed ───────────────────────────────────────────────────────────────

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

const currentTabs = computed(() => (editModalOpen.value ? EDIT_TABS : CREATE_TABS));

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
        const upload = await uploadFile('/api/v1/admin/videos/upload', selectedVideoFile.value);
        const title =
            form.value.title.trim() ||
            selectedVideoFile.value.name.replace(/\.[^.]+$/, '');
        const payload = await postJson<unknown>('/api/v1/admin/videos', {
            title,
            description: form.value.description.trim() || null,
            source: 'uploaded',
            visibility: 'public',
            local_file_path: upload.local_file_path,
        });
        const created = unwrapVideo(payload);

        if (!created?.id) {
            throw new Error('Video was uploaded but could not be linked.');
        }

        const videoPayload = await getList<VideoOption>('/api/v1/admin/videos');
        videos.value = videoPayload.data ?? [];
        form.value.video_id = created.id;
        form.value.settings.source_type = 'upload';

        if (created.playback_url) {
            form.value.settings.video_url = created.playback_url;
        }

        selectedVideoFile.value = null;
    } catch (error) {
        videoUploadError.value = error instanceof Error ? error.message : 'Video upload failed.';
    } finally {
        uploadingVideo.value = false;
    }
}

function toggleProduct(productId: number) {
    const idx = form.value.featured_product_ids.indexOf(productId);

    if (idx === -1) {
form.value.featured_product_ids.push(productId);
} else {
form.value.featured_product_ids.splice(idx, 1);
}
}

function validateForm(): string | null {
    if (!form.value.title.trim()) {
return 'Webinar title is required.';
}

    if (!form.value.starts_at) {
return 'Start date is required.';
}

    if (form.value.ends_at && form.value.starts_at) {
        const s = new Date(form.value.starts_at);
        const e = new Date(form.value.ends_at);

        if (!Number.isNaN(s.getTime()) && !Number.isNaN(e.getTime()) && e < s) {
return 'End date must be after start date.';
}
    }

    return null;
}

function buildPayload() {
    return {
        title: form.value.title.trim(),
        description: form.value.description.trim() || null,
        video_id: form.value.video_id,
        starts_at: form.value.starts_at,
        ends_at: form.value.ends_at || null,
        status: form.value.status,
        is_premiere: false,
        featured_product_ids: form.value.featured_product_ids,
        settings: {
            host_name: form.value.settings.host_name.trim() || null,
            thumbnail_url: selectedThumbnailUrl() || null,
            video_url: selectedVideoUrl() || null,
            source_type: form.value.settings.source_type,
            registration_title: form.value.settings.registration_title.trim() || null,
            registration_description: form.value.settings.registration_description.trim() || null,
            room_title: form.value.settings.room_title.trim() || null,
            chat_enabled: form.value.settings.chat_enabled,
            ai_assistant_enabled: form.value.settings.ai_assistant_enabled,
            knowledge_sources: form.value.settings.knowledge_sources,
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

async function loadAttendees(webinarId: number) {
    loadingAttendees.value = true;
    attendees.value = [];

    try {
        const payload = await apiFetch<{ data: WebinarAttendee[] }>(
            `/api/v1/admin/live-shows/${webinarId}/attendees`,
        );
        attendees.value = payload.data ?? [];
    } catch (error) {
        modalError.value = error instanceof Error ? error.message : 'Could not load attendees.';
    } finally {
        loadingAttendees.value = false;
    }
}

// ── CRUD ───────────────────────────────────────────────────────────────────

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

    try {
        await postJson('/api/v1/admin/live-shows', buildPayload());
        createModalOpen.value = false;
        await loadData();
    } catch (error) {
        modalError.value = error instanceof Error ? error.message : 'Could not create webinar.';
    } finally {
        saving.value = false;
    }
}

async function openEditModal(item: WebinarItem) {
    editingWebinar.value = item;
    const s = item.settings ?? {};
    form.value = {
        title: item.title ?? '',
        description: item.description ?? '',
        video_id: item.video_id ?? null,
        starts_at: normalizeDateTimeLocal(item.starts_at),
        ends_at: normalizeDateTimeLocal(item.ends_at),
        status: item.status,
        featured_product_ids: (item.featured_products ?? []).map((p) => p.id),
        settings: {
            host_name: item.host_name ?? s.host_name ?? '',
            thumbnail_url: item.thumbnail_url ?? s.thumbnail_url ?? '',
            video_url: item.video_url ?? s.video_url ?? '',
            source_type: item.source_type ?? s.source_type ?? 'upload',
            registration_title: item.registration_title ?? s.registration_title ?? 'Join Webinar',
            registration_description:
                item.registration_description ??
                s.registration_description ??
                'Enter your details to join. Registered attendees get instant access.',
            room_title: item.room_title ?? s.room_title ?? 'In-call chat',
            chat_enabled: item.chat_enabled ?? s.chat_enabled ?? true,
            ai_assistant_enabled: item.ai_assistant_enabled ?? s.ai_assistant_enabled ?? false,
            knowledge_sources: Array.isArray(s.knowledge_sources) ? [...s.knowledge_sources] : [],
        },
    };
    modalError.value = '';
    productSearch.value = '';
    addingSource.value = false;
    sourceForm.value = { title: '', content: '' };
    expandedSourceIndex.value = null;
    clearSelectedVideoFile();
    activeTab.value = 'basics';
    editModalOpen.value = true;
    await loadAttendees(item.id);
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
        modalError.value = error instanceof Error ? error.message : 'Could not update webinar.';
    } finally {
        saving.value = false;
    }
}

async function removeWebinar(item: WebinarItem) {
    if (!window.confirm(`Delete webinar "${item.title}"?`)) {
return;
}

    deleting.value = item.id;

    try {
        await deleteResource(`/api/v1/admin/live-shows/${item.id}`);
        await loadData();
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not delete webinar.';
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

onMounted(loadData);

onBeforeUnmount(() => {
    if (previewVideoUrl.value?.startsWith('blob:')) {
        URL.revokeObjectURL(previewVideoUrl.value);
    }

    if (copiedLinkTimeout !== null) {
        window.clearTimeout(copiedLinkTimeout);
    }
});
</script>

<template>
    <Head title="Webinars" />

    <!-- ═══════════════════════════════════════ Main list page ══════════════════════════════════════ -->
    <div class="live-root flex h-full flex-1 flex-col gap-5 p-4 md:p-5">

        <!-- Header -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <div class="page-icon flex size-10 shrink-0 items-center justify-center rounded-xl">
                    <Video class="size-5 text-white" />
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-[#E8563A]">Live Commerce</p>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900">Webinars</h1>
                    <p class="mt-0.5 text-sm text-gray-500">
                    Manage on-demand webinar funnels, registration flows, room chat, and offer assignments.
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" class="ghost-btn" :disabled="loading" @click="loadData">
                    {{ loading ? 'Refreshing...' : 'Refresh' }}
                </Button>
                <Button size="sm" class="cta-btn" @click="openCreateModal">
                    <PlusCircle class="mr-1.5 size-4" />
                    New Webinar
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
                    <p class="font-bold text-gray-900">All Webinars</p>
                    <p class="text-xs text-gray-500">
                        {{ filteredWebinars.length }} shown / {{ webinars.length }} total
                    </p>
                </div>
                <div class="relative w-full max-w-xs">
                    <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <Input v-model="search" placeholder="Search webinars..." class="search-input pl-9" />
                </div>
            </div>

            <div v-if="loading" class="space-y-2 p-4">
                <Skeleton v-for="n in 6" :key="n" class="h-14 rounded-lg" />
            </div>

            <div
                v-else-if="filteredWebinars.length === 0"
                class="px-4 py-14 text-center text-sm text-gray-500"
            >
                {{ search ? 'No webinars match your search.' : 'No webinars yet — create your first one.' }}
            </div>

            <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-[#FAF8F5] text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-3 text-left">Webinar</th>
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
                                        title="Edit webinar"
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
                                                title="Webinar links"
                                            >
                                                <Link2 class="size-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" class="w-56">
                                            <DropdownMenuLabel>Webinar links</DropdownMenuLabel>
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
                                                    Open webinar room
                                                </a>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>

                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="text-red-400 hover:bg-red-50 hover:text-red-600"
                                        title="Delete webinar"
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
        <DialogContent class="flex max-h-[min(92dvh,calc(100vh-2rem))] flex-col gap-0 overflow-hidden p-0 sm:max-w-4xl">
            <!-- Header -->
            <DialogHeader class="shrink-0 border-b px-6 py-4">
                <DialogTitle>Create Webinar</DialogTitle>
                <DialogDescription>
                    Configure host, video, registration page, offers, and AI assistant.
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
            <div class="flex-1 overflow-y-auto px-6 py-5">

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
                        <p class="mb-3 text-sm font-semibold">Basics <span class="font-normal text-muted-foreground">— Configure the core webinar details</span></p>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label>Webinar Title <span class="text-destructive">*</span></Label>
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
                                placeholder="Tell attendees what this webinar covers..."
                            />
                        </div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div class="space-y-1.5">
                                <Label>Status</Label>
                                <select v-model="form.status" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                    <option value="scheduled">Scheduled</option>
                                    <option value="live">Live</option>
                                    <option value="ended">Ended</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Starts At <span class="text-destructive">*</span></Label>
                                <Input v-model="form.starts_at" type="datetime-local" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Ends At</Label>
                                <Input v-model="form.ends_at" type="datetime-local" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Video ── -->
                <div v-show="activeTab === 'video'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-3 text-sm font-semibold">Video <span class="font-normal text-muted-foreground">— Upload or link the webinar video</span></p>

                        <div class="mb-4 rounded-xl border-2 border-dashed border-[#E8563A]/30 bg-[#E8563A]/5 p-4">
                            <p class="mb-1 text-sm font-semibold">Upload video file</p>
                            <p class="mb-3 text-xs text-muted-foreground">MP4, MOV, or WebM. File is saved to your video library and linked to this webinar.</p>
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
                                <Label>Video Source Type</Label>
                                <select v-model="form.settings.source_type" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                    <option value="upload">Uploaded Video</option>
                                    <option value="ai">AI Generated Video</option>
                                    <option value="url">Direct URL</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Linked Library Video</Label>
                                <select v-model="form.video_id" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                    <option :value="null">No linked video</option>
                                    <option v-for="video in videos" :key="video.id" :value="video.id">{{ video.title }}</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Thumbnail URL / PNG Path</Label>
                                <Input v-model="form.settings.thumbnail_url" placeholder="https://.../thumb.png" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Video URL Override</Label>
                                <Input v-model="form.settings.video_url" placeholder="https://.../video.mp4" />
                            </div>
                        </div>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-md border bg-muted/30 p-3">
                                <p class="mb-2 text-xs font-medium text-muted-foreground">Thumbnail Preview</p>
                                <div class="flex h-32 items-center justify-center overflow-hidden rounded-md border bg-muted">
                                    <img v-if="selectedThumbnailUrl()" :src="selectedThumbnailUrl()" class="h-full w-full object-cover">
                                    <ImageOff v-else class="size-5 text-muted-foreground" />
                                </div>
                            </div>
                            <div class="rounded-md border bg-muted/30 p-3">
                                <p class="mb-2 text-xs font-medium text-muted-foreground">Video preview</p>
                                <div class="flex aspect-video items-center justify-center overflow-hidden rounded-md border bg-black/90">
                                    <video
                                        v-if="selectedVideoUrl()"
                                        :src="selectedVideoUrl()"
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
                            Registration and room URLs will be generated after the webinar is created.
                        </div>
                    </div>
                </div>

                <!-- ── Offers ── -->
                <div v-show="activeTab === 'offers'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-3 text-sm font-semibold">Offers <span class="font-normal text-muted-foreground">— Assign products shown during the webinar room</span></p>
                        <div class="relative mb-3">
                            <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input v-model="productSearch" placeholder="Search products..." class="pl-9" />
                        </div>
                        <div v-if="products.length === 0" class="rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground">
                            No products yet. Add products in the Products section.
                        </div>
                        <div v-else class="max-h-64 space-y-1.5 overflow-y-auto rounded-md border p-2">
                            <button
                                v-for="product in filteredProducts"
                                :key="product.id"
                                type="button"
                                :class="[
                                    'flex w-full items-center gap-3 rounded-md px-2 py-2 text-left transition-colors',
                                    form.featured_product_ids.includes(product.id) ? 'bg-[#E8563A]/5 ring-1 ring-[#E8563A]/30' : 'hover:bg-muted/40',
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
                                    form.featured_product_ids.includes(product.id) ? 'border-[#E8563A] bg-[#E8563A] text-white' : 'border-muted-foreground/30',
                                ]">
                                    <svg v-if="form.featured_product_ids.includes(product.id)" class="size-3" viewBox="0 0 12 12" fill="none">
                                        <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                            </button>
                        </div>
                        <p v-if="form.featured_product_ids.length" class="mt-2 text-xs text-muted-foreground">
                            {{ form.featured_product_ids.length }} offer(s) assigned
                        </p>
                    </div>
                </div>

                <!-- ── AI Assistant ── -->
                <div v-show="activeTab === 'ai'" class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="mb-3 text-sm font-semibold">AI Assistant <span class="font-normal text-muted-foreground">— Auto-reply to attendee messages using your knowledge base</span></p>
                        <label class="flex items-start gap-3 rounded-md border bg-muted/20 p-3">
                            <input v-model="form.settings.ai_assistant_enabled" type="checkbox" class="mt-0.5 h-4 w-4 rounded">
                            <div>
                                <p class="text-sm font-medium">Enable AI assistant auto-replies</p>
                                <p class="text-xs text-muted-foreground">
                                    When enabled, AI will respond to attendee chat messages using the knowledge sources below.
                                </p>
                            </div>
                        </label>
                    </div>

                    <div class="rounded-lg border p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <div>
                                <p class="text-sm font-semibold">Knowledge Base Sources</p>
                                <p class="text-xs text-muted-foreground">
                                    Up to 3 sources. AI uses these to generate replies.
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="rounded-full border px-2 py-0.5 text-xs font-medium">
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
                                            @click="toggleSourceExpanded(idx)"
                                        >
                                            <Eye class="size-3.5" />
                                            {{ expandedSourceIndex === idx ? 'Hide' : 'Review' }}
                                            <ChevronUp v-if="expandedSourceIndex === idx" class="size-3" />
                                            <ChevronDown v-else class="size-3" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                            @click="removeKnowledgeSource(idx)"
                                        >
                                            <Trash2 class="size-3.5" />
                                        </Button>
                                    </div>
                                </div>
                                <div v-if="expandedSourceIndex !== idx" class="px-4 py-2">
                                    <p class="truncate text-xs text-muted-foreground">{{ source.content.slice(0, 120) }}{{ source.content.length > 120 ? '...' : '' }}</p>
                                </div>
                                <div v-else class="border-t bg-muted/10 px-4 py-3">
                                    <p class="mb-1 text-xs font-medium text-muted-foreground">Full content</p>
                                    <pre class="max-h-48 overflow-y-auto whitespace-pre-wrap text-xs text-foreground">{{ source.content }}</pre>
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
                            class="rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground"
                        >
                            No knowledge sources yet. Add up to 3 sources for AI-assisted replies.
                        </div>
                    </div>

                    <div class="rounded-lg border p-4">
                        <label class="flex items-start gap-3">
                            <input v-model="form.settings.chat_enabled" type="checkbox" class="mt-0.5 h-4 w-4 rounded">
                            <div>
                                <p class="text-sm font-medium">Enable attendee chat in the room</p>
                                <p class="text-xs text-muted-foreground">Allow attendees to send messages during the webinar.</p>
                            </div>
                        </label>
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
                            {{ saving ? 'Creating...' : 'Create Webinar' }}
                        </Button>
                    </div>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- ═══════════════════════════════════════ Edit Dialog ══════════════════════════════════════ -->
    <Dialog v-model:open="editModalOpen">
        <DialogContent class="flex max-h-[min(92dvh,calc(100vh-2rem))] flex-col gap-0 overflow-hidden p-0 sm:max-w-5xl">

            <!-- Header -->
            <div class="shrink-0 border-b px-6 py-4">
                <p v-if="editingWebinar" class="truncate text-xs text-muted-foreground">{{ editingWebinar.title }}</p>
                <h2 class="text-lg font-semibold">Edit Webinar</h2>
                <p class="text-sm text-muted-foreground">Update settings, automation, and publishing options.</p>
            </div>

            <!-- Stats row -->
            <div v-if="editingWebinar" class="grid shrink-0 grid-cols-2 gap-px border-b bg-border sm:grid-cols-3 lg:grid-cols-6">
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">Registrants</p>
                    <p class="text-base font-bold">{{ attendees.length || editingWebinar.registrants_count || 0 }}</p>
                </div>
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">Views</p>
                    <p class="text-base font-bold">{{ editingWebinar.views_count || 0 }}</p>
                </div>
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">Messages</p>
                    <p class="text-base font-bold">{{ editingWebinar.messages_count || 0 }}</p>
                </div>
                <div class="bg-background px-3 py-2.5">
                    <p class="text-xs text-muted-foreground">Offers</p>
                    <p class="text-base font-bold">{{ form.featured_product_ids.length }}</p>
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
                        v-for="tab in EDIT_TABS"
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
                <span>Step {{ activeTabIndex + 1 }} of {{ EDIT_TABS.length }}</span>
                <span class="font-semibold text-foreground">{{ EDIT_TABS[activeTabIndex]?.label }}</span>
                <span>{{ activeTabIndex + 1 }} / {{ EDIT_TABS.length }} completed</span>
            </div>

            <!-- Tab content -->
            <div class="flex-1 overflow-y-auto px-6 py-5">

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
                                    <option value="scheduled">Scheduled</option>
                                    <option value="live">Live</option>
                                    <option value="ended">Ended</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Starts At <span class="text-destructive">*</span></Label>
                                <Input v-model="form.starts_at" type="datetime-local" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Ends At</Label>
                                <Input v-model="form.ends_at" type="datetime-local" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Video ── -->
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
                                <Label>Video Source Type</Label>
                                <select v-model="form.settings.source_type" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                    <option value="upload">Uploaded Video</option>
                                    <option value="ai">AI Generated Video</option>
                                    <option value="url">Direct URL</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Linked Library Video</Label>
                                <select v-model="form.video_id" class="w-full rounded-md border bg-background px-3 py-2 text-sm">
                                    <option :value="null">No linked video</option>
                                    <option v-for="video in videos" :key="video.id" :value="video.id">{{ video.title }}</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <Label>Thumbnail URL / PNG Path</Label>
                                <Input v-model="form.settings.thumbnail_url" placeholder="https://.../thumb.png" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Video URL Override</Label>
                                <Input v-model="form.settings.video_url" placeholder="https://.../video.mp4" />
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
                                    <video
                                        v-if="selectedVideoUrl()"
                                        :src="selectedVideoUrl()"
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
                    <div class="rounded-lg border p-4">
                        <p class="mb-3 text-sm font-semibold">Registered Attendees
                            <span class="ml-2 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                {{ attendees.length }}
                            </span>
                        </p>
                        <div v-if="loadingAttendees" class="space-y-2">
                            <Skeleton v-for="n in 5" :key="n" class="h-12 rounded-md" />
                        </div>
                        <div v-else-if="attendees.length === 0" class="rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground">
                            No registrations yet. Share the registration link to get attendees.
                        </div>
                        <div v-else class="max-h-72 space-y-2 overflow-y-auto">
                            <div
                                v-for="attendee in attendees"
                                :key="attendee.id"
                                class="flex items-center justify-between rounded-md border px-3 py-2"
                            >
                                <div>
                                    <p class="text-sm font-medium">{{ attendee.full_name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ attendee.email }}</p>
                                </div>
                                <div class="text-right text-xs text-muted-foreground">
                                    <p>{{ attendee.join_count ?? 0 }} join(s)</p>
                                    <p>{{ formatDate(attendee.registered_at) }}</p>
                                </div>
                            </div>
                        </div>
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
                        <p class="mb-3 text-sm font-semibold">Offers</p>
                        <div class="relative mb-3">
                            <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input v-model="productSearch" placeholder="Search products..." class="pl-9" />
                        </div>
                        <div v-if="products.length === 0" class="rounded-lg border border-dashed py-8 text-center text-sm text-muted-foreground">
                            No products yet.
                        </div>
                        <div v-else class="max-h-72 space-y-1.5 overflow-y-auto rounded-md border p-2">
                            <button
                                v-for="product in filteredProducts"
                                :key="product.id"
                                type="button"
                                :class="[
                                    'flex w-full items-center gap-3 rounded-md px-2 py-2 text-left transition-colors',
                                    form.featured_product_ids.includes(product.id) ? 'bg-[#E8563A]/5 ring-1 ring-[#E8563A]/30' : 'hover:bg-muted/40',
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
                                    form.featured_product_ids.includes(product.id) ? 'border-[#E8563A] bg-[#E8563A] text-white' : 'border-muted-foreground/30',
                                ]">
                                    <svg v-if="form.featured_product_ids.includes(product.id)" class="size-3" viewBox="0 0 12 12" fill="none">
                                        <path d="M2 6l3 3 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                            </button>
                        </div>
                        <p v-if="form.featured_product_ids.length" class="mt-2 text-xs text-muted-foreground">
                            {{ form.featured_product_ids.length }} offer(s) assigned
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
                                            @click="toggleSourceExpanded(idx)"
                                        >
                                            <Eye class="size-3.5" />
                                            {{ expandedSourceIndex === idx ? 'Hide' : 'Review' }}
                                            <ChevronUp v-if="expandedSourceIndex === idx" class="size-3" />
                                            <ChevronDown v-else class="size-3" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                            @click="removeKnowledgeSource(idx)"
                                        >
                                            <Trash2 class="size-3.5" />
                                        </Button>
                                    </div>
                                </div>
                                <div v-if="expandedSourceIndex !== idx" class="px-4 py-2">
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
                        <Button variant="ghost" @click="editModalOpen = false">Cancel</Button>
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
