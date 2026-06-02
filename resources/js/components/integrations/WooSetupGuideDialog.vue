<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Connect WooCommerce</DialogTitle>
                <DialogDescription>
                    Paste 3 values in Integrations, then save and sync.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 text-sm text-gray-700">
                <div class="rounded-xl border border-[#F0EDE8] bg-[#FAF8F5] p-3 text-xs text-gray-600">
                    <p class="font-semibold text-gray-900">Paste these in Integrations</p>
                    <ul class="mt-2 space-y-1">
                        <li>
                            <strong>Site URL</strong> — your WordPress site URL, e.g.
                            <code class="rounded bg-white px-1">https://yourstore.com</code>
                        </li>
                        <li>
                            <strong>Consumer key</strong> — starts with
                            <code class="rounded bg-white px-1">ck_</code>
                        </li>
                        <li>
                            <strong>Consumer secret</strong> — starts with
                            <code class="rounded bg-white px-1">cs_</code>
                        </li>
                    </ul>
                </div>

                <ol class="space-y-3 text-xs text-muted-foreground">
                    <li>
                        <p class="font-semibold text-gray-900">1. Open WooCommerce REST API keys</p>
                        <p>In WordPress admin go to <strong>WooCommerce → Settings → Advanced → REST API</strong>.</p>
                    </li>
                    <li>
                        <p class="font-semibold text-gray-900">2. Create API key</p>
                        <p>Click <strong>Add key</strong>, select a user, and set permissions to <strong>Read/Write</strong>.</p>
                    </li>
                    <li>
                        <p class="font-semibold text-gray-900">3. Copy and paste credentials</p>
                        <p>Copy the generated <strong>Consumer key</strong> and <strong>Consumer secret</strong> now (secret may be hidden later).</p>
                    </li>
                </ol>

                <p class="text-xs text-muted-foreground">
                    Requirements: WooCommerce 3.5+, WordPress 4.4+, and non-default permalinks.
                </p>

                <a
                    href="https://developer.woocommerce.com/docs/apis/rest-api/"
                    target="_blank"
                    rel="noreferrer"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-[#E8563A] hover:underline"
                >
                    Open WooCommerce REST API docs
                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                        <polyline points="15 3 21 3 21 9" />
                        <line x1="10" y1="14" x2="21" y2="3" />
                    </svg>
                </a>
            </div>
        </DialogContent>
    </Dialog>
</template>
