<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const open = defineModel<boolean>('open', { default: false });

const steps = [
    {
        title: 'Open the Dev Dashboard from your store',
        body: 'In Shopify Admin go to Settings → Apps, then click Build apps in Dev Dashboard.',
        image: '/images/shopify-setup/step-1-store-apps.png',
        alt: 'Shopify store settings with Apps and Build apps in Dev Dashboard',
    },
    {
        title: 'Open your app',
        body: 'Pick your app (or create one), then open it from the Apps list.',
        image: '/images/shopify-setup/step-2-dev-dashboard.png',
        alt: 'Shopify Dev Dashboard apps list',
    },
    {
        title: 'Copy Client ID and Client Secret',
        body: 'Go to Settings → Credentials. Copy Client ID and reveal/copy Client Secret.',
        image: '/images/shopify-setup/step-3-credentials.png',
        alt: 'Shopify app Settings page showing Client ID and Secret',
    },
];
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Connect Shopify</DialogTitle>
                <DialogDescription>
                    Paste three values in Integrations, then save and sync.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 text-sm text-gray-700">
                <div class="rounded-xl border border-[#F0EDE8] bg-[#FAF8F5] p-3 text-xs text-gray-600">
                    <p class="font-semibold text-gray-900">Paste these in Integrations</p>
                    <ul class="mt-2 space-y-1">
                        <li>
                            <strong>Shop URL</strong> — your store address, e.g.
                            <code class="rounded bg-white px-1">zr70cd-zq.myshopify.com</code>
                        </li>
                        <li><strong>Client ID</strong> — from step 3 below</li>
                        <li><strong>Client Secret</strong> — same page (click the eye icon)</li>
                    </ul>
                </div>

                <ol class="space-y-4">
                    <li v-for="(step, index) in steps" :key="step.title" class="space-y-2">
                        <p class="font-semibold text-gray-900">
                            {{ index + 1 }}. {{ step.title }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ step.body }}
                        </p>
                        <img
                            :src="step.image"
                            :alt="step.alt"
                            class="w-full rounded-lg border border-gray-200 shadow-sm"
                            loading="lazy"
                        />
                    </li>
                </ol>

                <p class="text-xs text-muted-foreground">
                    The app must be <strong>installed on your store</strong> and have
                    <strong>read_products</strong> scope on an active version — otherwise sync will fail.
                </p>

                <p class="text-xs text-red-700">
                    Do not use the <strong>App automation token</strong> (<code>atkn_…</code>).
                    Use Client ID and Client Secret only.
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
