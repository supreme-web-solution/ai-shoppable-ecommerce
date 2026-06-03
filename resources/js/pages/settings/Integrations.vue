<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { CircleHelp, Loader2 } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import ShopifySetupGuideDialog from '@/components/integrations/ShopifySetupGuideDialog.vue';
import WooSetupGuideDialog from '@/components/integrations/WooSetupGuideDialog.vue';
import ZernioConnectPanel from '@/components/integrations/ZernioConnectPanel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAdminApi } from '@/composables/useAdminApi';

type TeamRecord = {
    id: number;
    name: string;
    checkout_mode: string;
    external_provider: string;
    settings?: {
        integrations?: {
            shopify?: { shop_url?: string; client_id?: string; client_secret?: string; enabled?: boolean };
            woocommerce?: { site_url?: string; consumer_key?: string; consumer_secret?: string; enabled?: boolean };
            stripe?: { publishable_key?: string; secret_key?: string; enabled?: boolean };
            paypal?: { client_id?: string; client_secret?: string; enabled?: boolean; mode?: string };
        };
    };
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Integrations', href: '/settings/integrations' },
        ],
    },
});

const page = usePage();
const zernioEnabled = computed(() => Boolean(page.props.zernioEnabled));

const { teamId, apiFetch, patchJson, postJson, ensureTeam } = useAdminApi();

const loading = ref(false);
const saving = ref(false);
const errorText = ref('');
const successText = ref('');
const team = ref<TeamRecord | null>(null);

const shopify = ref({ shop_url: '', client_id: '', client_secret: '', enabled: false });
const woocommerce = ref({ site_url: '', consumer_key: '', consumer_secret: '', enabled: false });
const stripe = ref({ publishable_key: '', secret_key: '', enabled: false });
const paypal = ref({ client_id: '', client_secret: '', enabled: false, mode: 'sandbox' });
const checkoutMode = ref('hybrid');
const externalProvider = ref('none');

const activeSection = ref<'native' | 'external' | null>(null);
const shopifyGuideOpen = ref(false);
const wooGuideOpen = ref(false);
const syncingShopify = ref(false);
const syncingWoo = ref(false);
const shopifySyncLabel = ref('Sync products now');

const shopifySyncBusy = computed(() => syncingShopify.value || saving.value);

const stripeReady = computed(() => stripe.value.enabled && stripe.value.secret_key.trim() !== '');
const paypalReady = computed(() =>
    paypal.value.enabled
    && paypal.value.client_id.trim() !== ''
    && paypal.value.client_secret.trim() !== '',
);
const nativeCheckoutReady = computed(() => stripeReady.value || paypalReady.value);
const externalCheckoutReady = computed(() =>
    (shopify.value.enabled && shopify.value.shop_url.trim() !== '' && shopify.value.client_id.trim() !== '' && shopify.value.client_secret.trim() !== '')
    || (
        woocommerce.value.enabled
        && woocommerce.value.site_url.trim() !== ''
        && woocommerce.value.consumer_key.trim() !== ''
        && woocommerce.value.consumer_secret.trim() !== ''
    ),
);

function disableAllPaymentProviders() {
    stripe.value.enabled = false;
    paypal.value.enabled = false;
    shopify.value.enabled = false;
    woocommerce.value.enabled = false;
}

function normalizeExclusiveProviderFromTeam() {
    if (!team.value) {
        return;
    }

    const integrations = team.value.settings?.integrations ?? {};
    const flags = {
        stripe: Boolean(integrations.stripe?.enabled),
        paypal: Boolean(integrations.paypal?.enabled),
        shopify: Boolean(integrations.shopify?.enabled),
        woocommerce: Boolean(integrations.woocommerce?.enabled),
    };
    const enabledKeys = Object.entries(flags).filter(([, enabled]) => enabled).map(([key]) => key);

    if (enabledKeys.length <= 1) {
        return;
    }

    disableAllPaymentProviders();

    const mode = team.value.checkout_mode;
    const ext = team.value.external_provider;

    if (mode === 'external' || ext === 'shopify' || ext === 'woocommerce') {
        if (ext === 'woocommerce' && flags.woocommerce) {
            woocommerce.value.enabled = true;
            checkoutMode.value = 'external';
            activeSection.value = 'external';

            return;
        }

        if (flags.shopify) {
            shopify.value.enabled = true;
            checkoutMode.value = 'external';
            activeSection.value = 'external';

            return;
        }
    }

    if (flags.paypal) {
        paypal.value.enabled = true;
    } else if (flags.stripe) {
        stripe.value.enabled = true;
    }

    checkoutMode.value = 'native';
    activeSection.value = 'native';
}

function setStripeEnabled(enabled: boolean) {
    if (enabled) {
        disableAllPaymentProviders();
        stripe.value.enabled = true;
        checkoutMode.value = 'native';
        activeSection.value = 'native';

        return;
    }

    stripe.value.enabled = false;
}

function setPaypalEnabled(enabled: boolean) {
    if (enabled) {
        disableAllPaymentProviders();
        paypal.value.enabled = true;
        checkoutMode.value = 'native';
        activeSection.value = 'native';

        return;
    }

    paypal.value.enabled = false;
}

function setShopifyEnabled(enabled: boolean) {
    if (enabled) {
        disableAllPaymentProviders();
        shopify.value.enabled = true;
        checkoutMode.value = 'external';
        activeSection.value = 'external';

        return;
    }

    shopify.value.enabled = false;
}

function setWooCommerceEnabled(enabled: boolean) {
    if (enabled) {
        disableAllPaymentProviders();
        woocommerce.value.enabled = true;
        checkoutMode.value = 'external';
        activeSection.value = 'external';

        return;
    }

    woocommerce.value.enabled = false;
}

async function loadTeam() {
    loading.value = true;
    errorText.value = '';

    try {
        const id = await ensureTeam();
        team.value = await apiFetch<TeamRecord>(`/api/v1/admin/teams/${id}`);

        checkoutMode.value = team.value.checkout_mode || 'hybrid';
        externalProvider.value = team.value.external_provider || 'none';
        shopify.value = {
            shop_url: team.value.settings?.integrations?.shopify?.shop_url ?? '',
            client_id: team.value.settings?.integrations?.shopify?.client_id ?? '',
            client_secret: team.value.settings?.integrations?.shopify?.client_secret ?? '',
            enabled: Boolean(team.value.settings?.integrations?.shopify?.enabled),
        };
        woocommerce.value = {
            site_url: team.value.settings?.integrations?.woocommerce?.site_url ?? '',
            consumer_key: team.value.settings?.integrations?.woocommerce?.consumer_key ?? '',
            consumer_secret: team.value.settings?.integrations?.woocommerce?.consumer_secret ?? '',
            enabled: Boolean(team.value.settings?.integrations?.woocommerce?.enabled),
        };
        stripe.value = {
            publishable_key: team.value.settings?.integrations?.stripe?.publishable_key ?? '',
            secret_key: team.value.settings?.integrations?.stripe?.secret_key ?? '',
            enabled: Boolean(team.value.settings?.integrations?.stripe?.enabled),
        };
        paypal.value = {
            client_id: team.value.settings?.integrations?.paypal?.client_id ?? '',
            client_secret: team.value.settings?.integrations?.paypal?.client_secret ?? '',
            enabled: Boolean(team.value.settings?.integrations?.paypal?.enabled),
            mode: team.value.settings?.integrations?.paypal?.mode ?? 'sandbox',
        };

        normalizeExclusiveProviderFromTeam();

        if (shopify.value.enabled || woocommerce.value.enabled) {
            activeSection.value = 'external';
        } else if (stripe.value.enabled || paypal.value.enabled) {
            activeSection.value = 'native';
        } else if (nativeCheckoutReady.value || stripe.value.publishable_key !== '' || paypal.value.client_id !== '') {
            activeSection.value = 'native';
        } else if (externalCheckoutReady.value) {
            activeSection.value = 'external';
        }
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not load team settings.';
    } finally {
        loading.value = false;
    }
}

async function saveSettings(options: { silent?: boolean } = {}) {
    if (!team.value) {
        return;
    }

    saving.value = true;

    if (!options.silent) {
        errorText.value = '';
        successText.value = '';
    }

    try {
        const resolvedExternalProvider = shopify.value.enabled
            ? 'shopify'
            : woocommerce.value.enabled
              ? 'woocommerce'
              : 'none';
        const resolvedCheckoutMode = shopify.value.enabled || woocommerce.value.enabled
            ? 'external'
            : stripe.value.enabled || paypal.value.enabled
              ? 'native'
              : 'native';

        team.value = await patchJson<TeamRecord>(`/api/v1/admin/teams/${team.value.id}`, {
            checkout_mode: resolvedCheckoutMode,
            external_provider: resolvedExternalProvider,
            settings: {
                ...(team.value.settings ?? {}),
                integrations: {
                    shopify: shopify.value,
                    woocommerce: woocommerce.value,
                    stripe: stripe.value,
                    paypal: paypal.value,
                },
            },
        });

        if (!options.silent) {
            successText.value = 'Settings saved successfully.';
        }
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not save settings.';

        throw error;
    } finally {
        saving.value = false;
    }
}

type CatalogSyncStatusPayload = {
    state: 'idle' | 'queued' | 'running' | 'completed' | 'failed';
    message?: string;
    products_created?: number;
    products_updated?: number;
    total_products?: number;
};

async function waitForShopifySync(): Promise<void> {
    const maxAttempts = 90;

    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        await new Promise((resolve) => window.setTimeout(resolve, 1500));

        const payload = await apiFetch<{ status: CatalogSyncStatusPayload }>(
            `/api/v1/integrations/shopify/sync-status?team_id=${teamId.value}`,
        );
        const status = payload.status;

        if (status.state === 'queued') {
            shopifySyncLabel.value = 'Waiting for worker…';
        } else if (status.state === 'running') {
            shopifySyncLabel.value = 'Importing from Shopify…';
        } else if (status.state === 'completed') {
            successText.value = status.message ?? 'Shopify products synced successfully.';

            return;
        } else if (status.state === 'failed') {
            throw new Error(status.message ?? 'Shopify sync failed. Check your Shop URL, Client ID, and Client Secret.');
        }
    }

    throw new Error('Sync is taking longer than expected. Ensure the integration queue worker is running.');
}

async function waitForWooSync(): Promise<void> {
    const maxAttempts = 90;

    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        await new Promise((resolve) => window.setTimeout(resolve, 1500));

        const payload = await apiFetch<{ status: CatalogSyncStatusPayload }>(
            `/api/v1/integrations/woo/sync-status?team_id=${teamId.value}`,
        );
        const status = payload.status;

        if (status.state === 'completed') {
            successText.value = status.message ?? 'WooCommerce products synced successfully.';

            return;
        }

        if (status.state === 'failed') {
            throw new Error(status.message ?? 'WooCommerce sync failed. Check your Site URL and API keys.');
        }
    }

    throw new Error('WooCommerce sync is taking longer than expected. Ensure the integration queue worker is running.');
}

async function syncProvider(provider: 'shopify' | 'woo') {
    if (provider === 'shopify') {
        if (!shopify.value.shop_url.trim() || !shopify.value.client_id.trim() || !shopify.value.client_secret.trim()) {
            errorText.value = 'Enter Shop URL, Client ID, and Client Secret first.';
            shopifyGuideOpen.value = true;

            return;
        }

        syncingShopify.value = true;
        shopifySyncLabel.value = 'Saving settings…';
    } else {
        syncingWoo.value = true;
    }

    errorText.value = '';
    successText.value = '';

    try {
        await saveSettings({ silent: true });

        if (provider === 'shopify') {
            shopifySyncLabel.value = 'Starting sync…';
            await postJson<{ message?: string }>('/api/v1/integrations/shopify/sync', {});
            await waitForShopifySync();
        } else {
            await postJson<{ message?: string }>('/api/v1/integrations/woo/sync', {});
            await waitForWooSync();
        }
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Sync failed.';
    } finally {
        syncingShopify.value = false;
        syncingWoo.value = false;
        shopifySyncLabel.value = 'Sync products now';
    }
}

onMounted(loadTeam);
</script>

<template>
    <Head title="Integrations" />

    <div class="integrations-root flex min-h-screen flex-1 flex-col gap-5 p-4 md:p-5">
        <!-- Header -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <div class="page-icon flex size-10 shrink-0 items-center justify-center rounded-xl">
                    <svg class="size-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14.5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-[#E8563A]">Commerce Setup</p>
                    <h2 class="text-2xl font-black tracking-tight text-gray-900">Checkout & Integrations</h2>
                    <p class="mt-0.5 text-sm text-gray-500">
                        Choose how shoppers pay: accept payments directly in your embed, or send them to Shopify or WooCommerce.
                    </p>
                </div>
            </div>
        </div>

        <!-- Feedback -->
        <Transition name="fade">
            <div v-if="errorText || successText">
                <p v-if="errorText" class="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    {{ errorText }}
                </p>
                <p v-else-if="successText" class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    {{ successText }}
                </p>
            </div>
        </Transition>

        <div v-if="loading" class="stat-card flex items-center gap-3 rounded-2xl p-4 text-sm text-gray-500">
            <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
            Loading your settings…
        </div>

        <template v-else>
            <!-- Step 1: Choose mode -->
            <div class="section-card rounded-2xl p-6">
                <div class="mb-5 flex items-start gap-3">
                    <div class="step-dot flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-bold">1</div>
                    <div>
                        <h3 class="font-bold text-gray-900">How should shoppers pay?</h3>
                        <p class="mt-0.5 text-sm text-gray-500">Choose one payment flow. Enabling a provider turns the others off.</p>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <!-- Native card -->
                    <button
                        type="button"
                        :class="[
                            'provider-card relative rounded-xl border-2 p-4 text-left transition-all',
                            activeSection === 'native'
                                ? 'provider-card-active'
                                : 'border-gray-100 hover:border-[#E8563A]/40 hover:bg-[#E8563A]/5',
                        ]"
                        @click="activeSection = 'native'"
                    >
                        <div v-if="nativeCheckoutReady" class="absolute right-3 top-3 flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                            <svg class="size-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                            Active
                        </div>
                        <div class="service-icon mb-2 flex size-9 items-center justify-center rounded-lg">
                            <svg class="size-5 text-[#E8563A]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        </div>
                        <p class="font-bold text-gray-900">Native in-app checkout</p>
                        <p class="mt-1 text-xs text-gray-500">Shoppers pay via Stripe or PayPal without leaving your store embed.</p>
                    </button>

                    <!-- External card -->
                    <button
                        type="button"
                        :class="[
                            'provider-card relative rounded-xl border-2 p-4 text-left transition-all',
                            activeSection === 'external'
                                ? 'provider-card-active'
                                : 'border-gray-100 hover:border-[#E8563A]/40 hover:bg-[#E8563A]/5',
                        ]"
                        @click="activeSection = 'external'"
                    >
                        <div v-if="externalCheckoutReady" class="absolute right-3 top-3 flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                            <svg class="size-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                            Active
                        </div>
                        <div class="service-icon mb-2 flex size-9 items-center justify-center rounded-lg">
                            <svg class="size-5 text-[#E8563A]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        </div>
                        <p class="font-bold text-gray-900">Shopify / WooCommerce</p>
                        <p class="mt-1 text-xs text-gray-500">Redirect shoppers to your existing Shopify or WooCommerce store to complete purchase.</p>
                    </button>
                </div>
            </div>

            <!-- Step 2: Native providers -->
            <Transition name="slide">
                <div v-if="activeSection === 'native'" class="space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="step-dot flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-bold">2</div>
                        <h3 class="font-bold text-gray-900">Connect a payment provider</h3>
                    </div>

                    <p class="ml-11 text-sm text-gray-500">
                        Enable Stripe or PayPal. Turning one on disables Shopify, WooCommerce, and the other native provider.
                    </p>

                    <!-- Stripe -->
                    <div
                        :class="[
                            'provider-panel ml-11 rounded-2xl border-2 transition-all',
                            stripeReady ? 'border-emerald-300 bg-emerald-50/60' : stripe.enabled ? 'border-[#E8563A]/40 bg-[#E8563A]/5' : 'border-gray-100 bg-white',
                        ]"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center gap-4 p-4 text-left"
                            @click="setStripeEnabled(!stripe.enabled)"
                        >
                            <!-- Stripe logo placeholder -->
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-indigo-600 font-bold text-white text-xs tracking-wide">
                                S
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="font-semibold">Stripe</p>
                                    <span v-if="stripeReady" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Connected</span>
                                </div>
                                <p class="text-xs text-gray-500">Accept cards, Apple Pay, Google Pay and more</p>
                            </div>
                            <!-- Toggle -->
                            <div
                                :class="[
                                    'relative flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                                    stripe.enabled ? 'bg-[#E8563A]' : 'bg-gray-200',
                                ]"
                            >
                                <span
                                    :class="[
                                        'absolute size-5 rounded-full bg-white shadow transition-transform',
                                        stripe.enabled ? 'translate-x-5' : 'translate-x-0.5',
                                    ]"
                                />
                            </div>
                        </button>

                        <Transition name="expand">
                            <div v-if="stripe.enabled" class="space-y-4 border-t px-4 pb-5 pt-4">
                                <div class="flex justify-end">
                                    <a
                                        href="https://dashboard.stripe.com/apikeys"
                                        target="_blank"
                                        rel="noreferrer"
                                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#E8563A] hover:underline"
                                    >
                                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                        Get keys from Stripe dashboard
                                    </a>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="space-y-1">
                                        <Label class="text-xs">Publishable key</Label>
                                        <Input v-model="stripe.publishable_key" placeholder="pk_live_..." class="font-mono text-xs" />
                                    </div>
                                    <div class="space-y-1">
                                        <Label class="text-xs">Secret key</Label>
                                        <Input v-model="stripe.secret_key" type="password" placeholder="sk_live_..." class="font-mono text-xs" />
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <!-- PayPal -->
                    <div
                        :class="[
                            'provider-panel ml-11 rounded-2xl border-2 transition-all',
                            paypalReady ? 'border-emerald-300 bg-emerald-50/60' : paypal.enabled ? 'border-[#E8563A]/40 bg-[#E8563A]/5' : 'border-gray-100 bg-white',
                        ]"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center gap-4 p-4 text-left"
                            @click="setPaypalEnabled(!paypal.enabled)"
                        >
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#003087] font-bold text-white text-xs tracking-wide">
                                PP
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="font-semibold">PayPal</p>
                                    <span v-if="paypalReady" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Connected</span>
                                </div>
                                <p class="text-xs text-gray-500">Accept PayPal balance and card payments</p>
                            </div>
                            <div
                                :class="[
                                    'relative flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                                    paypal.enabled ? 'bg-[#E8563A]' : 'bg-gray-200',
                                ]"
                            >
                                <span
                                    :class="[
                                        'absolute size-5 rounded-full bg-white shadow transition-transform',
                                        paypal.enabled ? 'translate-x-5' : 'translate-x-0.5',
                                    ]"
                                />
                            </div>
                        </button>

                        <Transition name="expand">
                            <div v-if="paypal.enabled" class="space-y-4 border-t px-4 pb-5 pt-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-medium text-gray-500">Environment:</span>
                                        <div class="flex overflow-hidden rounded-lg border text-xs">
                                            <button
                                                type="button"
                                                :class="['px-3 py-1.5 font-medium transition-colors', paypal.mode === 'sandbox' ? 'bg-[#E8563A] text-white' : 'bg-white hover:bg-[#FAF8F5]']"
                                                @click="paypal.mode = 'sandbox'"
                                            >
                                                Sandbox
                                            </button>
                                            <button
                                                type="button"
                                                :class="['px-3 py-1.5 font-medium transition-colors', paypal.mode === 'live' ? 'bg-[#E8563A] text-white' : 'bg-white hover:bg-[#FAF8F5]']"
                                                @click="paypal.mode = 'live'"
                                            >
                                                Live
                                            </button>
                                        </div>
                                    </div>
                                    <a
                                        href="https://developer.paypal.com/dashboard/applications"
                                        target="_blank"
                                        rel="noreferrer"
                                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#E8563A] hover:underline"
                                    >
                                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                        Open PayPal dashboard
                                    </a>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="space-y-1">
                                        <Label class="text-xs">Client ID</Label>
                                        <Input v-model="paypal.client_id" placeholder="A2x..." class="font-mono text-xs" />
                                    </div>
                                    <div class="space-y-1">
                                        <Label class="text-xs">Client Secret</Label>
                                        <Input v-model="paypal.client_secret" type="password" placeholder="••••••••" class="font-mono text-xs" />
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </Transition>

            <!-- Step 2: External store -->
            <Transition name="slide">
                <div v-if="activeSection === 'external'" class="space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="step-dot flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-bold">2</div>
                        <h3 class="font-bold text-gray-900">Connect your store</h3>
                    </div>

                    <p class="ml-11 text-sm text-gray-500">
                        Enable Shopify or WooCommerce. Turning one on disables Stripe, PayPal, and the other store platform.
                    </p>

                    <!-- Shopify -->
                    <div
                        :class="[
                            'provider-panel ml-11 rounded-2xl border-2 transition-all',
                            shopify.enabled && shopify.shop_url ? 'border-emerald-300 bg-emerald-50/60' : shopify.enabled ? 'border-[#E8563A]/40 bg-[#E8563A]/5' : 'border-gray-100 bg-white',
                        ]"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center gap-4 p-4 text-left"
                            @click="setShopifyEnabled(!shopify.enabled)"
                        >
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#5C6BC0]/15">
                                <svg class="size-6" viewBox="0 0 24 24" fill="none"><path d="M15.5 5.5C15.5 5.5 15 3 12.5 3C10.5 3 9.5 5 9.5 5H7L5 19H19L17 5.5H15.5Z" fill="#5C6BC0" opacity="0.8"/><path d="M12.5 3C12.5 3 11.5 5 11.5 8.5C11.5 12 13 13.5 13 13.5" stroke="#5C6BC0" stroke-width="1.5" stroke-linecap="round"/></svg>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="font-semibold">Shopify</p>
                                    <span v-if="externalCheckoutReady && shopify.enabled" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Connected</span>
                                </div>
                                <p class="text-xs text-gray-500">Redirect to Shopify checkout · sync products automatically</p>
                            </div>
                            <div :class="['relative flex h-6 w-11 shrink-0 items-center rounded-full transition-colors', shopify.enabled ? 'bg-[#E8563A]' : 'bg-gray-200']">
                                <span :class="['absolute size-5 rounded-full bg-white shadow transition-transform', shopify.enabled ? 'translate-x-5' : 'translate-x-0.5']" />
                            </div>
                        </button>
                        <Transition name="expand">
                            <div v-if="shopify.enabled" class="space-y-3 border-t px-4 pb-5 pt-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs text-gray-500">
                                        Import products from Shopify into this app for shoppable videos.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="shrink-0 rounded-full text-xs"
                                        @click="shopifyGuideOpen = true"
                                    >
                                        <CircleHelp class="mr-1.5 size-3.5 text-[#E8563A]" />
                                        How to get keys
                                    </Button>
                                </div>
                                <div class="space-y-1">
                                    <Label class="text-xs">Shop URL</Label>
                                    <Input v-model="shopify.shop_url" placeholder="your-store.myshopify.com" />
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="space-y-1">
                                        <Label class="text-xs">Client ID</Label>
                                        <Input v-model="shopify.client_id" placeholder="a9caaedb..." class="font-mono text-xs" />
                                    </div>
                                    <div class="space-y-1">
                                        <Label class="text-xs">Client Secret</Label>
                                        <Input v-model="shopify.client_secret" type="password" placeholder="••••••••" class="font-mono text-xs" />
                                    </div>
                                </div>
                                <p class="text-[11px] text-gray-500">
                                    See <strong>How to get keys</strong> for where to find these.
                                </p>
                                <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="min-w-[10.5rem]"
                                        :disabled="shopifySyncBusy"
                                        @click="syncProvider('shopify')"
                                    >
                                        <Loader2
                                            v-if="shopifySyncBusy"
                                            class="mr-1.5 size-3.5 shrink-0 animate-spin"
                                        />
                                        {{ shopifySyncLabel }}
                                    </Button>
                                   
                                </div>
                            </div>
                        </Transition>
                    </div>

                    <!-- WooCommerce -->
                    <div
                        :class="[
                            'provider-panel ml-11 rounded-2xl border-2 transition-all',
                            woocommerce.enabled && woocommerce.site_url ? 'border-emerald-300 bg-emerald-50/60' : woocommerce.enabled ? 'border-[#E8563A]/40 bg-[#E8563A]/5' : 'border-gray-100 bg-white',
                        ]"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center gap-4 p-4 text-left"
                            @click="setWooCommerceEnabled(!woocommerce.enabled)"
                        >
                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#7F54B3]/15">
                                <svg class="size-6" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8" fill="#7F54B3" opacity="0.25"/><text x="12" y="16" text-anchor="middle" font-size="9" font-weight="bold" fill="#7F54B3">Woo</text></svg>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="font-semibold">WooCommerce</p>
                                    <span v-if="externalCheckoutReady && woocommerce.enabled" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Connected</span>
                                </div>
                                <p class="text-xs text-gray-500">Redirect to WooCommerce checkout · sync products via REST API</p>
                            </div>
                            <div :class="['relative flex h-6 w-11 shrink-0 items-center rounded-full transition-colors', woocommerce.enabled ? 'bg-[#E8563A]' : 'bg-gray-200']">
                                <span :class="['absolute size-5 rounded-full bg-white shadow transition-transform', woocommerce.enabled ? 'translate-x-5' : 'translate-x-0.5']" />
                            </div>
                        </button>
                        <Transition name="expand">
                            <div v-if="woocommerce.enabled" class="space-y-3 border-t px-4 pb-5 pt-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs text-gray-500">
                                        Import products from WooCommerce into this app for shoppable videos.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="shrink-0 rounded-full text-xs"
                                        @click="wooGuideOpen = true"
                                    >
                                        <CircleHelp class="mr-1.5 size-3.5 text-[#E8563A]" />
                                        How to get keys
                                    </Button>
                                </div>
                                <div class="space-y-1">
                                    <Label class="text-xs">Site URL</Label>
                                    <Input v-model="woocommerce.site_url" placeholder="https://yourstore.com" />
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="space-y-1">
                                        <Label class="text-xs">Consumer key</Label>
                                        <Input v-model="woocommerce.consumer_key" class="font-mono text-xs" />
                                    </div>
                                    <div class="space-y-1">
                                        <Label class="text-xs">Consumer secret</Label>
                                        <Input v-model="woocommerce.consumer_secret" type="password" class="font-mono text-xs" />
                                    </div>
                                </div>
                                <p class="text-[11px] text-gray-500">
                                    See <strong>How to get keys</strong> for the exact WooCommerce steps.
                                </p>
                                <Button variant="outline" size="sm" :disabled="syncingWoo || saving" @click="syncProvider('woo')">
                                    <svg v-if="syncingWoo" class="mr-1.5 size-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                    {{ syncingWoo ? 'Syncing…' : 'Sync products now' }}
                                </Button>
                            </div>
                        </Transition>
                    </div>
                </div>
            </Transition>

            <ZernioConnectPanel v-if="zernioEnabled" />

            <!-- Save -->
            <div v-if="activeSection" class="section-card flex items-center gap-4 rounded-2xl p-4">
                <Button :disabled="saving" class="cta-btn min-w-32" @click="saveSettings">
                    <svg v-if="saving" class="mr-2 size-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                    {{ saving ? 'Saving…' : 'Save settings' }}
                </Button>
                <p class="text-xs text-gray-500">Changes take effect immediately for new checkout sessions.</p>
            </div>
        </template>

        <ShopifySetupGuideDialog v-model:open="shopifyGuideOpen" />
        <WooSetupGuideDialog v-model:open="wooGuideOpen" />
    </div>
</template>

<style scoped>
.integrations-root {
    background-color: #F2EFEA;
}

.page-icon {
    background: linear-gradient(135deg, #E8563A, #ff8c42);
    box-shadow: 0 4px 12px rgba(232,86,58,0.35);
}

.section-card,
.stat-card {
    background: #fff;
    border: 1px solid #F0EDE8;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 16px rgba(0,0,0,0.06);
}

.step-dot,
.service-icon {
    background: rgba(232,86,58,0.10);
    color: #E8563A;
    box-shadow: inset 0 0 0 1px rgba(232,86,58,0.12);
}

.provider-card,
.provider-panel {
    background: #fff;
}

.provider-card:hover,
.provider-panel:hover {
    box-shadow: 0 6px 20px rgba(0,0,0,0.06);
}

.provider-card-active {
    border-color: #E8563A;
    background: rgba(232,86,58,0.05);
    box-shadow: 0 4px 16px rgba(232,86,58,0.10);
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

:deep(input) {
    border-color: #e5e7eb;
    background: #fff;
    border-radius: 12px;
}

:deep(input:focus) {
    border-color: #E8563A;
    box-shadow: 0 0 0 3px rgba(232,86,58,0.10);
}

:deep(button[variant="outline"]),
:deep(.border) {
    border-color: #e5e7eb;
}

.fade-enter-active, .fade-leave-active { transition: opacity .2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
.slide-enter-active, .slide-leave-active { transition: all .25s ease; }
.slide-enter-from, .slide-leave-to { opacity: 0; transform: translateY(8px); }
.expand-enter-active, .expand-leave-active { transition: all .2s ease; overflow: hidden; }
.expand-enter-from, .expand-leave-to { opacity: 0; max-height: 0; }
.expand-enter-to, .expand-leave-from { max-height: 600px; }
</style>
