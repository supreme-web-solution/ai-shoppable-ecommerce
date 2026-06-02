<script setup lang="ts">
const props = defineProps<{
    liveShowBadgeText: string | null;
    displayViewerCount: number;
    currentIndex: number;
    feedLength: number;
    hasMoreFeed: boolean;
    isMuted: boolean;
    displayReactionCount: number;
    commentCount: number;
    isSaved: boolean;
    cartItemCount: number;
    canGoPrevious: boolean;
    canGoNext: boolean;
    isCarouselLayout: boolean;
    isProductPageLayout: boolean;
}>();

const emit = defineEmits<{
    (event: 'toggle-audio'): void;
    (event: 'react'): void;
    (event: 'toggle-comments'): void;
    (event: 'share'): void;
    (event: 'save'): void;
    (event: 'toggle-cart'): void;
    (event: 'previous-video'): void;
    (event: 'next-video'): void;
}>();
</script>

<template>
    <div
        class="embed-action-shell"
        :class="{
            'embed-action-shell--carousel': props.isCarouselLayout,
            'embed-action-shell--product-page': props.isProductPageLayout,
        }"
    >
        <div class="hud-top">
            <div v-if="props.liveShowBadgeText" class="live-badge">
                <span class="live-dot"></span>
                {{ props.liveShowBadgeText }}
            </div>
            <div v-else class="flex-1" />

            <div class="viewer-chip">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
                <span>{{ props.displayViewerCount.toLocaleString() }}</span>
                <span class="opacity-50">·</span>
                <span>{{ props.currentIndex + 1 }}/{{ props.feedLength }}{{ props.hasMoreFeed ? '+' : '' }}</span>
            </div>
        </div>

        <div class="action-rail">
            <button type="button" class="rail-btn" @click="emit('toggle-audio')">
                <span class="rail-icon" :class="!props.isMuted ? 'saved-active' : ''">
                    <svg
                        v-if="props.isMuted"
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" />
                        <line x1="23" y1="9" x2="17" y2="15" />
                        <line x1="17" y1="9" x2="23" y2="15" />
                    </svg>
                    <svg
                        v-else
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5" />
                        <path d="M15.54 8.46a5 5 0 0 1 0 7.07" />
                        <path d="M19.07 4.93a10 10 0 0 1 0 14.14" />
                    </svg>
                </span>
                <span class="rail-label">{{ props.isMuted ? 'Muted' : 'Sound' }}</span>
            </button>

            <button type="button" class="rail-btn" @click="emit('react')">
                <span class="rail-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" />
                    </svg>
                </span>
                <span class="rail-label">{{ props.displayReactionCount > 0 ? props.displayReactionCount.toLocaleString() : '' }}</span>
            </button>

            <button type="button" class="rail-btn" @click="emit('toggle-comments')">
                <span class="rail-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                    </svg>
                </span>
                <span class="rail-label">{{ props.commentCount || '' }}</span>
            </button>

            <button type="button" class="rail-btn" @click="emit('share')">
                <span class="rail-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="18" cy="5" r="3" />
                        <circle cx="6" cy="12" r="3" />
                        <circle cx="18" cy="19" r="3" />
                        <line x1="8.59" y1="13.51" x2="15.42" y2="17.49" />
                        <line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
                    </svg>
                </span>
                <span class="rail-label">Share</span>
            </button>

            <button type="button" class="rail-btn" @click="emit('save')">
                <span class="rail-icon" :class="props.isSaved ? 'saved-active' : ''">
                    <svg
                        width="22"
                        height="22"
                        viewBox="0 0 24 24"
                        :fill="props.isSaved ? 'currentColor' : 'none'"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" />
                    </svg>
                </span>
                <span class="rail-label">{{ props.isSaved ? 'Saved' : 'Save' }}</span>
            </button>

            <button type="button" class="rail-btn" @click="emit('toggle-cart')">
                <span class="rail-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="21" r="1" />
                        <circle cx="20" cy="21" r="1" />
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                    </svg>
                </span>
                <span v-if="props.cartItemCount" class="cart-badge">{{ props.cartItemCount }}</span>
                <span class="rail-label">Cart</span>
            </button>

            <div v-if="!props.isCarouselLayout && !props.isProductPageLayout" class="rail-nav">
                <button type="button" class="nav-btn" :disabled="!props.canGoPrevious" @click="emit('previous-video')">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="18 15 12 9 6 15" />
                    </svg>
                </button>
                <button type="button" class="nav-btn" :disabled="!props.canGoNext" @click="emit('next-video')">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="6 9 12 15 18 9" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.hud-top {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 14px 0;
}
.live-badge {
    display: flex;
    align-items: center;
    gap: 5px;
    background: linear-gradient(135deg, #e8563a, #ff4d42);
    box-shadow: 0 8px 24px rgba(232, 86, 58, 0.34);
    backdrop-filter: blur(10px);
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.live-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #fff;
    animation: pulse 1.2s infinite;
}
@keyframes pulse {
    0%,
    100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.4; transform: scale(0.7); }
}
.viewer-chip {
    display: flex;
    align-items: center;
    gap: 5px;
    background: rgba(255, 255, 255, 0.13);
    backdrop-filter: blur(14px);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 999px;
    padding: 5px 10px;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.9);
}
.action-rail {
    position: absolute;
    right: 12px;
    bottom: 182px;
    z-index: 10;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}
.rail-btn {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    background: rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 16px;
    padding: 9px 8px 6px;
    cursor: pointer;
    transition: transform 0.15s, background 0.15s;
    min-width: 46px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
    color: #fff;
}
.rail-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: translateY(-1px) scale(1.04);
}
.rail-btn:active { transform: scale(0.96); }
.rail-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    filter: drop-shadow(0 1px 5px rgba(0, 0, 0, 0.35));
    color: inherit;
}
.rail-label {
    font-size: 10px;
    color: rgba(255, 255, 255, 0.8);
    white-space: nowrap;
}
.saved-active { color: #ffb35c; }
.cart-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #e8563a;
    color: #fff;
    border-radius: 999px;
    font-size: 9px;
    font-weight: 700;
    padding: 1px 5px;
    line-height: 1.4;
}
.rail-nav {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 4px;
}
.nav-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.13);
    border: 1px solid rgba(255, 255, 255, 0.18);
    cursor: pointer;
    transition: background 0.15s;
    color: #fff;
}
.nav-btn:disabled { opacity: 0.25; cursor: default; }
.nav-btn:not(:disabled):hover { background: rgba(232, 86, 58, 0.72); }

.embed-action-shell--carousel .hud-top { z-index: 40; }
.embed-action-shell--carousel .action-rail { z-index: 45; bottom: 148px; }
.embed-action-shell--carousel .rail-nav { z-index: 41; }
.embed-action-shell--product-page .action-rail { z-index: 45; bottom: 24px; right: 14px; }
.embed-action-shell--product-page .hud-top { z-index: 40; }

@media (min-width: 900px) {
    .embed-action-shell--carousel .action-rail {
        bottom: 128px;
    }
}
</style>
