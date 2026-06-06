<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowUpRight,
    DollarSign,
    Download,
    Eye,
    Film,
    Heart,
    Layers3,
    RefreshCw,
    ShoppingBag,
    ShoppingCart,
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
type TopVideoRevenue = { video_id: number; title: string; revenue: number; orders: number };
type VideoConversionRow = {
    video_id: number;
    title: string;
    revenue: number;
    orders: number;
    views: number;
    add_to_cart: number;
    checkouts: number;
    conversion_rate: number;
};
type CommerceRoi = {
    total_revenue: number;
    attributed_revenue: number;
    event_revenue: number;
    paid_orders: number;
    funnel: {
        video_views: number;
        add_to_cart: number;
        checkouts_completed: number;
        view_to_cart_rate: number;
        cart_to_checkout_rate: number;
        view_to_checkout_rate: number;
    };
};

type AbandonedCartRow = {
    cart_id: number;
    session_key: string;
    total_amount: number;
    currency: string;
    items_count: number;
    status: string;
    updated_at?: string;
    preview: string[];
};

type AbandonedCartsSummary = {
    count: number;
    recoverable_value: number;
    items: number;
    recent: AbandonedCartRow[];
};

type ExecutiveSnapshot = {
    revenue: number;
    orders: number;
    views: number;
    checkouts: number;
    abandoned_carts: number;
    abandoned_value: number;
};

type PeriodComparison = {
    current: ExecutiveSnapshot;
    previous: ExecutiveSnapshot;
    previous_from: string;
    previous_to: string;
    changes: {
        revenue_pct: number | null;
        orders_pct: number | null;
        views_pct: number | null;
        checkouts_pct: number | null;
        abandoned_carts_pct: number | null;
    };
};

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
    top_videos_by_revenue?: TopVideoRevenue[];
    commerce_roi?: CommerceRoi;
    video_conversion?: VideoConversionRow[];
    abandoned_carts?: AbandonedCartsSummary;
    period_comparison?: PeriodComparison;
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
const checkoutCompleted = computed(() => metric('checkout_completed'));
const addToCartCount = computed(() => metric('add_to_cart'));
const commerceRoi = computed(() => summary.value?.commerce_roi ?? null);
const topVideosByRevenue = computed(() => summary.value?.top_videos_by_revenue ?? []);
const videoConversion = computed(() => summary.value?.video_conversion ?? []);
const topRevenueMax = computed(() => Math.max(...topVideosByRevenue.value.map((v) => v.revenue), 1));
const abandonedCarts = computed(() => summary.value?.abandoned_carts ?? null);
const periodComparison = computed(() => summary.value?.period_comparison ?? null);
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

function fmtMoney(amount: number, currency = 'USD'): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(amount);
}

function fmtPctChange(value: number | null | undefined): string {
    if (value == null) {
        return '—';
    }

    const prefix = value > 0 ? '+' : '';

    return `${prefix}${value}%`;
}

function changeTone(value: number | null | undefined): string {
    if (value == null || value === 0) {
        return 'text-gray-500';
    }

    return value > 0 ? 'text-emerald-600' : 'text-red-600';
}

function exportReportCsv() {
    if (!summary.value) {
        return;
    }

    const rows: string[][] = [
        ['Analytics export'],
        ['Period', `${summary.value.from} to ${summary.value.to}`],
        [],
        ['Executive summary', 'Current', 'Previous period', 'Change %'],
    ];

    const pc = summary.value.period_comparison;

    if (pc) {
        rows.push(
            ['Revenue', String(pc.current.revenue), String(pc.previous.revenue), fmtPctChange(pc.changes.revenue_pct)],
            ['Paid orders', String(pc.current.orders), String(pc.previous.orders), fmtPctChange(pc.changes.orders_pct)],
            ['Video views', String(pc.current.views), String(pc.previous.views), fmtPctChange(pc.changes.views_pct)],
            ['Checkouts', String(pc.current.checkouts), String(pc.previous.checkouts), fmtPctChange(pc.changes.checkouts_pct)],
            ['Abandoned carts', String(pc.current.abandoned_carts), String(pc.previous.abandoned_carts), fmtPctChange(pc.changes.abandoned_carts_pct)],
        );
    }

    rows.push([], ['Top videos by revenue', 'Title', 'Revenue', 'Orders']);

    for (const video of summary.value.top_videos_by_revenue ?? []) {
        rows.push(['', video.title, String(video.revenue), String(video.orders)]);
    }

    rows.push([], ['Video conversion', 'Views', 'Cart', 'Checkouts', 'Revenue', 'Conversion %']);

    for (const row of summary.value.video_conversion ?? []) {
        rows.push([row.title, String(row.views), String(row.add_to_cart), String(row.checkouts), String(row.revenue), String(row.conversion_rate)]);
    }

    if (summary.value.abandoned_carts) {
        rows.push(
            [],
            ['Abandoned carts', String(summary.value.abandoned_carts.count)],
            ['Recoverable value', String(summary.value.abandoned_carts.recoverable_value)],
        );
    }

    const csv = rows
        .map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(','))
        .join('\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `analytics-${summary.value.from}-to-${summary.value.to}.csv`;
    link.click();
    URL.revokeObjectURL(url);
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
                    :disabled="!summary"
                    @click="exportReportCsv"
                >
                    <Download class="size-3.5" />
                    Export CSV
                </button>
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

            <!-- ── Executive ROI vs previous period ── -->
            <div v-if="periodComparison" class="mb-3 card">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-bold text-gray-900">Executive ROI report</p>
                        <p class="text-[11px] text-gray-400">
                            Compared with {{ periodComparison.previous_from }} — {{ periodComparison.previous_to }}
                        </p>
                    </div>
                    <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-[10px] font-semibold text-gray-600">
                        {{ daysWindow === 1 ? 'Today vs yesterday' : `Last ${daysWindow} days vs prior ${daysWindow} days` }}
                    </span>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <div
                        v-for="item in [
                            { label: 'Revenue', current: periodComparison.current.revenue, previous: periodComparison.previous.revenue, change: periodComparison.changes.revenue_pct, format: 'money' },
                            { label: 'Paid orders', current: periodComparison.current.orders, previous: periodComparison.previous.orders, change: periodComparison.changes.orders_pct, format: 'number' },
                            { label: 'Video views', current: periodComparison.current.views, previous: periodComparison.previous.views, change: periodComparison.changes.views_pct, format: 'number' },
                            { label: 'Checkouts', current: periodComparison.current.checkouts, previous: periodComparison.previous.checkouts, change: periodComparison.changes.checkouts_pct, format: 'number' },
                            { label: 'Abandoned carts', current: periodComparison.current.abandoned_carts, previous: periodComparison.previous.abandoned_carts, change: periodComparison.changes.abandoned_carts_pct, format: 'number' },
                        ]"
                        :key="item.label"
                        class="rounded-xl border border-gray-100 bg-gray-50/80 p-3"
                    >
                        <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400">{{ item.label }}</p>
                        <p class="mt-1 text-lg font-black text-gray-900">
                            {{ item.format === 'money' ? fmtMoney(Number(item.current)) : fmtN(Number(item.current)) }}
                        </p>
                        <p class="mt-0.5 text-[11px] text-gray-500">
                            Was {{ item.format === 'money' ? fmtMoney(Number(item.previous)) : fmtN(Number(item.previous)) }}
                        </p>
                        <p class="mt-1 flex items-center gap-1 text-xs font-semibold" :class="changeTone(item.change)">
                            <ArrowUpRight v-if="(item.change ?? 0) > 0" class="size-3.5" />
                            <ArrowDownRight v-else-if="(item.change ?? 0) < 0" class="size-3.5" />
                            {{ fmtPctChange(item.change) }}
                        </p>
                    </div>
                </div>
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

            <!-- ── Commerce ROI row ── -->
            <div v-if="commerceRoi" class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="card group relative overflow-hidden">
                    <div class="absolute right-0 top-0 h-full w-1 rounded-full bg-amber-500" />
                    <div class="flex items-start justify-between">
                        <p class="label">Revenue</p>
                        <DollarSign class="size-4 text-amber-500" />
                    </div>
                    <p class="kpi text-amber-600">{{ fmtMoney(commerceRoi.total_revenue) }}</p>
                    <p class="mt-auto text-[11px] text-gray-400">
                        {{ commerceRoi.paid_orders }} paid order{{ commerceRoi.paid_orders === 1 ? '' : 's' }}
                        · {{ fmtMoney(commerceRoi.attributed_revenue) }} attributed
                    </p>
                </div>

                <div class="card group relative overflow-hidden">
                    <div class="absolute right-0 top-0 h-full w-1 rounded-full bg-violet-500" />
                    <div class="flex items-start justify-between">
                        <p class="label">Checkouts</p>
                        <ShoppingBag class="size-4 text-violet-400" />
                    </div>
                    <p class="kpi text-violet-600">{{ fmtN(checkoutCompleted) }}</p>
                    <p class="mt-auto text-[11px] text-gray-400">checkout_completed events</p>
                </div>

                <div class="card group relative overflow-hidden">
                    <div class="absolute right-0 top-0 h-full w-1 rounded-full bg-cyan-500" />
                    <div class="flex items-start justify-between">
                        <p class="label">View → Cart</p>
                        <ShoppingCart class="size-4 text-cyan-400" />
                    </div>
                    <p class="kpi text-cyan-600">{{ commerceRoi.funnel.view_to_cart_rate }}%</p>
                    <p class="mt-auto text-[11px] text-gray-400">
                        {{ fmtN(addToCartCount) }} carts from {{ fmtN(commerceRoi.funnel.video_views) }} views
                    </p>
                </div>

                <div class="card group relative overflow-hidden">
                    <div class="absolute right-0 top-0 h-full w-1 rounded-full bg-indigo-500" />
                    <div class="flex items-start justify-between">
                        <p class="label">View → Checkout</p>
                        <TrendingUp class="size-4 text-indigo-400" />
                    </div>
                    <p class="kpi text-indigo-600">{{ commerceRoi.funnel.view_to_checkout_rate }}%</p>
                    <p class="mt-auto text-[11px] text-gray-400">
                        Cart → checkout {{ commerceRoi.funnel.cart_to_checkout_rate }}%
                    </p>
                </div>
            </div>

            <!-- ── Abandoned carts ── -->
            <div v-if="abandonedCarts" class="mt-3 card">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-bold text-gray-900">Abandoned carts</p>
                        <p class="text-[11px] text-gray-400">
                            Shoppers who added products but did not checkout (idle 1+ hour or marked abandoned)
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-lg font-black text-amber-600">{{ fmtMoney(abandonedCarts.recoverable_value) }}</p>
                        <p class="text-[11px] text-gray-500">{{ abandonedCarts.count }} carts · {{ abandonedCarts.items }} items</p>
                    </div>
                </div>

                <div v-if="abandonedCarts.recent.length === 0" class="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-gray-400">
                    No abandoned carts in this period yet.
                </div>

                <div v-else class="space-y-2">
                    <div
                        v-for="cart in abandonedCarts.recent"
                        :key="cart.cart_id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-100 bg-amber-50/40 px-3 py-2.5"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-800">
                                {{ cart.preview.join(', ') || 'Cart items' }}
                            </p>
                            <p class="text-[11px] text-gray-500">
                                {{ cart.items_count }} item{{ cart.items_count === 1 ? '' : 's' }}
                                · {{ cart.status }}
                                <span v-if="cart.updated_at"> · {{ shortDay(cart.updated_at.slice(0, 10)) }}</span>
                            </p>
                        </div>
                        <p class="shrink-0 text-sm font-bold text-amber-700">
                            {{ fmtMoney(cart.total_amount, cart.currency) }}
                        </p>
                    </div>
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

            <!-- ── Revenue + conversion tables ── -->
            <div class="mt-3 grid gap-3 xl:grid-cols-2">
                <div class="card">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-bold text-gray-900">Top Videos by Revenue</p>
                            <p class="text-[11px] text-gray-400">Paid in-app orders attributed to a video</p>
                        </div>
                        <Link href="/content" class="text-[11px] font-semibold text-[#E8563A] hover:underline">
                            All videos →
                        </Link>
                    </div>

                    <div v-if="topVideosByRevenue.length === 0" class="flex h-24 flex-col items-center justify-center gap-1 text-xs text-gray-400">
                        <DollarSign class="size-6 text-gray-200" />
                        Revenue will rank videos here after paid checkouts
                    </div>

                    <div v-else class="space-y-2">
                        <div
                            v-for="(video, idx) in topVideosByRevenue"
                            :key="`rev-${video.video_id}`"
                            class="flex items-center gap-3 rounded-xl p-2 transition-colors hover:bg-gray-50"
                        >
                            <div
                                class="flex size-7 shrink-0 items-center justify-center rounded-lg text-[11px] font-black"
                                :style="idx === 0 ? 'background:#f59e0b; color:white' : 'background:#f3f4f6; color:#374151'"
                            >
                                {{ idx + 1 }}
                            </div>
                            <span class="min-w-0 flex-1 truncate text-xs font-medium text-gray-800">
                                {{ video.title }}
                            </span>
                            <div class="shrink-0 text-right">
                                <p class="text-xs font-black text-amber-600">{{ fmtMoney(video.revenue) }}</p>
                                <p class="text-[10px] text-gray-400">{{ video.orders }} order{{ video.orders === 1 ? '' : 's' }}</p>
                                <div class="mt-0.5 h-1 w-16 overflow-hidden rounded-full bg-gray-100">
                                    <div
                                        class="h-full rounded-full bg-amber-500"
                                        :style="{ width: `${Math.round((video.revenue / topRevenueMax) * 100)}%` }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="mb-4">
                        <p class="text-sm font-bold text-gray-900">Video Conversion Funnel</p>
                        <p class="text-[11px] text-gray-400">Views → cart → checkout per video</p>
                    </div>

                    <div v-if="videoConversion.length === 0" class="flex h-24 flex-col items-center justify-center gap-1 text-xs text-gray-400">
                        <TrendingUp class="size-6 text-gray-200" />
                        Publish shoppable videos to see conversion stats
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full min-w-[420px] text-left text-xs">
                            <thead>
                                <tr class="border-b text-[10px] uppercase tracking-wide text-gray-400">
                                    <th class="pb-2 pr-3 font-semibold">Video</th>
                                    <th class="pb-2 px-2 text-right font-semibold">Views</th>
                                    <th class="pb-2 px-2 text-right font-semibold">Cart</th>
                                    <th class="pb-2 px-2 text-right font-semibold">Paid</th>
                                    <th class="pb-2 pl-2 text-right font-semibold">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="row in videoConversion.slice(0, 8)"
                                    :key="`conv-${row.video_id}`"
                                    class="border-b border-gray-50 last:border-0"
                                >
                                    <td class="py-2 pr-3">
                                        <p class="truncate font-medium text-gray-800">{{ row.title }}</p>
                                        <p class="text-[10px] text-gray-400">{{ row.conversion_rate }}% view → checkout</p>
                                    </td>
                                    <td class="px-2 py-2 text-right text-gray-600">{{ fmtN(row.views) }}</td>
                                    <td class="px-2 py-2 text-right text-gray-600">{{ fmtN(row.add_to_cart) }}</td>
                                    <td class="px-2 py-2 text-right font-semibold text-violet-600">{{ fmtN(row.checkouts) }}</td>
                                    <td class="py-2 pl-2 text-right font-bold text-amber-600">{{ fmtMoney(row.revenue) }}</td>
                                </tr>
                            </tbody>
                        </table>
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
