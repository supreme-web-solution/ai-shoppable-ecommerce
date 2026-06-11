<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Search, UserRound } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminApi } from '@/composables/useAdminApi';

type LeadRow = {
    id: number;
    email: string;
    full_name?: string | null;
    source: string;
    metadata?: Record<string, unknown> | null;
    last_activity_at?: string | null;
    created_at?: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Leads', href: '/leads' },
        ],
    },
});

const { getList, ensureTeam } = useAdminApi();

const LEADS_PER_PAGE = 20;

const loading = ref(false);
const errorText = ref('');
const search = ref('');
const sourceFilter = ref('');
const leads = ref<LeadRow[]>([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });

const sourceOptions = [
    { value: '', label: 'All sources' },
    { value: 'checkout', label: 'Checkout' },
    { value: 'webinar', label: 'Webinar' },
];

const rangeStart = computed(() => {
    if (pagination.value.total === 0) {
        return 0;
    }

    return (pagination.value.current_page - 1) * LEADS_PER_PAGE + 1;
});

const rangeEnd = computed(() =>
    Math.min(pagination.value.current_page * LEADS_PER_PAGE, pagination.value.total),
);

function sourceLabel(source: string): string {
    if (source === 'checkout') {
        return 'Checkout';
    }

    if (source === 'webinar') {
        return 'Webinar';
    }

    return source;
}

function sourceDetail(lead: LeadRow): string {
    const meta = lead.metadata ?? {};

    if (lead.source === 'checkout' && meta.order_number) {
        return `Order ${meta.order_number}`;
    }

    if (lead.source === 'webinar' && meta.live_show_title) {
        return String(meta.live_show_title);
    }

    return '—';
}

function formatDate(value?: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

let searchTimer: number | null = null;

async function loadLeads(page = 1) {
    loading.value = true;
    errorText.value = '';

    try {
        const teamId = await ensureTeam();
        const params = new URLSearchParams({
            team_id: String(teamId),
            per_page: String(LEADS_PER_PAGE),
            page: String(page),
        });

        if (search.value.trim()) {
            params.set('search', search.value.trim());
        }

        if (sourceFilter.value) {
            params.set('source', sourceFilter.value);
        }

        const data = await getList<LeadRow>(`/api/v1/admin/leads?${params.toString()}`);

        leads.value = data.data;
        pagination.value = {
            current_page: data.meta?.current_page ?? page,
            last_page: data.meta?.last_page ?? 1,
            total: data.meta?.total ?? data.data.length,
        };
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load leads.';
    } finally {
        loading.value = false;
    }
}

watch([search, sourceFilter], () => {
    if (searchTimer !== null) {
        window.clearTimeout(searchTimer);
    }

    searchTimer = window.setTimeout(() => {
        void loadLeads(1);
    }, 300);
});

onMounted(() => {
    void loadLeads();
});
</script>

<template>
    <Head title="Leads" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-[#E8563A]">Commerce</p>
                <h1 class="mt-1 text-2xl font-extrabold text-gray-900">Leads</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Customer emails captured from checkout and webinar registrations.
                </p>
            </div>
            <div class="flex size-12 items-center justify-center rounded-2xl bg-[#E8563A]/10 text-[#E8563A]">
                <UserRound class="size-6" />
            </div>
        </div>

        <div class="section-card rounded-2xl p-4 sm:p-6">
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <Input
                        v-model="search"
                        class="pl-9"
                        placeholder="Search by name or email…"
                    />
                </div>
                <select
                    v-model="sourceFilter"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                >
                    <option v-for="option in sourceOptions" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <p v-if="errorText" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {{ errorText }}
            </p>

            <div v-if="loading" class="space-y-3">
                <Skeleton v-for="n in 5" :key="n" class="h-14 w-full rounded-xl" />
            </div>

            <div v-else-if="!leads.length" class="rounded-2xl border border-dashed bg-gray-50 px-6 py-12 text-center">
                <UserRound class="mx-auto size-10 text-gray-300" />
                <p class="mt-3 font-semibold text-gray-700">No leads yet</p>
                <p class="mt-1 text-sm text-gray-500">
                    Emails are captured when shoppers enter checkout or register for webinars.
                </p>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[680px] text-sm">
                    <thead>
                        <tr class="border-b text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="pb-3 pr-4 font-semibold">Name</th>
                            <th class="pb-3 pr-4 font-semibold">Email</th>
                            <th class="pb-3 pr-4 font-semibold">Source</th>
                            <th class="pb-3 pr-4 font-semibold">Detail</th>
                            <th class="pb-3 font-semibold">Last activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="lead in leads"
                            :key="lead.id"
                            class="border-b border-gray-100 last:border-0"
                        >
                            <td class="py-3 pr-4 font-medium text-gray-900">
                                {{ lead.full_name || '—' }}
                            </td>
                            <td class="py-3 pr-4 text-gray-600">
                                {{ lead.email }}
                            </td>
                            <td class="py-3 pr-4">
                                <Badge variant="outline" class="capitalize">
                                    {{ sourceLabel(lead.source) }}
                                </Badge>
                            </td>
                            <td class="py-3 pr-4 text-gray-600">
                                {{ sourceDetail(lead) }}
                            </td>
                            <td class="py-3 text-gray-500">
                                {{ formatDate(lead.last_activity_at ?? lead.created_at) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="pagination.total > 0"
                class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t pt-4 text-sm text-gray-500"
            >
                <span>
                    Showing {{ rangeStart }}–{{ rangeEnd }} of {{ pagination.total }}
                </span>
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="loading || pagination.current_page <= 1"
                        @click="loadLeads(pagination.current_page - 1)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>
                    <span class="min-w-20 text-center">
                        {{ pagination.current_page }} / {{ pagination.last_page }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="loading || pagination.current_page >= pagination.last_page"
                        @click="loadLeads(pagination.current_page + 1)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
