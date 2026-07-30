<script setup lang="ts">
import { computed, onBeforeUnmount, watch } from 'vue';

export type DigitalPreviewProduct = {
    id: number;
    title: string;
    description?: string | null;
    price: string;
    sale_price?: string | null;
    currency?: string;
    image_url?: string | null;
    product_type?: 'physical' | 'digital' | string;
    digital_access_type?: 'file' | 'link' | string | null;
    digital_file_name?: string | null;
};

const props = defineProps<{
    open: boolean;
    product: DigitalPreviewProduct | null;
    ctaLabel?: string | null;
    checkoutLoading?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    'add-to-cart': [];
    'buy-now': [];
}>();

const isOpen = computed(() => props.open && props.product !== null);

const fileLabel = computed(() => {
    const product = props.product;

    if (!product) {
        return 'Digital download';
    }

    if (product.digital_access_type === 'link') {
        return 'Instant access link';
    }

    const name = product.digital_file_name?.trim();

    if (name) {
        const ext = name.includes('.') ? name.split('.').pop()?.toUpperCase() : null;

        if (ext && ext.length <= 5) {
            return `${ext} file`;
        }

        return name;
    }

    return 'Digital file';
});

const displayPrice = computed(() => {
    const product = props.product;

    if (!product) {
        return '';
    }

    return product.sale_price || product.price;
});

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');
    }
}

watch(isOpen, (open) => {
    if (typeof document === 'undefined') {
        return;
    }

    if (open) {
        document.addEventListener('keydown', onKeydown);
        document.body.style.overflow = 'hidden';
    } else {
        document.removeEventListener('keydown', onKeydown);
        document.body.style.overflow = '';
    }
});

onBeforeUnmount(() => {
    if (typeof document === 'undefined') {
        return;
    }

    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition name="digital-preview">
            <div
                v-if="isOpen && product"
                class="digital-preview-root"
                role="dialog"
                aria-modal="true"
                :aria-label="`Preview ${product.title}`"
                @click.self="emit('close')"
            >
                <div class="digital-preview-card">
                    <button
                        type="button"
                        class="digital-preview-close"
                        aria-label="Close preview"
                        @click="emit('close')"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>

                    <div class="digital-preview-media">
                        <img
                            v-if="product.image_url"
                            :src="product.image_url"
                            :alt="product.title"
                            class="digital-preview-img"
                        >
                        <div v-else class="digital-preview-img-fallback">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                            </svg>
                        </div>
                        <div class="digital-preview-glow" />
                        <span class="digital-preview-badge">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                <polyline points="7 10 12 15 17 10" />
                                <line x1="12" y1="15" x2="12" y2="3" />
                            </svg>
                            Digital product
                        </span>
                    </div>

                    <div class="digital-preview-body">
                        <p class="digital-preview-eyebrow">{{ fileLabel }}</p>
                        <h3 class="digital-preview-title">{{ product.title }}</h3>
                        <p v-if="product.description" class="digital-preview-desc">
                            {{ product.description }}
                        </p>

                        <ul class="digital-preview-perks">
                            <li>Instant delivery after payment</li>
                            <li>Access link in your receipt email</li>
                            <li>No shipping needed</li>
                        </ul>

                        <div class="digital-preview-price-row">
                            <span class="digital-preview-price">{{ displayPrice }}</span>
                            <span
                                v-if="product.sale_price"
                                class="digital-preview-price-was"
                            >{{ product.price }}</span>
                        </div>

                        <div class="digital-preview-actions">
                            <button
                                type="button"
                                class="digital-preview-btn digital-preview-btn--ghost"
                                @click="emit('add-to-cart')"
                            >
                                {{ ctaLabel || 'Add to cart' }}
                            </button>
                            <button
                                type="button"
                                class="digital-preview-btn digital-preview-btn--primary"
                                :disabled="checkoutLoading"
                                @click="emit('buy-now')"
                            >
                                Buy now
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.digital-preview-root {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: rgba(8, 10, 16, 0.72);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}

.digital-preview-card {
    position: relative;
    width: min(100%, 380px);
    max-height: min(92vh, 640px);
    overflow: auto;
    border-radius: 24px;
    background: linear-gradient(165deg, #1a1d27 0%, #12141c 55%, #0d0f15 100%);
    border: 1px solid rgba(255, 255, 255, 0.12);
    box-shadow:
        0 24px 80px rgba(0, 0, 0, 0.55),
        inset 0 1px 0 rgba(255, 255, 255, 0.08);
    color: #fff;
}

.digital-preview-close {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 2;
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    background: rgba(0, 0, 0, 0.45);
    color: #fff;
    cursor: pointer;
}

.digital-preview-media {
    position: relative;
    aspect-ratio: 16 / 11;
    overflow: hidden;
    background: #0b0d12;
}

.digital-preview-img,
.digital-preview-img-fallback {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.digital-preview-img-fallback {
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.35);
    background: radial-gradient(circle at 30% 20%, rgba(232, 86, 58, 0.25), transparent 55%),
        #151821;
}

.digital-preview-glow {
    position: absolute;
    inset: auto 0 0;
    height: 40%;
    background: linear-gradient(to top, rgba(13, 15, 21, 0.95), transparent);
    pointer-events: none;
}

.digital-preview-badge {
    position: absolute;
    left: 14px;
    bottom: 14px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: #fff;
    background: linear-gradient(135deg, #e8563a, #ff8c42);
    box-shadow: 0 8px 20px rgba(232, 86, 58, 0.35);
}

.digital-preview-body {
    padding: 18px 18px 20px;
}

.digital-preview-eyebrow {
    margin: 0 0 6px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #7dd3fc;
}

.digital-preview-title {
    margin: 0;
    font-size: 20px;
    font-weight: 800;
    letter-spacing: -0.02em;
    line-height: 1.25;
}

.digital-preview-desc {
    margin: 8px 0 0;
    font-size: 13px;
    line-height: 1.45;
    color: rgba(255, 255, 255, 0.68);
    display: -webkit-box;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.digital-preview-perks {
    margin: 14px 0 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 7px;
}

.digital-preview-perks li {
    position: relative;
    padding-left: 18px;
    font-size: 12px;
    color: rgba(255, 255, 255, 0.78);
}

.digital-preview-perks li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 5px;
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: linear-gradient(135deg, #38bdf8, #e8563a);
}

.digital-preview-price-row {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin-top: 16px;
}

.digital-preview-price {
    font-size: 22px;
    font-weight: 800;
    color: #ffb35c;
}

.digital-preview-price-was {
    font-size: 13px;
    color: rgba(255, 255, 255, 0.4);
    text-decoration: line-through;
}

.digital-preview-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
}

.digital-preview-btn {
    flex: 1;
    height: 42px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: opacity 0.15s, background 0.15s;
}

.digital-preview-btn:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.digital-preview-btn--ghost {
    color: #fff;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.16);
}

.digital-preview-btn--ghost:hover {
    background: rgba(255, 255, 255, 0.14);
}

.digital-preview-btn--primary {
    color: #fff;
    border: none;
    background: linear-gradient(135deg, #e8563a, #ff8c42);
}

.digital-preview-btn--primary:hover {
    opacity: 0.94;
}

.digital-preview-enter-active,
.digital-preview-leave-active {
    transition: opacity 0.2s ease;
}

.digital-preview-enter-active .digital-preview-card,
.digital-preview-leave-active .digital-preview-card {
    transition: transform 0.22s ease, opacity 0.22s ease;
}

.digital-preview-enter-from,
.digital-preview-leave-to {
    opacity: 0;
}

.digital-preview-enter-from .digital-preview-card,
.digital-preview-leave-to .digital-preview-card {
    opacity: 0;
    transform: translateY(12px) scale(0.97);
}
</style>
