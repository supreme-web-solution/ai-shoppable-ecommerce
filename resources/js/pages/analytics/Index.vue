<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Eye,
    Film,
    Heart,
    Layers3,
    RefreshCw,
    ShoppingBag,
    Sparkles,
    TrendingUp,
    Users,
    Zap,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useAdminApi } from '@/composables/useAdminApi';

type MetricRow = { count: number; value: number };
type DailyPoint = { date: string; total: number };
type PlatformRow = { platform: string; total: number };
type TopVideo = { video_id: number; title: string; total: number };

type SummaryResponse = {
    team_id: number;
    from: string;
    to: string;
    data_source?: 'rollups' | 'events';
    metrics: Record<string, MetricRow>;
    groups?: Record<string, Record<string, MetricRow>>;
    top_events?: Record<string, MetricRow>;
    daily_series?: DailyPoint[];
    platform_breakdown?: PlatformRow[];
    top_videos?: TopVideo[];
    totals?: { events: number; unique_sessions: number };
    catalog?: {
        videos: number;
        published_videos: number;
        products: number;
        playlists: number;
        embeds: number;
        live_shows: number;
    };
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Analytics', href: '/analytics' },
        ],
    },
});

const { teamId, apiFetch, ensureTeam } = useAdminApi();

const loading = ref(false);
const errorText = ref('');
const summary = ref<SummaryResponse | null>(null);
const daysWindow = ref(7);
const hoveredBar = ref<number | null>(null);
const hoveredDonut = ref<number | null>(null);

const W = 560;
const H = 130;
const PAD = { t: 10, r: 8, b: 24, l: 8 };

const metric = (name: string): number => summary.value?.metrics?.[name]?.count ?? 0;

const totalEvents  = computed(() => summary.value?.totals?.events ?? 0);
const uniqueSessions = computed(() => summary.value?.totals?.unique_sessions ?? 0);
const videoViews   = computed(() => metric('video_view'));
const engagementTotal = computed(() =>
    metric('reaction') + metric('comment_submitted') + metric('share') + metric('save'),
);
const commerceTotal = computed(() =>
    metric('add_to_cart') + metric('checkout_started') + metric('checkout_completed') + metric('checkout_external_redirect'),
);
const engagementRate = computed(() =>
    videoViews.value > 0 ? Math.round((engagementTotal.value / videoViews.value) * 100) : 0,
);

const dailySeries = computed(() => summary.value?.daily_series ?? []);
const dailyMax    = computed(() => Math.max(...dailySeries.value.map((d) => d.total), 1));

/* ── SVG area chart ── */
const areaPath = computed(() => {
    const pts = dailySeries.value;

    if (pts.length < 2) {
return '';
}

    const cw = W - PAD.l - PAD.r;
    const ch = H - PAD.t - PAD.b;
    const xs = (i: number) => PAD.l + (i / (pts.length - 1)) * cw;
    const ys = (v: number) => PAD.t + ch - (v / dailyMax.value) * ch;
    const tension = 0.35;

    let d = `M ${xs(0)} ${ys(pts[0].total)}`;

    for (let i = 0; i < pts.length - 1; i++) {
        const x0 = xs(i), y0 = ys(pts[i].total);
        const x1 = xs(i + 1), y1 = ys(pts[i + 1].total);
        const dx = (x1 - x0) * tension;
        d += ` C ${x0 + dx} ${y0}, ${x1 - dx} ${y1}, ${x1} ${y1}`;
    }

    const lastX = xs(pts.length - 1);
    const bottom = PAD.t + ch;
    d += ` L ${lastX} ${bottom} L ${PAD.l} ${bottom} Z`;

    return d;
});

const linePath = computed(() => {
    const pts = dailySeries.value;

    if (pts.length < 2) {
return '';
}

    const cw = W - PAD.l - PAD.r;
    const ch = H - PAD.t - PAD.b;
    const xs = (i: number) => PAD.l + (i / (pts.length - 1)) * cw;
    const ys = (v: number) => PAD.t + ch - (v / dailyMax.value) * ch;
    const tension = 0.35;

    let d = `M ${xs(0)} ${ys(pts[0].total)}`;

    for (let i = 0; i < pts.length - 1; i++) {
        const x0 = xs(i), y0 = ys(pts[i].total);
        const x1 = xs(i + 1), y1 = ys(pts[i + 1].total);
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

    const cw = W - PAD.l - PAD.r;
    const ch = H - PAD.t - PAD.b;

    return pts.map((p, i) => ({
        x: PAD.l + (i / Math.max(pts.length - 1, 1)) * cw,
        y: PAD.t + ch - (p.total / dailyMax.value) * ch,
        total: p.total,
        date: p.date,
        label: shortDay(p.date),
    }));
});

/* ── Event bar chart (horizontal SVG) ── */
const eventBars = computed(() => {
    const entries = Object.entries(summary.value?.top_events ?? summary.value?.metrics ?? {});
    const max = Math.max(...entries.map(([, m]) => m.count), 1);

    return entries
        .sort(([, a], [, b]) => b.count - a.count)
        .slice(0, 7)
        .map(([name, row], i) => ({
            name,
            count: row.count,
            pct: Math.round((row.count / max) * 100),
            color: BAR_COLORS[i % BAR_COLORS.length],
        }));
});

const BAR_COLORS = [
    '#E8563A', '#F59E0B', '#10B981', '#6366F1', '#EC4899', '#14B8A6', '#8B5CF6',
];

/* ── Donut chart – engagement vs commerce vs live ── */
const donutSlices = computed(() => {
    const parts = [
        { label: 'Video Views', value: videoViews.value,      color: '#E8563A' },
        { label: 'Engagement',  value: engagementTotal.value,  color: '#F59E0B' },
        { label: 'Commerce',    value: commerceTotal.value,    color: '#10B981' },
    ];
    const total = Math.max(parts.reduce((s, p) => s + p.value, 0), 1);
    const r = 44, circ = 2 * Math.PI * r;
    let offset = 0;

    return parts.map((p) => {
        const pct = p.value / total;
        const dash = pct * circ;
        const slice = { ...p, dash, gap: circ - dash, offset: offset * circ / total, total };
        offset += p.value;

        return slice;
    });
});

const donutTotal = computed(() => totalEvents.value);

/* ── Top videos ── */
const topVideos   = computed(() => summary.value?.top_videos ?? []);
const topVideoMax = computed(() => Math.max(...topVideos.value.map((v) => v.total), 1));

/* ── Catalog ── */
const catalog = computed(() => summary.value?.catalog ?? null);

/* ── Helpers ── */
const today = computed(() => {
    const d = new Date();

    return {
        day: d.getDate(),
        weekday: d.toLocaleDateString('en-US', { weekday: 'short' }),
        month: d.toLocaleDateString('en-US', { month: 'long' }),
        year: d.getFullYear(),
    };
});

function formatDate(date: Date): string {
    return date.toISOString().slice(0, 10);
}
function metricLabel(key: string): string {
    return String(key).replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
function shortDay(iso: string): string {
    const d = new Date(iso + 'T12:00:00');

    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}
function fmtN(n: number): string {
    if (n >= 1_000_000) {
return (n / 1_000_000).toFixed(1) + 'M';
}

    if (n >= 1_000) {
return (n / 1_000).toFixed(1) + 'K';
}

    return String(n);
}

async function loadSummary() {
    loading.value = true;
    errorText.value = '';

    try {
        await ensureTeam();
        const to = new Date();
        const from = new Date();
        from.setDate(to.getDate() - daysWindow.value);
        summary.value = await apiFetch<SummaryResponse>(
            `/api/v1/analytics/summary?team_id=${teamId.value}&from=${formatDate(from)}&to=${formatDate(to)}`,
        );
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not load analytics.';
        summary.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(loadSummary);
</script>

<template>
    <Head title="Analytics" />

    <div class="min-h-full bg-[#F2EFEA] p-3 pb-10 md:p-5">

        <!-- ── Header ── -->
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div class="flex items-end gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#E8563A] shadow-lg shadow-orange-200">
                    <TrendingUp class="size-6 text-white" />
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-[#E8563A]">
                        {{ today.weekday }}, {{ today.month }} {{ today.day }}
                    </p>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900">Analytics</h1>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <div class="flex overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <button
                        v-for="d in [1, 7, 14, 30]"
                        :key="d"
                        type="button"
                        class="px-3 py-1.5 text-xs font-semibold transition-colors"
                        :class="daysWindow === d
                            ? 'bg-[#E8563A] text-white'
                            : 'text-gray-500 hover:bg-gray-50'"
                        @click="daysWindow = d; loadSummary()"
                    >
                        {{ d === 1 ? 'Today' : `${d}d` }}
                    </button>
                </div>
                <button
                    type="button"
                    class="flex items-center gap-1.5 rounded-xl bg-white px-3 py-1.5 text-xs font-semibold shadow-sm transition hover:bg-gray-50 disabled:opacity-40"
                    :disabled="loading"
                    @click="loadSummary"
                >
                    <RefreshCw class="size-3.5" :class="loading ? 'animate-spin' : ''" />
                    Refresh
                </button>
            </div>
        </div>

        <p v-if="errorText" class="mb-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ errorText }}
        </p>

        <!-- ── Loading ── -->
        <div v-if="loading && !summary" class="space-y-3">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="i in 4" :key="i" class="h-28 animate-pulse rounded-2xl bg-white/70" />
            </div>
            <div class="h-52 animate-pulse rounded-2xl bg-white/70" />
        </div>

        <template v-else-if="summary">

            <!-- empty hint -->
            <div v-if="totalEvents === 0" class="mb-4 flex items-center gap-3 rounded-2xl border border-dashed border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <Sparkles class="size-4 shrink-0 text-amber-500" />
                <span>No events yet. Embed a playlist on your store — player interactions will appear here in real time.</span>
                <Link href="/playlists" class="ml-auto shrink-0 font-semibold text-[#E8563A] hover:underline">Manage embeds →</Link>
            </div>

            <!-- ── KPI row ── -->
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">

                <div class="card group relative overflow-hidden">
                    <div class="absolute right-0 top-0 h-full w-1 rounded-full bg-[#E8563A]" />
                    <p class="label">Total Events</p>
                    <p class="kpi text-[#E8563A]">{{ fmtN(totalEvents) }}</p>
                    <div class="mt-auto flex items-center justify-between">
                        <p class="text-[11px] text-gray-400">{{ fmtN(uniqueSessions) }} sessions</p>
                        <div class="flex items-center gap-0.5 text-[11px] font-semibold text-emerald-600">
                            <ArrowUpRight class="size-3" />
                            Live
                        </div>
                    </div>
                </div>

                <div class="card group relative overflow-hidden">
                    <div class="absolute right-0 top-0 h-full w-1 rounded-full bg-blue-500" />
                    <div class="flex items-start justify-between">
                        <p class="label">Video Views</p>
                        <Eye class="size-4 text-blue-400" />
                    </div>
                    <p class="kpi text-blue-600">{{ fmtN(videoViews) }}</p>
                    <p class="mt-auto text-[11px] text-gray-400">video_view events</p>
                </div>

                <div class="card group relative overflow-hidden">
                    <div class="absolute right-0 top-0 h-full w-1 rounded-full bg-rose-500" />
                    <div class="flex items-start justify-between">
                        <p class="label">Engagement</p>
                        <Heart class="size-4 text-rose-400" />
                    </div>
                    <p class="kpi text-rose-600">{{ fmtN(engagementTotal) }}</p>
                    <p class="mt-auto text-[11px] text-gray-400">{{ engagementRate }}% engagement rate</p>
                </div>

                <div class="card group relative overflow-hidden">
                    <div class="absolute right-0 top-0 h-full w-1 rounded-full bg-emerald-500" />
                    <div class="flex items-start justify-between">
                        <p class="label">Commerce</p>
                        <ShoppingBag class="size-4 text-emerald-500" />
                    </div>
                    <p class="kpi text-emerald-600">{{ fmtN(commerceTotal) }}</p>
                    <p class="mt-auto text-[11px] text-gray-400">cart &amp; checkout actions</p>
                </div>
            </div>

            <!-- ── Area chart + donut ── -->
            <div class="mt-3 grid gap-3 xl:grid-cols-3">

                <!-- Area chart -->
                <div class="card xl:col-span-2">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-bold text-gray-900">Daily Activity</p>
                            <p class="text-[11px] text-gray-400">{{ summary.from }} — {{ summary.to }}</p>
                        </div>
                        <span class="rounded-full bg-[#E8563A]/10 px-2.5 py-0.5 text-[11px] font-semibold text-[#E8563A]">
                            {{ daysWindow }}d window
                        </span>
                    </div>

                    <div v-if="dailySeries.length === 0 || dailySeries.every((d) => d.total === 0)" class="flex h-32 flex-col items-center justify-center gap-2 rounded-xl bg-gray-50">
                        <TrendingUp class="size-8 text-gray-200" />
                        <p class="text-xs text-gray-400">No activity in this window yet</p>
                    </div>

                    <div v-else class="relative">
                        <svg
                            :viewBox="`0 0 ${W} ${H}`"
                            preserveAspectRatio="none"
                            class="h-36 w-full"
                        >
                            <defs>
                                <linearGradient id="area-grad" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#E8563A" stop-opacity="0.25" />
                                    <stop offset="100%" stop-color="#E8563A" stop-opacity="0.02" />
                                </linearGradient>
                            </defs>

                            <!-- Area fill -->
                            <path :d="areaPath" fill="url(#area-grad)" />

                            <!-- Line -->
                            <path :d="linePath" fill="none" stroke="#E8563A" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

                            <!-- Dots -->
                            <g v-for="(pt, i) in dotPoints" :key="i">
                                <circle
                                    :cx="pt.x" :cy="pt.y" r="4"
                                    fill="white" stroke="#E8563A" stroke-width="2"
                                    class="cursor-pointer transition-all"
                                    :class="hoveredBar === i ? 'r-6' : ''"
                                    @mouseenter="hoveredBar = i"
                                    @mouseleave="hoveredBar = null"
                                />
                                <!-- Tooltip -->
                                <g v-if="hoveredBar === i">
                                    <rect
                                        :x="Math.min(pt.x - 28, W - 68)"
                                        :y="pt.y - 32"
                                        width="60" height="22" rx="6"
                                        fill="#1a1a1a"
                                    />
                                    <text
                                        :x="Math.min(pt.x - 28, W - 68) + 30"
                                        :y="pt.y - 17"
                                        text-anchor="middle"
                                        fill="white"
                                        font-size="10"
                                        font-weight="700"
                                    >
                                        {{ pt.total }}
                                    </text>
                                </g>
                            </g>

                            <!-- X-axis labels (show subset to avoid crowding) -->
                            <g v-for="(pt, i) in dotPoints.filter((_, j) => j % Math.max(1, Math.ceil(dotPoints.length / 7)) === 0)" :key="i">
                                <text
                                    :x="pt.x" :y="H - 2"
                                    text-anchor="middle"
                                    fill="#9ca3af"
                                    font-size="9"
                                >{{ pt.label }}</text>
                            </g>
                        </svg>
                    </div>
                </div>

                <!-- Donut chart -->
                <div class="card flex flex-col">
                    <div class="mb-4 flex items-center justify-between">
                        <p class="text-sm font-bold text-gray-900">Event Breakdown</p>
                        <Zap class="size-4 text-amber-400" />
                    </div>

                    <div class="flex flex-1 flex-col items-center justify-center gap-4">
                        <div class="relative flex items-center justify-center">
                            <svg width="140" height="140" viewBox="0 0 140 140">
                                <circle cx="70" cy="70" r="44" fill="none" stroke="#f3f4f6" stroke-width="14" />
                                <g v-for="(slice, i) in donutSlices" :key="i">
                                    <circle
                                        cx="70" cy="70" r="44"
                                        fill="none"
                                        :stroke="slice.color"
                                        stroke-width="14"
                                        stroke-linecap="round"
                                        :stroke-dasharray="`${slice.dash - 2} ${slice.gap + 2}`"
                                        :stroke-dashoffset="`-${slice.offset}`"
                                        transform="rotate(-90 70 70)"
                                        class="cursor-pointer transition-all duration-200"
                                        :style="hoveredDonut === i ? 'stroke-width:17' : ''"
                                        @mouseenter="hoveredDonut = i"
                                        @mouseleave="hoveredDonut = null"
                                    />
                                </g>
                                <text x="70" y="66" text-anchor="middle" font-size="20" font-weight="900" fill="#111">{{ fmtN(donutTotal) }}</text>
                                <text x="70" y="80" text-anchor="middle" font-size="9" fill="#9ca3af">total</text>
                            </svg>
                        </div>

                        <div class="w-full space-y-2">
                            <div
                                v-for="(slice, i) in donutSlices"
                                :key="i"
                                class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1 transition-colors"
                                :class="hoveredDonut === i ? 'bg-gray-50' : ''"
                                @mouseenter="hoveredDonut = i"
                                @mouseleave="hoveredDonut = null"
                            >
                                <span class="size-2.5 shrink-0 rounded-full" :style="{ background: slice.color }" />
                                <span class="flex-1 text-xs text-gray-600">{{ slice.label }}</span>
                                <span class="text-xs font-bold text-gray-900">{{ fmtN(slice.value) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Bar chart row ── -->
            <div class="mt-3 grid gap-3 xl:grid-cols-2">

                <!-- Horizontal bar chart: top events -->
                <div class="card">
                    <p class="mb-4 text-sm font-bold text-gray-900">Top Events</p>

                    <div v-if="eventBars.length === 0" class="flex h-24 items-center justify-center text-xs text-gray-400">
                        No events yet
                    </div>

                    <div v-else class="space-y-2.5">
                        <div
                            v-for="(bar, i) in eventBars"
                            :key="bar.name"
                            class="group flex items-center gap-3"
                        >
                            <div
                                class="flex size-6 shrink-0 items-center justify-center rounded-md text-[10px] font-black text-white"
                                :style="{ background: bar.color }"
                            >
                                {{ i + 1 }}
                            </div>
                            <p class="w-32 shrink-0 truncate text-xs font-medium text-gray-700">
                                {{ metricLabel(bar.name) }}
                            </p>
                            <div class="relative h-5 flex-1 overflow-hidden rounded-lg bg-gray-100">
                                <div
                                    class="absolute inset-y-0 left-0 flex items-center rounded-lg transition-all duration-500"
                                    :style="{ width: `${Math.max(bar.pct, 4)}%`, background: bar.color, opacity: '0.85' }"
                                />
                            </div>
                            <span class="w-10 shrink-0 text-right text-xs font-bold text-gray-900">
                                {{ fmtN(bar.count) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Top videos leaderboard -->
                <div class="card">
                    <div class="mb-4 flex items-center justify-between">
                        <p class="text-sm font-bold text-gray-900">Top Videos</p>
                        <Link href="/content" class="text-[11px] font-semibold text-[#E8563A] hover:underline">
                            All videos →
                        </Link>
                    </div>

                    <div v-if="topVideos.length === 0" class="flex h-24 flex-col items-center justify-center gap-1 text-xs text-gray-400">
                        <Film class="size-6 text-gray-200" />
                        Views will rank videos here
                    </div>

                    <div v-else class="space-y-2">
                        <div
                            v-for="(video, idx) in topVideos"
                            :key="video.video_id"
                            class="flex items-center gap-3 rounded-xl p-2 transition-colors hover:bg-gray-50"
                        >
                            <div
                                class="flex size-7 shrink-0 items-center justify-center rounded-lg text-[11px] font-black"
                                :style="idx === 0
                                    ? 'background:#E8563A; color:white'
                                    : idx === 1
                                        ? 'background:#f3f4f6; color:#374151'
                                        : 'background:#f9fafb; color:#9ca3af'"
                            >
                                {{ idx + 1 }}
                            </div>
                            <span class="min-w-0 flex-1 truncate text-xs font-medium text-gray-800">
                                {{ video.title }}
                            </span>
                            <div class="shrink-0 text-right">
                                <p class="text-xs font-black text-[#E8563A]">{{ fmtN(video.total) }}</p>
                                <div class="mt-0.5 h-1 w-16 overflow-hidden rounded-full bg-gray-100">
                                    <div
                                        class="h-full rounded-full bg-[#E8563A]"
                                        :style="{ width: `${Math.round((video.total / topVideoMax) * 100)}%` }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Metric groups ── -->
            <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="card in [
                        { label: 'Video', icon: Film, keys: ['video_view','video_complete','watch_time'], color: '#E8563A', bg: '#fff5f3' },
                        { label: 'Engagement', icon: Heart, keys: ['reaction','comment_submitted','share','save'], color: '#F43F5E', bg: '#fff1f2' },
                        { label: 'Commerce', icon: ShoppingBag, keys: ['add_to_cart','checkout_started','checkout_completed','checkout_external_redirect'], color: '#10B981', bg: '#f0fdf4' },
                        { label: 'Live', icon: Users, keys: ['live_show_view','live_reaction_spike'], color: '#8B5CF6', bg: '#f5f3ff' },
                    ]"
                    :key="card.label"
                    class="card"
                    :style="{ borderTop: `3px solid ${card.color}` }"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="flex size-7 items-center justify-center rounded-lg" :style="{ background: card.bg }">
                                <component :is="card.icon" class="size-3.5" :style="{ color: card.color }" />
                            </div>
                            <p class="text-sm font-bold text-gray-900">{{ card.label }}</p>
                        </div>
                        <p class="text-lg font-black" :style="{ color: card.color }">
                            {{ fmtN(card.keys.reduce((s, k) => s + metric(k), 0)) }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <div
                            v-for="k in card.keys"
                            :key="k"
                            class="flex items-center gap-2"
                        >
                            <p class="min-w-0 flex-1 truncate text-[11px] text-gray-500">{{ metricLabel(k) }}</p>
                            <div class="relative h-1.5 w-16 overflow-hidden rounded-full bg-gray-100">
                                <div
                                    class="absolute inset-y-0 left-0 rounded-full transition-all duration-500"
                                    :style="{
                                        width: `${Math.round((metric(k) / Math.max(card.keys.reduce((s, j) => s + metric(j), 0), 1)) * 100)}%`,
                                        background: card.color,
                                    }"
                                />
                            </div>
                            <span class="w-8 shrink-0 text-right text-[11px] font-bold text-gray-800">{{ fmtN(metric(k)) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Catalog strip ── -->
            <div v-if="catalog" class="mt-3 grid gap-3 grid-cols-2 sm:grid-cols-3 xl:grid-cols-6">
                <div
                    v-for="item in [
                        { label: 'Videos', value: catalog.videos, icon: Film },
                        { label: 'Published', value: catalog.published_videos, icon: Eye },
                        { label: 'Products', value: catalog.products, icon: ShoppingBag },
                        { label: 'Playlists', value: catalog.playlists, icon: Layers3 },
                        { label: 'Embeds', value: catalog.embeds, icon: Zap },
                        { label: 'Live shows', value: catalog.live_shows, icon: Users },
                    ]"
                    :key="item.label"
                    class="card flex flex-col items-center gap-1 py-3 text-center"
                >
                    <component :is="item.icon" class="size-4 text-gray-300" />
                    <p class="text-xl font-black text-gray-900">{{ item.value }}</p>
                    <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ item.label }}</p>
                </div>
            </div>

            <!-- data source badge -->
            <p class="mt-4 text-center text-[10px] text-gray-300">
                Data from {{ summary.data_source === 'events' ? 'live events' : 'rollup cache' }}
                · {{ summary.from }} → {{ summary.to }}
            </p>
        </template>

        <div v-else-if="!loading" class="rounded-2xl border border-dashed border-gray-200 bg-white p-12 text-center text-sm text-gray-400">
            Could not load analytics. Check your connection and try again.
        </div>
    </div>
</template>

<style scoped>
.card {
    background: #ffffff;
    border-radius: 20px;
    padding: 1.25rem;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04), 0 4px 20px rgba(0,0,0,0.06);
}

.label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #9ca3af;
    margin-bottom: 0.25rem;
}

.kpi {
    font-size: 2rem;
    font-weight: 900;
    line-height: 1;
    margin: 0.5rem 0;
    letter-spacing: -0.02em;
}
</style>
