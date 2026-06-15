<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

const vimeoEmbedUrl = 'https://player.vimeo.com/video/1201387143';
const dealUrl = 'https://jvz1.com/c/1335891/442967/';
const bonusEmail = 'victoryakposbonusdelivery@gmail.com';
</script>

<template>
    <Head title="ViroFeed Bundle Bonus" />

    <div class="bonus-page min-h-screen px-4 py-10 sm:px-6 sm:py-14">
        <main class="mx-auto w-full max-w-3xl">
            <div class="content-card rounded-3xl p-6 sm:p-10">
                <h1 class="text-center text-2xl font-black leading-tight text-gray-900 sm:text-3xl">
                    Here&rsquo;s A Guaranteed Shortcut To At Least $1,000+ In Affiliate Commissions Monthly&hellip;
                </h1>

                <p class="mt-6 text-center text-lg font-bold text-[#E8563A] sm:text-xl">
                    Watch This Video First!!!
                </p>

                <div class="mt-6 overflow-hidden rounded-2xl border border-[#F0EDE8] bg-black shadow-lg">
                    <div class="aspect-video">
                        <iframe
                            :src="vimeoEmbedUrl"
                            title="ViroFeed bonus video"
                            class="h-full w-full border-0"
                            allow="autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media"
                            allowfullscreen
                        />
                    </div>
                </div>

                <div class="mt-8 text-center">
                    <p class="text-base font-semibold text-gray-800 sm:text-lg">
                        Want 1 out of the 19 lucky spots?
                    </p>

                    <a
                        :href="dealUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="cta-btn mt-4 inline-flex min-h-14 items-center justify-center rounded-2xl px-6 py-4 text-center text-sm font-bold leading-snug sm:text-base"
                    >
                        Click Here To Grab ViroFeed Bundle Deal Now!!!
                    </a>
                </div>

                <p class="mt-8 text-center text-sm leading-relaxed text-gray-600 sm:text-base">
                    <span class="font-semibold text-gray-800">Note:</span>
                    After picking up a copy quickly send us an email here so we can get started with your unbeatable bnus delivery
                    <a
                        :href="`mailto:${bonusEmail}`"
                        class="font-semibold text-[#E8563A] underline underline-offset-4 hover:text-[#D44A2F]"
                    >
                        {{ bonusEmail }}
                    </a>
                </p>

                <p class="mt-6 text-center text-lg font-bold text-gray-900">
                    See you inside!
                </p>
            </div>
        </main>
    </div>
</template>

<style scoped>
.bonus-page {
    background-color: #f2efea;
}

.content-card {
    background: #fff;
    border: 1px solid #f0ede8;
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.04),
        0 14px 42px rgba(0, 0, 0, 0.1);
}

.cta-btn {
    width: 100%;
    max-width: 36rem;
    background: #e8563a;
    color: #fff;
    box-shadow: 0 4px 16px rgba(232, 86, 58, 0.35);
    transition: all 0.15s ease;
}

.cta-btn:hover {
    background: #d44a2f;
    box-shadow: 0 8px 24px rgba(232, 86, 58, 0.45);
    transform: translateY(-1px);
}
</style>
