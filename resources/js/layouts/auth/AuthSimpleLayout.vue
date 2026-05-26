<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Film } from 'lucide-vue-next';
import { home } from '@/routes';

const page = usePage();
const appName = (page.props as { name?: string }).name ?? 'SupremeVid';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="auth-root flex min-h-svh w-full">

        <!-- ── Left brand panel (hidden on mobile) ── -->
        <div class="brand-panel relative hidden flex-col justify-between overflow-hidden p-10 lg:flex lg:w-[42%]">
            <!-- Decorative blobs -->
            <div class="blob blob-1" />
            <div class="blob blob-2" />
            <div class="blob blob-3" />

            <!-- Logo -->
            <Link :href="home()" class="relative z-10 flex items-center gap-3 no-underline">
                <div class="brand-mark flex size-10 items-center justify-center rounded-2xl">
                    <Film class="size-5 text-white" />
                </div>
                <span class="text-xl font-extrabold text-white tracking-tight">{{ appName }}</span>
            </Link>

            <!-- Center copy -->
            <div class="relative z-10 space-y-6">
                <div class="space-y-3">
                    <div class="inline-flex items-center gap-2 rounded-full bg-white/20 px-4 py-1.5 text-xs font-semibold text-white backdrop-blur-sm">
                        <span class="size-1.5 rounded-full bg-white/80" />
                        AI-Powered Video Commerce
                    </div>
                    <h2 class="text-4xl font-black leading-tight text-white">
                        Turn videos into<br/>shoppable experiences
                    </h2>
                    <p class="text-base text-white/70 leading-relaxed">
                        Create, schedule and embed shoppable video feeds.<br/>
                        Let AI generate your next ad in minutes.
                    </p>
                </div>

                <!-- Feature pills -->
                <div class="flex flex-wrap gap-2">
                    <span v-for="feat in ['AI Video Ads', 'Live Commerce', 'Embed Anywhere', 'Analytics']" :key="feat"
                        class="rounded-full bg-white/15 px-3 py-1 text-xs font-semibold text-white backdrop-blur-sm">
                        {{ feat }}
                    </span>
                </div>
            </div>

            <!-- Bottom testimonial -->
            <div class="relative z-10 rounded-2xl bg-white/10 p-5 backdrop-blur-sm">
                <p class="text-sm text-white/80 italic leading-relaxed">
                    "We increased conversions by 3× after embedding shoppable video feeds on our product pages."
                </p>
                <div class="mt-3 flex items-center gap-2.5">
                    <div class="flex size-8 items-center justify-center rounded-full bg-white/30 text-xs font-bold text-white">J</div>
                    <div>
                        <p class="text-xs font-semibold text-white">Jamie Chen</p>
                        <p class="text-[10px] text-white/60">Head of E-commerce, Nomad Collective</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Right form panel ── -->
        <div class="form-panel flex flex-1 flex-col items-center justify-center px-6 py-12 sm:px-10">

            <!-- Mobile logo -->
            <Link :href="home()" class="mb-8 flex items-center gap-2.5 no-underline lg:hidden">
                <div class="brand-mark flex size-9 items-center justify-center rounded-xl">
                    <Film class="size-4 text-white" />
                </div>
                <span class="text-lg font-extrabold text-gray-900">{{ appName }}</span>
            </Link>

            <!-- Form card -->
            <div class="form-card w-full max-w-sm">
                <div class="mb-8 space-y-1.5 text-center">
                    <h1 class="text-2xl font-black text-gray-900">{{ title }}</h1>
                    <p class="text-sm text-gray-500">{{ description }}</p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
/* ── Root ── */
.auth-root {
    background-color: #F2EFEA;
}

/* ── Brand panel ── */
.brand-panel {
    background: linear-gradient(145deg, #E8563A 0%, #c9402a 40%, #a83220 100%);
}

/* ── Blobs ── */
.blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(60px);
    opacity: 0.25;
    pointer-events: none;
}
.blob-1 {
    width: 320px; height: 320px;
    background: #ff8c42;
    top: -80px; right: -60px;
}
.blob-2 {
    width: 220px; height: 220px;
    background: #fff;
    bottom: 120px; left: -60px;
}
.blob-3 {
    width: 180px; height: 180px;
    background: #ff8c42;
    bottom: -40px; right: 80px;
}

/* ── Brand mark ── */
.brand-mark {
    background: rgba(255,255,255,0.25);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

/* ── Form panel ── */
.form-panel {
    background-color: #F2EFEA;
}

/* ── Form card ── */
.form-card {
    background: #ffffff;
    border-radius: 24px;
    padding: 2rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 8px 32px rgba(0,0,0,0.08);
}
</style>
