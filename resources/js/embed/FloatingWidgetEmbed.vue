<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import EmbedPlayerApp from '@/embed/EmbedPlayerApp.vue';

defineProps<{
    embedSlug: string;
    embedName?: string;
}>();

const open = ref(false);

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        open.value = false;
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div class="floating-widget-root">
        <button
            type="button"
            class="floating-widget-fab"
            :class="{ 'floating-widget-fab--hidden': open }"
            aria-label="Open shoppable videos"
            @click="open = true"
        >
            <span class="floating-widget-fab-icon" aria-hidden="true">
                <svg
                    width="22"
                    height="22"
                    viewBox="0 0 24 24"
                    fill="currentColor"
                >
                    <path d="M8 5v14l11-7z" />
                </svg>
            </span>
            <span class="floating-widget-fab-label">Shop live</span>
        </button>

        <Transition name="floating-widget-fade">
            <div
                v-if="open"
                class="floating-widget-backdrop"
                role="dialog"
                aria-modal="true"
                aria-label="Shoppable video player"
                @click.self="open = false"
            >
                <div class="floating-widget-panel">
                    <button
                        type="button"
                        class="floating-widget-close"
                        aria-label="Close player"
                        @click="open = false"
                    >
                        <svg
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.5"
                        >
                            <line x1="18" y1="6" x2="6" y2="18" />
                            <line x1="6" y1="6" x2="18" y2="18" />
                        </svg>
                    </button>
                    <EmbedPlayerApp :embed-slug="embedSlug" layout="inline" />
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.floating-widget-root {
    position: relative;
    z-index: 99999;
}

.floating-widget-fab {
    pointer-events: auto;
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 100000;
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: calc(100vw - 40px);
    padding: 10px 16px 10px 12px;
    border: none;
    border-radius: 999px;
    background: linear-gradient(135deg, #e8563a, #ff8c42);
    color: #fff;
    box-shadow:
        0 12px 32px rgba(232, 86, 58, 0.45),
        0 0 0 1px rgba(255, 255, 255, 0.2) inset;
    cursor: pointer;
    transition:
        transform 0.2s ease,
        opacity 0.2s ease,
        box-shadow 0.2s ease;
    animation: floating-widget-pulse 2.4s ease-in-out infinite;
}

.floating-widget-fab:hover {
    transform: translateY(-2px) scale(1.02);
    box-shadow: 0 16px 36px rgba(232, 86, 58, 0.5);
}

.floating-widget-fab--hidden {
    opacity: 0;
    pointer-events: none;
    transform: scale(0.9);
}

.floating-widget-fab-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.18);
}

.floating-widget-fab-label {
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 0.01em;
    white-space: nowrap;
}

.floating-widget-backdrop {
    pointer-events: auto;
    position: fixed;
    inset: 0;
    z-index: 100001;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    padding: 12px;
    background: rgba(8, 8, 8, 0.72);
    backdrop-filter: blur(4px);
}

.floating-widget-panel {
    position: relative;
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 420px;
    height: min(92dvh, 900px);
    overflow: hidden;
    border-radius: 24px;
    background: #050505;
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.55);
}

.floating-widget-close {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 30;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border: none;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    cursor: pointer;
}

.floating-widget-close:hover {
    background: rgba(0, 0, 0, 0.75);
}

.floating-widget-panel :deep(.player-root--inline) {
    flex: 1;
    min-height: 0;
}

.floating-widget-fade-enter-active,
.floating-widget-fade-leave-active {
    transition: opacity 0.22s ease;
}

.floating-widget-fade-enter-from,
.floating-widget-fade-leave-to {
    opacity: 0;
}

@keyframes floating-widget-pulse {
    0%,
    100% {
        box-shadow:
            0 12px 32px rgba(232, 86, 58, 0.45),
            0 0 0 0 rgba(232, 86, 58, 0.35);
    }

    50% {
        box-shadow:
            0 14px 36px rgba(232, 86, 58, 0.55),
            0 0 0 10px rgba(232, 86, 58, 0);
    }
}

@media (min-width: 640px) {
    .floating-widget-backdrop {
        align-items: center;
    }
}
</style>
