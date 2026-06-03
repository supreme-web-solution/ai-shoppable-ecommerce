<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    Clapperboard,
    Eye,
    Film,
    Layers3,
    Package,
    PlusCircle,
    Sparkles,
    TrendingUp,
    Upload,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminApi } from '@/composables/useAdminApi';
import { dashboard } from '@/routes';

type DailyPoint = { date: string; total: number };
type TopVideo = { video_id: number; title: string; total: number };

type OverviewResponse = {
    counts: {
        videos: number;
        published_videos: number;
        products: number;
        playlists: number;
        embeds: number;
        live_shows: number;
    };
    metrics_7d: Record<string, number>;
    daily_series?: DailyPoint[];
    top_videos?: TopVideo[];
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const { apiFetch, ensureTeam, teamId } = useAdminApi();
const page = usePage();
const loading = ref(false);
const overview = ref<OverviewResponse | null>(null);
const hoveredDay = ref<number | null>(null);

const CHART_W = 520;
const CHART_H = 120;
const CHART_PAD = { t: 8, r: 6, b: 22, l: 6 };

const userName = computed(() => {
    const u = page.props.auth?.user as { name?: string } | null;

    return u?.name?.split(' ')[0] ?? 'there';
});

const today = computed(() => {
    const d = new Date();

    return {
        day: d.getDate(),
        weekday: d.toLocaleDateString('en-US', { weekday: 'short' }),
        month: d.toLocaleDateString('en-US', { month: 'long' }),
    };
});

const publishedPct = computed(() => {
    if (!overview.value) {
return 0;
}

    const c = overview.value.counts;

    return c.videos > 0 ? Math.round((c.published_videos / c.videos) * 100) : 0;
});

const donutDash = computed(() => {
    const r = 46;
    const circ = 2 * Math.PI * r;

    return `${(publishedPct.value / 100) * circ} ${circ}`;
});

const totalActivity = computed(() =>
    Object.values(overview.value?.metrics_7d ?? {}).reduce((a, b) => a + b, 0),
);

const videoViews = computed(() => overview.value?.metrics_7d?.video_view ?? 0);

const dailySeries = computed(() => overview.value?.daily_series ?? []);

const dailyMax = computed(() => Math.max(...dailySeries.value.map((d) => d.total), 1));

const areaPath = computed(() => {
    const pts = dailySeries.value;

    if (pts.length < 2) {
return '';
}

    const cw = CHART_W - CHART_PAD.l - CHART_PAD.r;
    const ch = CHART_H - CHART_PAD.t - CHART_PAD.b;
    const xs = (i: number) => CHART_PAD.l + (i / (pts.length - 1)) * cw;
    const ys = (v: number) => CHART_PAD.t + ch - (v / dailyMax.value) * ch;
    const tension = 0.35;

    let d = `M ${xs(0)} ${ys(pts[0].total)}`;

    for (let i = 0; i < pts.length - 1; i++) {
        const x0 = xs(i);
        const y0 = ys(pts[i].total);
        const x1 = xs(i + 1);
        const y1 = ys(pts[i + 1].total);
        const dx = (x1 - x0) * tension;
        d += ` C ${x0 + dx} ${y0}, ${x1 - dx} ${y1}, ${x1} ${y1}`;
    }

    const lastX = xs(pts.length - 1);
    const bottom = CHART_PAD.t + ch;
    d += ` L ${lastX} ${bottom} L ${CHART_PAD.l} ${bottom} Z`;

    return d;
});

const linePath = computed(() => {
    const pts = dailySeries.value;

    if (pts.length < 2) {
return '';
}

    const cw = CHART_W - CHART_PAD.l - CHART_PAD.r;
    const ch = CHART_H - CHART_PAD.t - CHART_PAD.b;
    const xs = (i: number) => CHART_PAD.l + (i / (pts.length - 1)) * cw;
    const ys = (v: number) => CHART_PAD.t + ch - (v / dailyMax.value) * ch;
    const tension = 0.35;

    let d = `M ${xs(0)} ${ys(pts[0].total)}`;

    for (let i = 0; i < pts.length - 1; i++) {
        const x0 = xs(i);
        const y0 = ys(pts[i].total);
        const x1 = xs(i + 1);
        const y1 = ys(pts[i + 1].total);
        const dx = (x1 - x0) * tension;
        d += ` C ${x0 + dx} ${y0}, ${x1 - dx} ${y1}, ${x1} ${y1}`;
    }

    return d;
});

const dotPoints = computed(() => {
    const pts = dailySeries.value;

    if (!pts.length) {
return [];
}

    const cw = CHART_W - CHART_PAD.l - CHART_PAD.r;
    const ch = CHART_H - CHART_PAD.t - CHART_PAD.b;

    return pts.map((p, i) => ({
        x: CHART_PAD.l + (i / Math.max(pts.length - 1, 1)) * cw,
        y: CHART_PAD.t + ch - (p.total / dailyMax.value) * ch,
        total: p.total,
        label: shortDay(p.date),
    }));
});

const topVideos = computed(() => overview.value?.top_videos ?? []);

const topVideoMax = computed(() => Math.max(...topVideos.value.map((v) => v.total), 1));

const quickActions = [
    { href: '/content/create', icon: Upload, label: 'Upload Video' },
    { href: '/content/create', icon: Sparkles, label: 'AI Video' },
    { href: '/live-shows', icon: Clapperboard, label: 'Live Show' },
    { href: '/playlists', icon: Layers3, label: 'Playlist' },
    { href: '/products', icon: Package, label: 'Products' },
    { href: '/analytics', icon: BarChart3, label: 'Analytics' },
];

function shortDay(iso: string) {
    const d = new Date(iso + 'T12:00:00');

    return d.toLocaleDateString('en-US', { weekday: 'short' });
}

function fmtN(n: number) {
    if (n >= 1_000) {
return `${(n / 1_000).toFixed(1)}K`;
}

    return String(n);
}

async function loadOverview() {
    loading.value = true;

    try {
        await ensureTeam();
        overview.value = await apiFetch<OverviewResponse>(`/api/v1/admin/overview?team_id=${teamId.value}`);
    } catch {
        overview.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(loadOverview);
</script>

<template>
    <Head title="Dashboard" />

    <div class="db-root min-h-full p-3 pb-10 md:p-5">

        <!-- Header -->
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-end gap-3">
                <div class="flex size-12 items-center justify-center rounded-2xl bg-coral shadow-coral">
                    <TrendingUp class="size-6 text-white" />
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-coral">
                        {{ today.weekday }}, {{ today.month }} {{ today.day }}
                    </p>
                    <h1 class="text-2xl font-black tracking-tight text-foreground">
                        Hey, {{ userName }} 👋
                    </h1>
                    <p class="text-xs text-muted-foreground">Your video commerce studio at a glance</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Link href="/content/create" class="cta-pill flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white shadow-coral">
                    <PlusCircle class="size-4" />
                    Create New Video
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6" /></svg>
                </Link>
                <button
                    type="button"
                    :disabled="loading"
                    class="flex size-9 items-center justify-center rounded-full bg-white shadow-card transition hover:bg-gray-50 disabled:opacity-50"
                    @click="loadOverview"
                >
                    <svg :class="['size-4 text-muted-foreground', loading && 'animate-spin']" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56" /></svg>
                </button>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading && !overview" class="space-y-3">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Skeleton v-for="n in 4" :key="n" class="h-24 rounded-2xl" />
            </div>
            <Skeleton class="h-44 rounded-2xl" />
            <div class="grid gap-3 xl:grid-cols-3">
                <Skeleton class="h-52 rounded-2xl xl:col-span-2" />
                <Skeleton class="h-52 rounded-2xl" />
            </div>
        </div>

        <template v-else-if="overview">
            <!-- KPI row -->
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="db-card kpi-card">
                    <div class="kpi-accent bg-coral" />
                    <div class="flex items-start justify-between">
                        <p class="kpi-label">Shoppable Videos</p>
                        <Film class="size-4 text-coral/70" />
                    </div>
                    <p class="kpi-value text-coral">{{ overview.counts.videos }}</p>
                    <p class="kpi-sub">{{ overview.counts.published_videos }} published · {{ publishedPct }}% live</p>
                    <Link href="/content" class="kpi-link">View all videos →</Link>
                </div>

                <div class="db-card kpi-card">
                    <div class="kpi-accent bg-blue-500" />
                    <div class="flex items-start justify-between">
                        <p class="kpi-label">7-Day Events</p>
                        <BarChart3 class="size-4 text-blue-400" />
                    </div>
                    <p class="kpi-value text-blue-600">{{ fmtN(totalActivity) }}</p>
                    <p class="kpi-sub">engagement &amp; commerce</p>
                    <Link href="/analytics" class="kpi-link">Full analytics →</Link>
                </div>

                <div class="db-card kpi-card">
                    <div class="kpi-accent bg-rose-500" />
                    <div class="flex items-start justify-between">
                        <p class="kpi-label">Video Views</p>
                        <Eye class="size-4 text-rose-400" />
                    </div>
                    <p class="kpi-value text-rose-600">{{ fmtN(videoViews) }}</p>
                    <p class="kpi-sub">last 7 days</p>
                    <Link href="/analytics" class="kpi-link">View trends →</Link>
                </div>

                <div class="db-card kpi-card">
                    <div class="kpi-accent bg-emerald-500" />
                    <div class="flex items-start justify-between">
                        <p class="kpi-label">Catalogue</p>
                        <Package class="size-4 text-emerald-500" />
                    </div>
                    <p class="kpi-value text-emerald-600">{{ overview.counts.products }}</p>
                    <p class="kpi-sub">{{ overview.counts.playlists }} playlists · {{ overview.counts.embeds }} embeds</p>
                    <Link href="/products" class="kpi-link">Manage products →</Link>
                </div>
            </div>

            <!-- Charts row -->
            <div class="mt-3 grid gap-3 xl:grid-cols-3">
                <!-- Daily activity -->
                <div class="db-card p-4 xl:col-span-2">
                    <div class="mb-3 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-bold text-foreground">Daily Activity</p>
                            <p class="text-[11px] text-muted-foreground">Events over the last 7 days</p>
                        </div>
                        <span class="rounded-full bg-coral/10 px-2.5 py-0.5 text-[11px] font-semibold text-coral">7d</span>
                    </div>

                    <div
                        v-if="dailySeries.length === 0 || dailySeries.every((d) => d.total === 0)"
                        class="flex h-28 flex-col items-center justify-center gap-2 rounded-xl bg-muted/40"
                    >
                        <TrendingUp class="size-7 text-muted-foreground/30" />
                        <p class="text-xs text-muted-foreground">Publish an embed to start seeing activity</p>
                        <Link href="/playlists" class="text-xs font-semibold text-coral hover:underline">Manage embeds →</Link>
                    </div>

                    <div v-else class="relative">
                        <svg
                            :viewBox="`0 0 ${CHART_W} ${CHART_H}`"
                            preserveAspectRatio="none"
                            class="h-32 w-full"
                        >
                            <defs>
                                <linearGradient id="db-area-grad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#E8563A" stop-opacity="0.22" />
                                    <stop offset="100%" stop-color="#E8563A" stop-opacity="0.02" />
                                </linearGradient>
                            </defs>
                            <path :d="areaPath" fill="url(#db-area-grad)" />
                            <path :d="linePath" fill="none" stroke="#E8563A" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                            <g v-for="(pt, i) in dotPoints" :key="i">
                                <circle
                                    :cx="pt.x"
                                    :cy="pt.y"
                                    r="3.5"
                                    fill="white"
                                    stroke="#E8563A"
                                    stroke-width="2"
                                    class="cursor-pointer"
                                    @mouseenter="hoveredDay = i"
                                    @mouseleave="hoveredDay = null"
                                />
                                <g v-if="hoveredDay === i">
                                    <rect
                                        :x="Math.min(pt.x - 24, CHART_W - 56)"
                                        :y="pt.y - 28"
                                        width="52"
                                        height="20"
                                        rx="5"
                                        fill="#1a1a1a"
                                    />
                                    <text
                                        :x="Math.min(pt.x - 24, CHART_W - 56) + 26"
                                        :y="pt.y - 15"
                                        text-anchor="middle"
                                        fill="white"
                                        font-size="10"
                                        font-weight="700"
                                    >
                                        {{ pt.total }}
                                    </text>
                                </g>
                            </g>
                            <g
                                v-for="(pt, i) in dotPoints.filter((_, j) => j % Math.max(1, Math.ceil(dotPoints.length / 7)) === 0)"
                                :key="`lbl-${i}`"
                            >
                                <text
                                    :x="pt.x"
                                    :y="CHART_H - 4"
                                    text-anchor="middle"
                                    fill="#9ca3af"
                                    font-size="9"
                                >
                                    {{ pt.label }}
                                </text>
                            </g>
                        </svg>
                    </div>
                </div>

                <!-- Side: publish + live -->
                <div class="flex flex-col gap-3">
                    <div class="db-card flex flex-1 flex-col items-center justify-center gap-2 p-4">
                        <p class="w-full text-left text-xs font-semibold uppercase tracking-widest text-muted-foreground">Publish Rate</p>
                        <div class="relative flex size-24 items-center justify-center">
                            <svg class="absolute inset-0 -rotate-90" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="46" fill="none" stroke="#F0EDE8" stroke-width="8" />
                                <circle
                                    cx="50"
                                    cy="50"
                                    r="46"
                                    fill="none"
                                    stroke="#E8563A"
                                    stroke-width="8"
                                    stroke-linecap="round"
                                    :stroke-dasharray="donutDash"
                                />
                            </svg>
                            <div class="z-10 text-center">
                                <p class="text-xl font-black text-foreground">{{ publishedPct }}%</p>
                                <p class="text-[9px] text-muted-foreground">published</p>
                            </div>
                        </div>
                        <p class="text-center text-[11px] text-muted-foreground">
                            {{ overview.counts.published_videos }} of {{ overview.counts.videos }} videos live
                        </p>
                    </div>

                    <div class="db-card flex flex-col gap-2 p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-widest text-muted-foreground">Live Shows</p>
                                <p class="mt-1 text-2xl font-black text-foreground">{{ overview.counts.live_shows }}</p>
                            </div>
                            <div class="icon-chip icon-chip-coral">
                                <Clapperboard class="size-5" />
                            </div>
                        </div>
                        <Link href="/live-shows" class="text-xs font-semibold text-coral hover:underline">View schedule →</Link>
                    </div>
                </div>
            </div>

            <!-- Bottom row -->
            <div class="mt-3 grid gap-3 xl:grid-cols-2">
                <div class="db-card p-4">
                        <p class="mb-3 text-sm font-bold text-foreground">Quick Actions</p>
                        <div class="grid grid-cols-3 gap-2">
                            <Link
                                v-for="action in quickActions"
                                :key="action.href + action.label"
                                :href="action.href"
                                class="qa-item group flex flex-col items-center gap-1.5 rounded-xl p-2.5 transition-all hover:bg-coral/5"
                            >
                                <div class="qa-icon flex size-8 items-center justify-center rounded-full bg-coral/12 text-coral transition-transform group-hover:scale-110 group-hover:bg-coral group-hover:text-white">
                                    <component :is="action.icon" class="size-3.5" />
                                </div>
                                <span class="text-center text-[10px] font-semibold leading-tight text-muted-foreground group-hover:text-coral">
                                    {{ action.label }}
                                </span>
                            </Link>
                        </div>
                    </div>

                <!-- Top videos + quick actions -->
                <div class="flex flex-col gap-3">
                    <div class="db-card p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <p class="text-sm font-bold text-foreground">Top Videos</p>
                            <Link href="/content" class="text-[11px] font-semibold text-coral hover:underline">All →</Link>
                        </div>

                        <div v-if="topVideos.length === 0" class="flex h-20 flex-col items-center justify-center gap-1 text-xs text-muted-foreground">
                            <Film class="size-5 opacity-40" />
                            Views will rank videos here
                        </div>

                        <div v-else class="space-y-2">
                            <div
                                v-for="(video, idx) in topVideos"
                                :key="video.video_id"
                                class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-muted/40"
                            >
                                <div
                                    class="flex size-6 shrink-0 items-center justify-center rounded-md text-[10px] font-black"
                                    :class="idx === 0 ? 'bg-coral text-white' : 'bg-muted text-muted-foreground'"
                                >
                                    {{ idx + 1 }}
                                </div>
                                <span class="min-w-0 flex-1 truncate text-xs font-medium">{{ video.title }}</span>
                                <div class="shrink-0 text-right">
                                    <p class="text-xs font-black text-coral">{{ fmtN(video.total) }}</p>
                                    <div class="mt-0.5 h-1 w-12 overflow-hidden rounded-full bg-gray-100">
                                        <div
                                            class="h-full rounded-full bg-coral"
                                            :style="{ width: `${Math.round((video.total / topVideoMax) * 100)}%` }"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                   
                </div>
            </div>
        </template>

        <div v-else class="db-card mt-4 p-8 text-center text-sm text-muted-foreground">
            Could not load overview. Check your connection and refresh.
        </div>
    </div>
</template>

<style scoped>
.db-root {
    background-color: #f2efea;
    min-height: 100%;
}

.db-card {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 4px 16px rgba(0, 0, 0, 0.06);
}

.bg-coral { background-color: #e8563a; }
.text-coral { color: #e8563a; }

.shadow-card {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04), 0 4px 16px rgba(0, 0, 0, 0.06);
}

.shadow-coral {
    box-shadow: 0 4px 20px rgba(232, 86, 58, 0.35);
}

.cta-pill {
    background: #e8563a;
    transition: all 0.2s;
}

.cta-pill:hover {
    background: #d44a2f;
    box-shadow: 0 8px 24px rgba(232, 86, 58, 0.4);
    transform: translateY(-1px);
}

.kpi-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 1rem 1rem 0.85rem;
    min-height: 7.5rem;
    overflow: hidden;
}

.kpi-accent {
    position: absolute;
    right: 0;
    top: 0;
    height: 100%;
    width: 4px;
    border-radius: 4px 0 0 4px;
}

.kpi-label {
    font-size: 0.65rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--muted-foreground);
}

.kpi-value {
    font-size: 1.75rem;
    font-weight: 900;
    line-height: 1.1;
}

.kpi-sub {
    margin-top: auto;
    font-size: 0.65rem;
    color: var(--muted-foreground);
}

.kpi-link {
    margin-top: 0.35rem;
    font-size: 0.65rem;
    font-weight: 600;
    color: #e8563a;
}

.kpi-link:hover {
    text-decoration: underline;
}

.icon-chip {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    flex-shrink: 0;
}

.icon-chip-coral {
    background: rgba(232, 86, 58, 0.12);
    color: #e8563a;
}
</style>
