<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    formatCountdown,
    msRemaining,
    resolveOverlayKind,
    tagOverlayStyle,
    tagPosition,
    type OverlayKind,
    type TimedTagLike,
} from '@/lib/tagOverlay';

type ProductTag = TimedTagLike & {
    cta_label?: string | null;
    product?: {
        id: number;
        title: string;
        price: string;
        sale_price?: string | null;
        image_url?: string | null;
    };
};

const props = withDefaults(
    defineProps<{
        tag: ProductTag;
        currentTimeMs: number;
        docked?: boolean;
    }>(),
    {
        docked: false,
    },
);

const emit = defineEmits<{
    dismiss: [tagId: number];
    addToCart: [tag: ProductTag];
    copyCoupon: [code: string];
}>();

const copied = ref(false);

const kind = computed(() => resolveOverlayKind(props.tag));
const style = computed(() =>
    tagOverlayStyle(props.tag, props.docked ? 'docked' : 'absolute'),
);
const remainingMs = computed(() =>
    msRemaining(props.tag.ends_at_ms, props.currentTimeMs),
);
const countdown = computed(() => formatCountdown(remainingMs.value));
const discountLabel = computed(() => {
    const n = Number(props.tag.discount_percent ?? 0);
    if (!n) {
        return null;
    }

    return `-${Math.round(n)}%`;
});

const displayPrice = computed(() => {
    const p = props.tag.product;
    if (!p) {
        return null;
    }

    return p.sale_price || p.price;
});

const kindClass = computed(() => {
    const anchor = tagPosition(props.tag).anchor ?? 'bottom-left';
    const anchorMod =
        anchor === 'center' ? ' timed-overlay--center' : '';

    const dockedMod = props.docked ? ' timed-overlay--docked' : '';

    return `timed-overlay timed-overlay--${kind.value as OverlayKind}${anchorMod}${dockedMod}`;
});

async function copyCode() {
    const code = props.tag.coupon_code?.trim();
    if (!code) {
        return;
    }

    try {
        await navigator.clipboard.writeText(code);
        copied.value = true;
        emit('copyCoupon', code);
        window.setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        /* ignore */
    }
}
</script>

<template>
    <div
        :class="kindClass"
        :style="style"
        role="status"
        :aria-label="tag.product?.title ?? 'Offer'"
    >
        <button
            type="button"
            class="timed-overlay-close"
            aria-label="Dismiss"
            @click.stop="emit('dismiss', tag.id)"
        >
            ×
        </button>

        <!-- Flash sale drop -->
        <template v-if="kind === 'flash'">
            <div class="timed-overlay-flash-pulse" aria-hidden="true" />
            <p class="timed-overlay-kicker">Flash sale</p>
            <p v-if="discountLabel" class="timed-overlay-discount">
                {{ discountLabel }}
            </p>
            <p v-if="tag.product?.title" class="timed-overlay-title">
                {{ tag.product.title }}
            </p>
            <p v-if="displayPrice" class="timed-overlay-price">
                {{ displayPrice }}
            </p>
            <p v-if="remainingMs > 0" class="timed-overlay-countdown">
                Ends in <strong>{{ countdown }}</strong>
            </p>
            <button
                v-if="tag.product"
                type="button"
                class="timed-overlay-cta"
                @click.stop="emit('addToCart', tag)"
            >
                {{ tag.cta_label || 'Grab deal' }}
            </button>
        </template>

        <!-- Coupon drop -->
        <template v-else-if="kind === 'coupon'">
            <p class="timed-overlay-kicker">Coupon drop</p>
            <p class="timed-overlay-coupon-label">Use code</p>
            <p class="timed-overlay-coupon-code">
                {{ tag.coupon_code || 'SAVE' }}
            </p>
            <p v-if="tag.product?.title" class="timed-overlay-sub">
                on {{ tag.product.title }}
            </p>
            <div class="timed-overlay-coupon-actions">
                <button
                    type="button"
                    class="timed-overlay-cta timed-overlay-cta--ghost"
                    @click.stop="copyCode"
                >
                    {{ copied ? 'Copied!' : 'Copy code' }}
                </button>
                <button
                    v-if="tag.product"
                    type="button"
                    class="timed-overlay-cta"
                    @click.stop="emit('addToCart', tag)"
                >
                    {{ tag.cta_label || 'Shop now' }}
                </button>
            </div>
        </template>

        <!-- Product hotspot card -->
        <template v-else>
            <div v-if="tag.product?.image_url" class="timed-overlay-thumb">
                <img
                    :src="tag.product.image_url"
                    :alt="tag.product.title"
                    class="timed-overlay-thumb-img"
                />
            </div>
            <div class="timed-overlay-body">
                <p class="timed-overlay-kicker">Featured</p>
                <p class="timed-overlay-title">
                    {{ tag.product?.title }}
                </p>
                <div class="timed-overlay-price-row">
                    <span v-if="displayPrice" class="timed-overlay-price">{{
                        displayPrice
                    }}</span>
                    <span
                        v-if="discountLabel"
                        class="timed-overlay-discount timed-overlay-discount--sm"
                        >{{ discountLabel }}</span
                    >
                </div>
                <button
                    v-if="tag.product"
                    type="button"
                    class="timed-overlay-cta"
                    @click.stop="emit('addToCart', tag)"
                >
                    {{ tag.cta_label || 'Shop now' }}
                </button>
            </div>
        </template>
    </div>
</template>

<style scoped>
.timed-overlay--docked {
    position: relative;
    top: auto;
    left: auto;
    right: auto;
    bottom: auto;
    transform: none;
    width: 100%;
    max-width: 260px;
    min-width: 0;
}

.timed-overlay {
    position: absolute;
    pointer-events: auto;
    border-radius: 18px;
    padding: 12px 14px 14px;
    color: #fff;
    box-shadow:
        0 16px 48px rgba(0, 0, 0, 0.45),
        0 0 0 1px rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(16px);
    animation: overlay-enter 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes overlay-enter {
    from {
        opacity: 0;
        transform: translateY(12px) scale(0.94);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.timed-overlay--center {
    animation-name: overlay-enter-center;
}

@keyframes overlay-enter-center {
    from {
        opacity: 0;
        transform: translate(-50%, -42%) scale(0.9);
    }
    to {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1);
    }
}

.timed-overlay--product {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(17, 17, 17, 0.88);
    border: 1px solid rgba(255, 255, 255, 0.14);
    min-width: 200px;
}

.timed-overlay--flash {
    background: linear-gradient(
        145deg,
        rgba(232, 86, 58, 0.95),
        rgba(255, 120, 60, 0.92)
    );
    border: 1px solid rgba(255, 255, 255, 0.25);
    text-align: left;
}

.timed-overlay--coupon {
    background: linear-gradient(
        160deg,
        rgba(24, 24, 28, 0.94),
        rgba(40, 36, 52, 0.94)
    );
    border: 1px solid rgba(255, 214, 120, 0.35);
    text-align: center;
    min-width: 200px;
}

.timed-overlay-flash-pulse {
    position: absolute;
    inset: -4px;
    border-radius: 22px;
    border: 2px solid rgba(255, 255, 255, 0.35);
    animation: flash-pulse 1.4s ease-in-out infinite;
    pointer-events: none;
}

@keyframes flash-pulse {
    0%,
    100% {
        opacity: 0.35;
        transform: scale(1);
    }
    50% {
        opacity: 0.9;
        transform: scale(1.02);
    }
}

.timed-overlay-close {
    position: absolute;
    top: 6px;
    right: 8px;
    width: 22px;
    height: 22px;
    border: none;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.25);
    color: #fff;
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
    opacity: 0.85;
}

.timed-overlay-close:hover {
    opacity: 1;
    background: rgba(0, 0, 0, 0.4);
}

.timed-overlay-kicker {
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    opacity: 0.9;
    margin: 0 0 4px;
    padding-right: 18px;
}

.timed-overlay-discount {
    font-size: 28px;
    font-weight: 900;
    line-height: 1;
    margin: 0 0 4px;
    letter-spacing: -0.03em;
}

.timed-overlay-discount--sm {
    font-size: 11px;
    padding: 2px 7px;
    border-radius: 999px;
    background: rgba(232, 86, 58, 0.25);
    margin-left: 6px;
}

.timed-overlay-title {
    font-size: 13px;
    font-weight: 700;
    line-height: 1.25;
    margin: 0 0 4px;
    padding-right: 12px;
}

.timed-overlay-sub {
    font-size: 11px;
    opacity: 0.85;
    margin: 0 0 8px;
}

.timed-overlay-price {
    font-size: 14px;
    font-weight: 800;
    margin: 0 0 6px;
}

.timed-overlay-price-row {
    display: flex;
    align-items: center;
    gap: 4px;
    margin-bottom: 8px;
}

.timed-overlay-countdown {
    font-size: 11px;
    margin: 0 0 8px;
    opacity: 0.95;
}

.timed-overlay-countdown strong {
    font-variant-numeric: tabular-nums;
}

.timed-overlay-coupon-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.75;
    margin: 0;
}

.timed-overlay-coupon-code {
    font-size: 22px;
    font-weight: 900;
    letter-spacing: 0.12em;
    margin: 4px 0 6px;
    color: #ffd878;
}

.timed-overlay-coupon-actions {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.timed-overlay-thumb {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    overflow: hidden;
    flex-shrink: 0;
    background: rgba(255, 255, 255, 0.1);
}

.timed-overlay-thumb-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.timed-overlay-body {
    min-width: 0;
    flex: 1;
}

.timed-overlay-cta {
    display: block;
    width: 100%;
    border: none;
    border-radius: 999px;
    background: #fff;
    color: #e8563a;
    font-size: 12px;
    font-weight: 800;
    padding: 8px 12px;
    cursor: pointer;
    transition:
        transform 0.15s,
        opacity 0.15s;
}

.timed-overlay-cta:hover {
    transform: scale(1.02);
    opacity: 0.95;
}

.timed-overlay-cta--ghost {
    background: rgba(255, 255, 255, 0.14);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.28);
}

.timed-overlay--flash .timed-overlay-cta {
    background: rgba(0, 0, 0, 0.22);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.35);
}
</style>
