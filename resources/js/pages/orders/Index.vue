<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Receipt, Search, ShoppingBag } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminApi } from '@/composables/useAdminApi';

type OrderItem = {
    id: number;
    title: string;
    quantity: number;
    line_total: string;
};

type OrderRow = {
    id: number;
    order_number: string;
    status: string;
    customer_email?: string | null;
    currency: string;
    total_amount: string;
    ordered_at?: string | null;
    metadata?: Record<string, unknown> | null;
    items?: OrderItem[];
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Orders', href: '/orders' },
        ],
    },
});

const { getList, ensureTeam } = useAdminApi();

const ORDERS_PER_PAGE = 20;

const loading = ref(false);
const errorText = ref('');
const search = ref('');
const statusFilter = ref('');
const orders = ref<OrderRow[]>([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });

const statusOptions = [
    { value: '', label: 'All statuses' },
    { value: 'paid', label: 'Paid' },
    { value: 'pending', label: 'Pending' },
    { value: 'failed', label: 'Failed' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'refunded', label: 'Refunded' },
];

const rangeStart = computed(() => {
    if (pagination.value.total === 0) {
        return 0;
    }

    return (pagination.value.current_page - 1) * ORDERS_PER_PAGE + 1;
});

const rangeEnd = computed(() =>
    Math.min(pagination.value.current_page * ORDERS_PER_PAGE, pagination.value.total),
);

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'paid') {
        return 'default';
    }

    if (status === 'pending') {
        return 'secondary';
    }

    if (status === 'failed' || status === 'cancelled') {
        return 'destructive';
    }

    return 'outline';
}

function formatDate(value?: string | null): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

function itemSummary(order: OrderRow): string {
    const items = order.items ?? [];

    if (!items.length) {
        return 'No items';
    }

    if (items.length === 1) {
        return `${items[0].title} × ${items[0].quantity}`;
    }

    return `${items[0].title} + ${items.length - 1} more`;
}

let searchTimer: number | null = null;

async function loadOrders(page = 1) {
    loading.value = true;
    errorText.value = '';

    try {
        const teamId = await ensureTeam();
        const params = new URLSearchParams({
            team_id: String(teamId),
            per_page: String(ORDERS_PER_PAGE),
            page: String(page),
        });

        if (search.value.trim()) {
            params.set('search', search.value.trim());
        }

        if (statusFilter.value) {
            params.set('status', statusFilter.value);
        }

        const data = await getList<OrderRow>(`/api/v1/admin/orders?${params.toString()}`);

        orders.value = data.data;
        pagination.value = {
            current_page: data.meta?.current_page ?? page,
            last_page: data.meta?.last_page ?? 1,
            total: data.meta?.total ?? data.data.length,
        };
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load orders.';
    } finally {
        loading.value = false;
    }
}

watch([search, statusFilter], () => {
    if (searchTimer !== null) {
        window.clearTimeout(searchTimer);
    }

    searchTimer = window.setTimeout(() => {
        void loadOrders(1);
    }, 300);
});

onMounted(() => {
    void loadOrders();
});
</script>

<template>
    <Head title="Orders" />

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-[#E8563A]">Commerce</p>
                <h1 class="mt-1 text-2xl font-extrabold text-gray-900">Orders</h1>
                <p class="mt-1 text-sm text-gray-500">
                    All purchases from your shoppable videos and checkout flow.
                </p>
            </div>
            <div class="flex size-12 items-center justify-center rounded-2xl bg-[#E8563A]/10 text-[#E8563A]">
                <ShoppingBag class="size-6" />
            </div>
        </div>

        <div class="section-card rounded-2xl p-4 sm:p-6">
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="relative flex-1">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <Input
                        v-model="search"
                        class="pl-9"
                        placeholder="Search by order number or email…"
                    />
                </div>
                <select
                    v-model="statusFilter"
                    class="h-10 rounded-md border border-input bg-background px-3 text-sm"
                >
                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">
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

            <div v-else-if="!orders.length" class="rounded-2xl border border-dashed bg-gray-50 px-6 py-12 text-center">
                <Receipt class="mx-auto size-10 text-gray-300" />
                <p class="mt-3 font-semibold text-gray-700">No orders yet</p>
                <p class="mt-1 text-sm text-gray-500">
                    Orders appear here when customers complete checkout from your videos.
                </p>
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="border-b text-left text-xs uppercase tracking-wide text-gray-400">
                            <th class="pb-3 pr-4 font-semibold">Order</th>
                            <th class="pb-3 pr-4 font-semibold">Customer</th>
                            <th class="pb-3 pr-4 font-semibold">Items</th>
                            <th class="pb-3 pr-4 font-semibold">Total</th>
                            <th class="pb-3 pr-4 font-semibold">Status</th>
                            <th class="pb-3 font-semibold">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="order in orders"
                            :key="order.id"
                            class="border-b border-gray-100 last:border-0"
                        >
                            <td class="py-3 pr-4 font-mono font-medium text-gray-900">
                                {{ order.order_number }}
                            </td>
                            <td class="py-3 pr-4 text-gray-600">
                                {{ order.customer_email || '—' }}
                            </td>
                            <td class="py-3 pr-4 text-gray-600">
                                {{ itemSummary(order) }}
                            </td>
                            <td class="py-3 pr-4 font-semibold text-gray-900">
                                {{ order.total_amount }} {{ order.currency }}
                            </td>
                            <td class="py-3 pr-4">
                                <Badge :variant="statusVariant(order.status)" class="capitalize">
                                    {{ order.status }}
                                </Badge>
                            </td>
                            <td class="py-3 text-gray-500">
                                {{ formatDate(order.ordered_at) }}
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
                        @click="loadOrders(pagination.current_page - 1)"
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
                        @click="loadOrders(pagination.current_page + 1)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
