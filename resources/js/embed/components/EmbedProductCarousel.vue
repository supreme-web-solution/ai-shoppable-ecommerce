<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';

type ProductVariant = {
    id: number;
    title: string;
    price: string;
    sale_price?: string | null;
};

type ProductTag = {
    id: number;
    cta_label?: string | null;
    discount_percent?: string | number | null;
    product?: {
        id: number;
        title: string;
        price: string;
        sale_price?: string | null;
        image_url?: string | null;
        variants?: ProductVariant[];
    };
};

type CurrentVideo = {
    title: string;
    description?: string | null;
};

const props = defineProps<{
    currentVideo: CurrentVideo;
    isProductPageLayout: boolean;
    pinnedTags: ProductTag[];
    activeProductIndex: number;
    variantByTagId: Record<number, number | null>;
    checkoutLoading: boolean;
    currentTag: ProductTag | null;
    productVariants: ProductVariant[];
    selectedVariantId: number | null;
}>();

const emit = defineEmits<{
    (event: 'update:activeProductIndex', value: number): void;
    (event: 'update:selectedVariantId', value: number | null): void;
    (event: 'variant-change', payload: { tagId: number; variantId: number | null }): void;
    (event: 'add-tag-to-cart', tag: ProductTag): void;
    (event: 'buy-tag-now', tag: ProductTag): void;
    (event: 'add-to-cart'): void;
    (event: 'checkout'): void;
}>();

const trackRef = ref<HTMLElement | null>(null);

function setActiveProductIndex(index: number): void {
    emit('update:activeProductIndex', index);
}

function toNullableNumber(value: string): number | null {
    if (value === '') {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

function onTagVariantChange(tagId: number, event: Event): void {
    const target = event.target as HTMLSelectElement;
    emit('variant-change', {
        tagId,
        variantId: toNullableNumber(target.value),
    });
}

function onSelectedVariantChange(event: Event): void {
    const target = event.target as HTMLSelectElement;
    emit('update:selectedVariantId', toNullableNumber(target.value));
}

function onTrackScroll(event: Event): void {
    const track = event.currentTarget as HTMLElement | null;

    if (!track || props.pinnedTags.length <= 1) {
        return;
    }

    const cards = Array.from(track.querySelectorAll<HTMLElement>('.product-card'));

    if (cards.length === 0) {
        return;
    }

    const trackCenter = track.scrollLeft + track.clientWidth / 2;
    let closestIdx = props.activeProductIndex;
    let closestDistance = Number.POSITIVE_INFINITY;

    cards.forEach((card, idx) => {
        const cardCenter = card.offsetLeft + card.offsetWidth / 2;
        const distance = Math.abs(cardCenter - trackCenter);

        if (distance < closestDistance) {
            closestDistance = distance;
            closestIdx = idx;
        }
    });

    if (closestIdx !== props.activeProductIndex) {
        emit('update:activeProductIndex', closestIdx);
    }
}

watch(
    () => props.activeProductIndex,
    async () => {
        if (props.isProductPageLayout) {
            return;
        }

        await nextTick();
        trackRef.value
            ?.querySelector('.product-card--active')
            ?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    },
);

watch(
    () => props.currentVideo.title,
    async () => {
        await nextTick();
        trackRef.value?.scrollTo({ left: 0, behavior: 'auto' });
    },
);
</script>

<template>
    <div class="bottom-area" :class="{ 'commerce-panel': isProductPageLayout }">
        <div v-if="isProductPageLayout" class="commerce-panel-head">
            <span class="commerce-panel-badge">Shoppable video</span>
            <p class="commerce-panel-eyebrow">Watch &amp; shop</p>
        </div>

        <div class="video-meta">
            <h2 class="video-title">{{ currentVideo.title }}</h2>
            <p v-if="currentVideo.description" class="video-desc">
                {{ currentVideo.description }}
            </p>
        </div>

        <div
            v-if="pinnedTags.length > 0"
            class="product-carousel-wrap"
            @touchstart.stop
            @touchend.stop
        >
            <div
                ref="trackRef"
                class="product-carousel"
                :class="{ 'product-carousel--stacked': isProductPageLayout }"
                @scroll.passive="onTrackScroll"
            >
                <div
                    v-for="(tag, idx) in pinnedTags"
                    :key="tag.id"
                    role="button"
                    tabindex="0"
                    :class="[
                        'product-card',
                        isProductPageLayout ? 'product-card--page' : '',
                        idx === activeProductIndex ? 'product-card--active' : '',
                    ]"
                    @click="isProductPageLayout ? undefined : setActiveProductIndex(idx)"
                    @keydown.enter.prevent="setActiveProductIndex(idx)"
                    @keydown.space.prevent="setActiveProductIndex(idx)"
                >
                    <div
                        class="product-card-main"
                        :class="{ 'product-card-main--clickable': isProductPageLayout }"
                        @click="isProductPageLayout ? setActiveProductIndex(idx) : undefined"
                    >
                        <div class="product-img-wrap">
                            <img
                                v-if="tag.product?.image_url"
                                :src="tag.product.image_url"
                                :alt="tag.product?.title"
                                class="product-img"
                                draggable="false"
                                @dragstart.prevent
                            />
                            <div v-else class="product-img-placeholder">
                                <svg
                                    width="18"
                                    height="18"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    class="text-white/30"
                                >
                                    <rect x="3" y="3" width="18" height="18" rx="2" />
                                    <circle cx="8.5" cy="8.5" r="1.5" />
                                    <polyline points="21 15 16 10 5 21" />
                                </svg>
                            </div>
                        </div>
                        <div class="product-info">
                            <p class="product-name">
                                {{ tag.product?.title }}
                            </p>
                            <div class="product-price-row">
                                <span
                                    v-if="tag.product?.sale_price"
                                    class="product-sale-price"
                                >
                                    {{ tag.product.sale_price }}
                                </span>
                                <span
                                    :class="
                                        tag.product?.sale_price
                                            ? 'product-orig-price'
                                            : 'product-price'
                                    "
                                >
                                    {{ tag.product?.price }}
                                </span>
                                <span v-if="tag.discount_percent" class="product-badge">
                                    -{{ tag.discount_percent }}%
                                </span>
                            </div>
                        </div>
                        <button
                            v-if="!isProductPageLayout"
                            type="button"
                            class="product-cart-btn"
                            @click.stop="emit('add-tag-to-cart', tag)"
                        >
                            <svg
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <circle cx="9" cy="21" r="1" />
                                <circle cx="20" cy="21" r="1" />
                                <path
                                    d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"
                                />
                            </svg>
                        </button>
                    </div>

                    <div
                        v-if="isProductPageLayout && tag.product"
                        class="product-card-actions"
                        @click.stop
                    >
                        <select
                            v-if="(tag.product.variants?.length ?? 0) > 0"
                            :value="variantByTagId[tag.id] ?? ''"
                            class="variant-select variant-select--page"
                            @change="onTagVariantChange(tag.id, $event)"
                        >
                            <option
                                v-for="v in tag.product.variants"
                                :key="v.id"
                                :value="v.id"
                            >
                                {{ v.title }} — {{ v.sale_price || v.price }}
                            </option>
                        </select>
                        <div class="product-card-cta-row">
                            <button
                                type="button"
                                class="btn-add-cart btn-add-cart--page"
                                @click="emit('add-tag-to-cart', tag)"
                            >
                                {{ tag.cta_label || 'Add to cart' }}
                            </button>
                            <button
                                type="button"
                                class="btn-buy-now btn-buy-now--page"
                                :disabled="checkoutLoading"
                                @click="emit('buy-tag-now', tag)"
                            >
                                Buy now
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="!isProductPageLayout && pinnedTags.length > 1"
                class="carousel-dots"
            >
                <span
                    v-for="(_, i) in pinnedTags"
                    :key="i"
                    :class="['dot', i === activeProductIndex ? 'dot--active' : '']"
                    @click="setActiveProductIndex(i)"
                />
            </div>

            <div
                v-if="currentTag?.product && !isProductPageLayout"
                class="cta-row"
            >
                <select
                    v-if="productVariants.length > 0"
                    :value="selectedVariantId ?? ''"
                    class="variant-select"
                    @change="onSelectedVariantChange"
                >
                    <option
                        v-for="v in productVariants"
                        :key="v.id"
                        :value="v.id"
                    >
                        {{ v.title }} — {{ v.sale_price || v.price }}
                    </option>
                </select>
                <button
                    type="button"
                    class="btn-add-cart"
                    @click="emit('add-to-cart')"
                >
                    <svg
                        width="14"
                        height="14"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                    >
                        <circle cx="9" cy="21" r="1" />
                        <circle cx="20" cy="21" r="1" />
                        <path
                            d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"
                        />
                    </svg>
                    {{ currentTag.cta_label || 'Add to cart' }}
                </button>
                <button
                    type="button"
                    class="btn-buy-now"
                    @click="emit('checkout')"
                >
                    Buy now
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.bottom-area {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 8;
    padding: 0 12px 12px;
}
.video-meta {
    margin-bottom: 10px;
    padding-right: 62px;
}
.video-title {
    font-size: 15px;
    font-weight: 800;
    line-height: 1.3;
    margin-bottom: 3px;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.7);
    letter-spacing: -0.01em;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    line-clamp: 3;
    box-orient: vertical;
}
.video-desc {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
    line-height: 1.4;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.product-carousel-wrap {
    background: rgba(255, 255, 255, 0.13);
    backdrop-filter: blur(18px);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 22px;
    padding: 10px 10px 9px;
    box-shadow: 0 14px 40px rgba(0, 0, 0, 0.28);
    touch-action: pan-x pinch-zoom;
    overscroll-behavior-x: contain;
}
.product-carousel {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    overflow-y: hidden;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 2px;
    touch-action: pan-x pinch-zoom;
    overscroll-behavior-x: contain;
}
.product-carousel::-webkit-scrollbar {
    display: none;
}
.product-card {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 200px;
    scroll-snap-align: center;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 16px;
    padding: 8px;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
    text-align: left;
    flex-shrink: 0;
    touch-action: pan-x pinch-zoom;
    -webkit-user-drag: none;
    user-select: none;
}
.product-card--active {
    background: rgba(255, 255, 255, 0.22);
    border-color: rgba(232, 86, 58, 0.68);
    box-shadow: inset 0 0 0 1px rgba(232, 86, 58, 0.22);
}
.product-card:hover {
    background: rgba(255, 255, 255, 0.18);
}
.product-img-wrap {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    overflow: hidden;
    flex-shrink: 0;
    background: rgba(255, 255, 255, 0.1);
}
.product-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.product-img-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.product-info {
    flex: 1;
    min-width: 0;
}
.product-name {
    font-size: 11px;
    font-weight: 600;
    line-height: 1.3;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    margin-bottom: 2px;
}
.product-price-row {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}
.product-price {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.9);
}
.product-sale-price {
    font-size: 11px;
    font-weight: 800;
    color: #ffb35c;
}
.product-orig-price {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.4);
    text-decoration: line-through;
}
.product-badge {
    font-size: 9px;
    background: rgba(232, 86, 58, 0.2);
    color: #ffd2c8;
    border-radius: 999px;
    padding: 1px 5px;
    font-weight: 800;
}
.product-cart-btn {
    width: 28px;
    height: 28px;
    border-radius: 10px;
    background: #e8563a;
    border: 1px solid rgba(255, 255, 255, 0.18);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    cursor: pointer;
    transition: background 0.15s;
    color: #fff;
}
.product-cart-btn:hover {
    background: #ff6b4c;
}
.carousel-dots {
    display: flex;
    justify-content: center;
    gap: 5px;
    margin-top: 7px;
}
.dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.3);
    cursor: pointer;
    transition: background 0.2s, transform 0.2s;
}
.dot--active {
    background: #ff8c42;
    transform: scale(1.35);
}
.cta-row {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 8px;
}
.variant-select {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.18);
    color: #fff;
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 11px;
    flex: 1;
    outline: none;
}
.btn-add-cart {
    display: flex;
    align-items: center;
    gap: 5px;
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 999px;
    color: #fff;
    padding: 7px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s;
}
.btn-add-cart:hover {
    background: rgba(255, 255, 255, 0.24);
}
.btn-buy-now {
    background: linear-gradient(135deg, #e8563a, #ff8c42);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 7px 14px;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    white-space: nowrap;
    transition: opacity 0.15s;
}
.btn-buy-now:hover {
    opacity: 0.92;
}

/* Product page layout refinements */
.bottom-area.commerce-panel .video-meta {
    margin-bottom: 14px;
    padding-right: 0;
}
.bottom-area.commerce-panel .video-title {
    font-size: 22px;
    font-weight: 800;
    line-height: 1.2;
    color: #111827;
    text-shadow: none;
    letter-spacing: -0.02em;
}
.bottom-area.commerce-panel .video-desc {
    margin-top: 8px;
    font-size: 14px;
    color: #6b7280;
    -webkit-line-clamp: 4;
}
.bottom-area.commerce-panel .product-carousel-wrap {
    min-width: 0;
    max-width: 100%;
    background: #fff;
    border: 1px solid #ece8e2;
    border-radius: 20px;
    padding: 14px;
    box-shadow: 0 10px 30px rgba(17, 24, 39, 0.06);
    backdrop-filter: none;
    overflow: hidden;
}
.bottom-area.commerce-panel .product-carousel--stacked {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-height: min(46vh, 420px);
    overflow-x: hidden;
    overflow-y: auto;
    padding-right: 2px;
    -webkit-overflow-scrolling: touch;
}
.bottom-area.commerce-panel .product-card--page {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
    min-width: 0;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    background: #faf8f5;
    border: 1px solid #ece8e2;
    border-radius: 16px;
    padding: 10px;
    cursor: default;
}
.bottom-area.commerce-panel .product-card-main {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}
.bottom-area.commerce-panel .product-card-main--clickable {
    cursor: pointer;
}
.bottom-area.commerce-panel .product-card-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
    min-width: 0;
}
.bottom-area.commerce-panel .variant-select--page {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}
.bottom-area.commerce-panel .product-card-cta-row {
    display: flex;
    gap: 8px;
    width: 100%;
}
.bottom-area.commerce-panel .btn-add-cart--page,
.bottom-area.commerce-panel .btn-buy-now--page {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.bottom-area.commerce-panel .btn-add-cart--page {
    color: #111827;
    background: #fff;
    border-color: #e5e7eb;
}
.bottom-area.commerce-panel .btn-add-cart--page:hover {
    background: #f9fafb;
}
.bottom-area.commerce-panel .product-card {
    background: #fff;
    border: 1px solid #ece8e2;
}
.bottom-area.commerce-panel .product-card--active {
    border-color: rgba(232, 86, 58, 0.48);
    box-shadow: inset 0 0 0 1px rgba(232, 86, 58, 0.15);
}
.bottom-area.commerce-panel .variant-select:not(.variant-select--page) {
    background: #fff;
    color: #111827;
    border: 1px solid #e5e7eb;
}

@media (max-width: 640px) {
    .bottom-area.commerce-panel .product-carousel--stacked {
        max-height: 42vh;
    }
}
</style>
