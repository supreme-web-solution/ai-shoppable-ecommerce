<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type OrderItem = {
    id: number;
    title: string;
    quantity: number;
    unit_price: string;
    line_total: string;
};

type CheckoutOrder = {
    id: number;
    order_number: string;
    status: string;
    currency: string;
    total_amount: string;
    subtotal_amount: string;
    metadata?: {
        payment_provider?: string;
    } | null;
    items?: OrderItem[];
    team?: {
        name?: string;
    };
};

const props = defineProps<{
    order: CheckoutOrder;
    token: string;
    paymentStatus?: string;
}>();

const loading = ref(false);
const errorText = ref('');

const provider = computed(() => props.order.metadata?.payment_provider ?? 'payment');
const providerLabel = computed(() => provider.value === 'paypal' ? 'PayPal' : 'Stripe');
const isPaid = computed(() => props.order.status === 'paid');
const isPending = computed(() => props.order.status === 'pending');

const statusMessage = computed(() => {
    if (props.paymentStatus === 'success') {
        return { type: 'info', text: 'Your payment is being confirmed. This page will update once the order is marked paid.' };
    }

    if (props.paymentStatus === 'cancelled') {
        return { type: 'warning', text: 'Payment was cancelled. You can try again below.' };
    }

    return null;
});

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

async function startPayment() {
    loading.value = true;
    errorText.value = '';

    try {
        const headers = new Headers({
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        });
        const csrfToken = readCookie('XSRF-TOKEN');

        if (csrfToken) {
            headers.set('X-XSRF-TOKEN', csrfToken);
        }

        const response = await fetch(`/api/v1/player/checkout/orders/${props.order.id}/start-payment`, {
            method: 'POST',
            credentials: 'same-origin',
            headers,
            body: JSON.stringify({ token: props.token }),
        });
        const payload = await response.json().catch(() => null) as { checkout_url?: string; message?: string } | null;

        if (!response.ok || !payload?.checkout_url) {
            throw new Error(payload?.message ?? 'Could not start payment.');
        }

        window.location.href = payload.checkout_url;
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not start payment.';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <Head :title="`Checkout · ${order.order_number}`" />

    <div class="min-h-screen bg-linear-to-br from-slate-50 to-slate-100">

        <!-- Header bar -->
        <header class="border-b bg-white/80 backdrop-blur-sm">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    </div>
                    <span class="font-semibold text-slate-900">{{ order.team?.name ?? 'Checkout' }}</span>
                </div>
                <div class="flex items-center gap-2 rounded-full border bg-slate-50 px-3 py-1.5 text-xs text-slate-500">
                    <svg class="size-3.5 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Secure checkout
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-8">
            <div class="grid gap-6 lg:grid-cols-[1fr_380px]">

                <!-- Left: Order details -->
                <div class="space-y-5">

                    <!-- Status alerts -->
                    <div
                        v-if="statusMessage"
                        :class="[
                            'flex items-start gap-3 rounded-2xl border p-4 text-sm',
                            statusMessage.type === 'info'
                                ? 'border-blue-200 bg-blue-50 text-blue-800'
                                : 'border-amber-200 bg-amber-50 text-amber-800',
                        ]"
                    >
                        <svg v-if="statusMessage.type === 'info'" class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <svg v-else class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        {{ statusMessage.text }}
                    </div>

                    <div
                        v-if="errorText"
                        class="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"
                    >
                        <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        {{ errorText }}
                    </div>

                    <!-- Paid confirmation -->
                    <div v-if="isPaid" class="flex items-start gap-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-6">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <div>
                            <h2 class="font-semibold text-emerald-900">Payment confirmed!</h2>
                            <p class="mt-1 text-sm text-emerald-700">
                                Order {{ order.order_number }} has been paid. Thank you for your purchase.
                            </p>
                        </div>
                    </div>

                    <!-- Order items card -->
                    <div class="overflow-hidden rounded-2xl border bg-white shadow-sm">
                        <div class="border-b bg-slate-50 px-5 py-4">
                            <div class="flex items-center justify-between">
                                <h2 class="font-semibold text-slate-900">Your order</h2>
                                <span class="rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-medium text-slate-600">
                                    {{ order.order_number }}
                                </span>
                            </div>
                        </div>

                        <div class="divide-y">
                            <div
                                v-for="item in order.items ?? []"
                                :key="item.id"
                                class="flex items-center justify-between gap-4 px-5 py-4"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-slate-100">
                                        <svg class="size-5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                                    </div>
                                    <div>
                                        <p class="font-medium text-slate-900">{{ item.title }}</p>
                                        <p class="text-sm text-slate-500">Qty {{ item.quantity }} × {{ item.unit_price }}</p>
                                    </div>
                                </div>
                                <p class="font-semibold text-slate-900">{{ item.line_total }}</p>
                            </div>

                            <div v-if="!order.items?.length" class="px-5 py-8 text-center text-sm text-slate-400">
                                No items found.
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Right: Summary + pay -->
                <div class="space-y-4 lg:sticky lg:top-8 lg:self-start">
                    <div class="overflow-hidden rounded-2xl border bg-white shadow-sm">
                        <div class="border-b bg-slate-50 px-5 py-4">
                            <h2 class="font-semibold text-slate-900">Order summary</h2>
                        </div>

                        <div class="px-5 py-5">
                            <div class="space-y-3 text-sm">
                                <div class="flex items-center justify-between text-slate-600">
                                    <span>Subtotal</span>
                                    <span class="font-medium text-slate-900">{{ order.subtotal_amount }} {{ order.currency }}</span>
                                </div>
                                <div class="flex items-center justify-between text-slate-600">
                                    <span>Tax</span>
                                    <span class="font-medium text-slate-900">—</span>
                                </div>
                                <div class="my-2 border-t" />
                                <div class="flex items-center justify-between">
                                    <span class="text-base font-bold text-slate-900">Total</span>
                                    <span class="text-lg font-bold text-slate-900">{{ order.total_amount }} {{ order.currency }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="border-t px-5 pb-5">
                            <!-- Pending: show pay button -->
                            <div v-if="isPending" class="pt-4">
                                <button
                                    type="button"
                                    :disabled="loading"
                                    class="relative flex w-full items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3.5 text-sm font-semibold text-primary-foreground shadow-sm transition-all hover:bg-primary/90 active:scale-[.98] disabled:cursor-not-allowed disabled:opacity-60"
                                    @click="startPayment"
                                >
                                    <svg v-if="loading" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                    <template v-else>
                                        <!-- PayPal icon -->
                                        <svg v-if="provider === 'paypal'" class="size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M19.5 6.5C19.5 4 17.5 2 15 2H7.5L5 18h4l.75-5h2.75c3.5 0 6.25-2.25 7-5.5Z" opacity=".7"/><path d="M4 9h9.5c1.75 0 3 1.25 3 3 0 2.25-2 4-4.5 4H9L8 22H4L6 9Z" opacity=".4"/></svg>
                                        <!-- Stripe icon -->
                                        <svg v-else class="size-4" viewBox="0 0 24 24" fill="currentColor"><path d="M14 3H6C4.34 3 3 4.34 3 6v12c0 1.66 1.34 3 3 3h12c1.66 0 3-1.34 3-3V10L14 3z" opacity=".3"/><path d="M14 3v7h7" opacity=".8"/></svg>
                                    </template>
                                    {{ loading ? 'Redirecting…' : `Pay with ${providerLabel}` }}
                                </button>
                                <p class="mt-3 text-center text-xs text-slate-500">
                                    You will be redirected securely to {{ providerLabel }} to enter your payment details.
                                </p>
                            </div>

                            <!-- Paid state -->
                            <div v-else-if="isPaid" class="pt-4">
                                <div class="flex items-center justify-center gap-2 rounded-xl bg-emerald-50 py-3 text-sm font-semibold text-emerald-700">
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                    Payment complete
                                </div>
                            </div>

                            <!-- Other states -->
                            <div v-else class="pt-4 text-center text-sm text-slate-500">
                                This order is no longer active.
                            </div>
                        </div>
                    </div>

                    <!-- Trust badges -->
                    <div class="flex items-center justify-center gap-4 rounded-2xl border bg-white px-4 py-3">
                        <div class="flex items-center gap-1.5 text-xs text-slate-500">
                            <svg class="size-3.5 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            SSL secured
                        </div>
                        <div class="h-4 w-px bg-slate-200" />
                        <div class="flex items-center gap-1.5 text-xs text-slate-500">
                            <svg class="size-3.5 text-emerald-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            Encrypted payment
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>
</template>
