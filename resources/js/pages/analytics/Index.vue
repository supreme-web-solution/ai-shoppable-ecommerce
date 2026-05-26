<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useAdminApi } from '@/composables/useAdminApi';

type MetricRow = {
    count: number;
    value: number;
};

type SummaryResponse = {
    team_id: number;
    from: string;
    to: string;
    metrics: Record<string, MetricRow>;
    groups?: Record<string, Record<string, MetricRow>>;
    top_events?: Record<string, MetricRow>;
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

const metricEntries = computed(() => Object.entries(summary.value?.metrics ?? {}));
const groupEntries = computed(() => Object.entries(summary.value?.groups ?? {}));
const topEvents = computed(() => Object.entries(summary.value?.top_events ?? {}));

function formatDate(date: Date): string {
    return date.toISOString().slice(0, 10);
}

function formatMetricName(name: string): string {
    return name.replaceAll('_', ' ');
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
        errorText.value = error instanceof Error ? error.message : 'Could not load analytics summary.';
        summary.value = null;
    } finally {
        loading.value = false;
    }
}

onMounted(loadSummary);
</script>

<template>
    <Head title="Analytics" />

    <div class="space-y-4 rounded-xl p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Analytics</h1>
                <p class="text-sm text-muted-foreground">
                    Views, engagement, commerce, and live interaction metrics.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <label class="text-sm text-muted-foreground" for="days-window">Window</label>
                <select
                    id="days-window"
                    v-model.number="daysWindow"
                    class="rounded border bg-background px-2 py-1 text-sm"
                    @change="loadSummary"
                >
                    <option :value="1">Today</option>
                    <option :value="7">7 days</option>
                    <option :value="14">14 days</option>
                    <option :value="30">30 days</option>
                </select>
                <Button variant="outline" :disabled="loading" @click="loadSummary">
                    {{ loading ? 'Loading...' : 'Refresh' }}
                </Button>
            </div>
        </div>

        <p v-if="errorText" class="rounded border border-red-400/40 bg-red-500/10 px-3 py-2 text-sm text-red-300">
            {{ errorText }}
        </p>

        <div v-else-if="loading" class="rounded-lg border p-4 text-sm text-muted-foreground">
            Loading analytics summary...
        </div>

        <template v-else-if="summary">
            <div class="rounded-lg border p-3 text-xs text-muted-foreground">
                Team {{ summary.team_id }} • {{ summary.from }} to {{ summary.to }}
            </div>

            <div v-if="groupEntries.length" class="space-y-4">
                <div v-for="[groupName, groupMetrics] in groupEntries" :key="groupName">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                        {{ groupName }}
                    </h2>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div
                            v-for="[metricName, metric] in Object.entries(groupMetrics)"
                            :key="metricName"
                            class="rounded-lg border bg-card p-4"
                        >
                            <p class="text-xs uppercase tracking-wide text-muted-foreground">
                                {{ formatMetricName(metricName) }}
                            </p>
                            <p class="mt-2 text-2xl font-semibold">{{ metric.count }}</p>
                            <p class="text-xs text-muted-foreground">Value: {{ metric.value }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="topEvents.length" class="rounded-lg border bg-card p-4">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                    Top Events
                </h2>
                <div class="space-y-2">
                    <div
                        v-for="[eventName, metric] in topEvents"
                        :key="eventName"
                        class="flex items-center justify-between text-sm"
                    >
                        <span>{{ formatMetricName(eventName) }}</span>
                        <span class="font-semibold">{{ metric.count }}</span>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="[metricName, metric] in metricEntries"
                    :key="metricName"
                    class="rounded-lg border bg-card p-4"
                >
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">
                        {{ formatMetricName(metricName) }}
                    </p>
                    <p class="mt-2 text-2xl font-semibold">{{ metric.count }}</p>
                    <p class="text-xs text-muted-foreground">Value: {{ metric.value }}</p>
                </div>
            </div>
        </template>

        <div v-else class="rounded-lg border p-4 text-sm text-muted-foreground">
            No analytics data available yet.
        </div>
    </div>
</template>
