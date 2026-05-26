<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    Clapperboard,
    Film,
    Layers3,
    Package,
    PlusCircle,
    Sparkles,
    Upload,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminApi } from '@/composables/useAdminApi';
import { dashboard } from '@/routes';

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
    if (!overview.value) return 0;
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

const metricMax = computed(() =>
    Math.max(...Object.values(overview.value?.metrics_7d ?? {}), 1),
);

const metricEntries = computed(() =>
    Object.entries(overview.value?.metrics_7d ?? {}),
);

const quickActions = [
    { href: '/content/create', icon: Upload, label: 'Upload Video' },
    { href: '/content/create', icon: Sparkles, label: 'AI Video' },
    { href: '/live-shows', icon: Clapperboard, label: 'Live Show' },
    { href: '/playlists', icon: Layers3, label: 'Playlist' },
    { href: '/products', icon: Package, label: 'Products' },
    { href: '/analytics', icon: BarChart3, label: 'Analytics' },
];

function metricLabel(key: string) {
    return String(key).replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
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

    <div class="db-root min-h-full p-3 md:p-4">

        <!-- ── Top greeting strip ── -->
        <div class="mb-6 mt-3 flex flex-wrap items-center gap-2">
            <!-- Date chip -->
            <div class="date-chip flex items-center gap-2 rounded-2xl bg-white px-4 py-2 shadow-card">
                <span class="text-3xl font-black text-foreground">{{ today.day }}</span>
                <div class="text-xs leading-tight text-muted-foreground">
                    <p class="font-semibold text-foreground">{{ today.weekday }},</p>
                    <p>{{ today.month }}</p>
                </div>
            </div>

            <!-- Primary CTA -->
            <Link href="/content/create" class="cta-pill flex items-center gap-2 rounded-full px-4 py-2 text-sm font-bold text-white shadow-coral">
                <PlusCircle class="size-4" />
                Create New Video
                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </Link>

            <!-- Refresh pill -->
            <button
                type="button"
                :disabled="loading"
                class="flex size-9 items-center justify-center rounded-full bg-white shadow-card transition hover:bg-gray-50 disabled:opacity-50"
                @click="loadOverview"
            >
                <svg :class="['size-4 text-muted-foreground', loading && 'animate-spin']" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
            </button>

            <!-- Greeting -->
            <div class="ml-auto hidden text-right lg:block">
                <p class="text-base font-black text-foreground">Hey, {{ userName }}! 👋</p>
                <p class="text-xs text-muted-foreground">Welcome to your video commerce studio.</p>
            </div>
        </div>

        <!-- ── Skeleton ── -->
        <div v-if="loading" class="bento">
            <Skeleton v-for="n in 8" :key="n" class="rounded-2xl" :style="{ gridArea: ['a','b','c','d','e','f','g','h'][n-1], height: '120px' }" />
        </div>

        <!-- ── Bento grid ── -->
        <div v-else-if="overview" class="bento">

            <!-- A: Shoppable Videos large card -->
            <div style="grid-area:a" class="db-card flex flex-col justify-between p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-widest text-muted-foreground">Shoppable Videos</p>
                        <p class="mt-0.5 text-4xl font-black text-foreground">{{ overview.counts.videos }}</p>
                    </div>
                    <div class="icon-chip icon-chip-coral">
                        <Film class="size-5" />
                    </div>
                </div>
                <div class="mt-2 space-y-1.5">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-muted-foreground">Published</span>
                        <span class="font-bold text-foreground">{{ overview.counts.published_videos }}</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full bg-coral transition-all" :style="{ width: `${publishedPct}%` }" />
                    </div>
                    <p class="text-xs text-muted-foreground">{{ publishedPct }}% of your videos are live</p>
                </div>
                <Link href="/content" class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-coral hover:underline">
                    View all videos
                    <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                </Link>
            </div>

            <!-- B: Views metric (top-right of A) -->
            <div style="grid-area:b" class="db-card flex flex-col justify-between p-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-widest text-muted-foreground">7-day Events</p>
                    <span class="rounded-full bg-coral/10 px-2 py-0.5 text-[11px] font-bold text-coral">Weekly</span>
                </div>
                <div>
                    <p class="text-2xl font-black text-foreground">{{ totalActivity.toLocaleString() }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">engagement & commerce events</p>
                </div>
                <Link href="/analytics" class="text-xs font-semibold text-coral hover:underline">View on chart mode →</Link>
            </div>

            <!-- C: Products metric -->
            <div style="grid-area:c" class="db-card flex flex-col justify-between p-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-semibold uppercase tracking-widest text-muted-foreground">Products</p>
                    <span class="rounded-full bg-coral/10 px-2 py-0.5 text-[11px] font-bold text-coral">Catalogue</span>
                </div>
                <div>
                    <p class="text-2xl font-black text-foreground">{{ overview.counts.products }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">tagged to your videos</p>
                </div>
                <Link href="/products" class="text-xs font-semibold text-coral hover:underline">Manage products →</Link>
            </div>

            <!-- D: Growth donut -->
            <div style="grid-area:d" class="db-card flex flex-col items-center justify-center gap-2 p-4">
                <div class="relative flex size-20 items-center justify-center">
                    <svg class="absolute inset-0 -rotate-90" viewBox="0 0 100 100">
                        <circle cx="50" cy="50" r="46" fill="none" stroke="#F0EDE8" stroke-width="8" />
                        <circle
                            cx="50" cy="50" r="46"
                            fill="none"
                            stroke="#E8563A"
                            stroke-width="8"
                            stroke-linecap="round"
                            :stroke-dasharray="donutDash"
                        />
                    </svg>
                    <div class="z-10 text-center">
                        <p class="text-lg font-black text-foreground">{{ publishedPct }}%</p>
                        <p class="text-[9px] text-muted-foreground">published</p>
                    </div>
                </div>
                <div class="text-center">
                    <p class="text-xs font-bold text-foreground">Publish Rate</p>
                    <p class="text-[11px] text-muted-foreground">{{ overview.counts.published_videos }} of {{ overview.counts.videos }} live</p>
                </div>
            </div>

            <!-- E: Playlists + Embeds -->
            <div style="grid-area:e" class="db-card flex flex-col justify-between p-4">
                <p class="text-xs font-semibold uppercase tracking-widest text-muted-foreground">Embed Feeds</p>
                <div>
                    <p class="text-3xl font-black text-foreground">{{ overview.counts.embeds }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">across {{ overview.counts.playlists }} playlist{{ overview.counts.playlists !== 1 ? 's' : '' }}</p>
                </div>
                <div class="flex gap-1.5">
                    <div class="ring-item" :style="{ width: '38px', height: '38px', boxShadow: '0 0 0 6px rgba(232,86,58,0.08)' }">
                        <span class="text-xs font-bold text-coral">{{ overview.counts.playlists }}</span>
                    </div>
                    <div class="ring-item" :style="{ width: '28px', height: '28px', boxShadow: '0 0 0 6px rgba(232,86,58,0.05)' }">
                        <span class="text-[9px] font-bold text-foreground/50">{{ overview.counts.embeds }}</span>
                    </div>
                </div>
                <Link href="/playlists" class="text-xs font-semibold text-coral hover:underline">Manage playlists →</Link>
            </div>

            <!-- F: Activity bar chart -->
            <div style="grid-area:f" class="db-card p-4">
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-sm font-bold text-foreground">Activity Manager</p>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-foreground">7 days</span>
                </div>

                <div v-if="metricEntries.length === 0" class="flex h-20 flex-col items-center justify-center gap-1.5">
                    <BarChart3 class="size-6 text-gray-200" />
                    <p class="text-xs text-muted-foreground">No events tracked yet. Publish an embed to start.</p>
                </div>

                <div v-else class="space-y-2">
                    <div v-for="([name, count], idx) in metricEntries.slice(0, 5)" :key="name" class="flex items-center gap-2">
                        <p class="w-24 shrink-0 truncate text-[11px] text-muted-foreground">{{ metricLabel(name) }}</p>
                        <div class="flex flex-1 items-center gap-2">
                            <div class="relative h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="idx === 0 ? 'bg-coral' : idx === 1 ? 'bg-amber-400' : idx === 2 ? 'bg-emerald-500' : idx === 3 ? 'bg-blue-500' : 'bg-violet-500'"
                                    :style="{ width: `${Math.round((count / metricMax) * 100)}%` }"
                                />
                            </div>
                            <span class="w-7 text-right text-[11px] font-bold text-foreground">{{ count }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- G: Live shows -->
            <div style="grid-area:g" class="db-card flex flex-col justify-between p-4">
                <div class="flex items-start justify-between">
                    <p class="text-xs font-semibold uppercase tracking-widest text-muted-foreground">Live Shows</p>
                    <div class="icon-chip icon-chip-sm icon-chip-coral">
                        <Clapperboard class="size-3.5" />
                    </div>
                </div>
                <p class="text-3xl font-black text-foreground">{{ overview.counts.live_shows }}</p>
                <p class="text-xs text-muted-foreground">webinars scheduled & past</p>
                <Link href="/live-shows" class="text-xs font-semibold text-coral hover:underline">View schedule →</Link>
            </div>

            <!-- H: Quick actions -->
            <div style="grid-area:h" class="db-card p-4">
                <p class="mb-2 text-sm font-bold text-foreground">Quick Actions</p>
                <div class="grid grid-cols-3 gap-1.5 pb-3">
                    <Link
                        v-for="(action, i) in quickActions"
                        :key="action.href + action.label"
                        :href="action.href"
                        class="qa-item group flex flex-col items-center gap-1 rounded-xl p-2 transition-all"
                        :class="i === 0 ? 'qa-item-primary' : ''"
                    >
                        <div :class="['qa-icon flex size-7 items-center justify-center rounded-lg transition-transform group-hover:scale-110', i === 0 ? 'bg-coral text-white' : 'bg-gray-100 text-foreground/70']">
                            <component :is="action.icon" class="size-3.5" />
                        </div>
                        <span class="text-[10px] font-semibold text-center leading-tight" :class="i === 0 ? 'text-coral' : 'text-muted-foreground'">{{ action.label }}</span>
                    </Link>
                </div>
            </div>
        </div>

        <!-- Error -->
        <div v-else class="mt-4 rounded-2xl border border-dashed bg-white p-8 text-center text-sm text-muted-foreground shadow-card">
            Could not load overview. Check your connection and refresh.
        </div>
    </div>
</template>

<style scoped>
/* ── Root background: warm off-white like the reference ── */
.db-root {
    background-color: #F2EFEA;
    min-height: 100%;
}

/* ── Bento grid ── */
.bento {
    display: grid;
    gap: 10px;
    grid-template-columns: 1fr 1fr 1fr 160px;
    grid-template-rows: auto auto auto;
    grid-template-areas:
        "a b c d"
        "a e f g"
        "h h f .";
}
@media (max-width: 1100px) {
    .bento {
        grid-template-columns: 1fr 1fr 1fr;
        grid-template-areas:
            "a a b"
            "a a c"
            "d e f"
            "g g h"
            "h h h";
    }
}
@media (max-width: 720px) {
    .bento {
        grid-template-columns: 1fr 1fr;
        grid-template-areas:
            "a a"
            "b c"
            "d e"
            "f f"
            "g h";
    }
}
@media (max-width: 480px) {
    .bento {
        grid-template-columns: 1fr;
        grid-template-areas: "a" "b" "c" "d" "e" "f" "g" "h";
    }
}

/* ── Card base ── */
.db-card {
    background: #FFFFFF;
    border-radius: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 16px rgba(0,0,0,0.06);
}

/* ── Coral accent ── */
.bg-coral { background-color: #E8563A; }
.text-coral { color: #E8563A; }

/* ── Date chip ── */
.date-chip {
    border-radius: 20px;
}

/* ── CTA pill ── */
.cta-pill {
    background: #E8563A;
    transition: all 0.2s;
}
.cta-pill:hover {
    background: #D44A2F;
    box-shadow: 0 8px 24px rgba(232,86,58,0.4);
    transform: translateY(-1px);
}

/* ── Shadow ── */
.shadow-card {
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 16px rgba(0,0,0,0.06);
}
.shadow-coral {
    box-shadow: 0 4px 20px rgba(232,86,58,0.35);
}

/* ── Icon chips ── */
.icon-chip {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    flex-shrink: 0;
}
.icon-chip-sm {
    width: 26px;
    height: 26px;
    border-radius: 8px;
}
.icon-chip-coral {
    background: rgba(232,86,58,0.12);
    color: #E8563A;
}

/* ── Ring items (concentric ring visual) ── */
.ring-item {
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: rgba(232,86,58,0.06);
}

/* ── Quick action items ── */
.qa-item {
    cursor: pointer;
}
.qa-item:hover {
    background: #F7F4F0;
}
.qa-item-primary .qa-icon {
    box-shadow: 0 4px 12px rgba(232,86,58,0.3);
}
</style>
