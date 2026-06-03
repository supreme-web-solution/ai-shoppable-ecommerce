<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Film, TrendingUp, Users } from 'lucide-vue-next';
import { home } from '@/routes';

const page = usePage();
const appName = (page.props as { name?: string }).name ?? 'SupremeVid';

defineProps<{
    title?: string;
    description?: string;
}>();

const stats = [
    { value: '10k+', label: 'Brands' },
    { value: '3×', label: 'Avg conversion' },
    { value: '$2M+', label: 'Revenue tracked' },
    { value: '99.9%', label: 'Uptime' },
];

const mockProducts = [
    { emoji: '👟', name: 'Air Max 270', category: 'Footwear', price: '$129', sold: '142', pct: '82%' },
    { emoji: '👜', name: 'Canvas Tote', category: 'Accessories', price: '$79', sold: '98', pct: '60%' },
    { emoji: '🕶️', name: 'Retro Shades', category: 'Eyewear', price: '$59', sold: '74', pct: '45%' },
];
</script>

<template>
    <div class="root">

        <!-- ═══════════════ LEFT BRAND PANEL ═══════════════ -->
        <div class="brand-panel">

            <!-- Background layers -->
            <div class="bp-gradient" />
            <div class="bp-grid" />
            <div class="bp-glow" />

            <!-- ── LOGO ── -->
            <div class="bp-logo">
                <Link :href="home()" class="flex items-center gap-3 no-underline">
                    <div class="logo-icon">
                        <Film class="size-4 text-white" />
                    </div>
                    <span class="text-lg font-black text-white tracking-tight">{{ appName }}</span>
                </Link>
            </div>

            <!-- ── HERO TEXT ── -->
            <div class="bp-hero">
                <div class="platform-tag">
                    <span class="tag-dot" />
                    AI-Powered Video Commerce
                </div>
                <h2 class="hero-title">
                    Where video becomes<br/>
                    your <span class="accent-text">best salesperson</span>
                </h2>
                <p class="hero-sub">
                    Create shoppable videos, run AI avatar ads, and sell live — all in one platform.
                </p>
            </div>

            <!-- ── MINI DASHBOARD MOCKUP ── -->
            <div class="dashboard-card">
                <!-- Card header -->
                <div class="dc-header">
                    <div class="dc-dot-row">
                        <span class="dc-dot dc-dot-r" />
                        <span class="dc-dot dc-dot-y" />
                        <span class="dc-dot dc-dot-g" />
                    </div>
                    <div class="dc-title-bar">
                        <span class="live-badge">
                            <span class="live-blink" />
                            LIVE
                        </span>
                        <span class="dc-name">Summer Drop — Live Stream</span>
                    </div>
                    <div class="dc-viewers">
                        <Users class="size-3 text-white/40" />
                        <span>2.4k</span>
                    </div>
                </div>

                <!-- Product rows -->
                <div class="dc-products">
                    <div v-for="item in mockProducts" :key="item.name" class="dc-product-row">
                        <span class="product-emoji">{{ item.emoji }}</span>
                        <div class="product-info">
                            <span class="product-name">{{ item.name }}</span>
                            <span class="product-meta">{{ item.category }}</span>
                        </div>
                        <span class="product-price">{{ item.price }}</span>
                        <div class="product-bar">
                            <div class="product-bar-fill" :style="{ width: item.pct }" />
                        </div>
                        <span class="product-sold">{{ item.sold }}</span>
                    </div>
                </div>

                <!-- Card footer -->
                <div class="dc-footer">
                    <div class="revenue-stat">
                        <TrendingUp class="size-3 text-emerald-400" />
                        <span class="revenue-label">Session revenue</span>
                        <span class="revenue-value">$4,820</span>
                    </div>
                    <div class="checkout-btn-mock">Buy Now →</div>
                </div>
            </div>

            <!-- ── STATS ROW ── -->
            <div class="stats-row">
                <div v-for="s in stats" :key="s.label" class="stat-item">
                    <div class="stat-value">{{ s.value }}</div>
                    <div class="stat-label">{{ s.label }}</div>
                </div>
            </div>

            <!-- ── TESTIMONIAL ── -->
            <div class="testimonial">
                <p class="testimonial-quote">"We hit $50k in our first week. The AI avatar ads are genuinely unreal."</p>
                <div class="testimonial-author">
                    <div class="author-avatar">S</div>
                    <div>
                        <p class="author-name">Sofia Ramirez</p>
                        <p class="author-role">Founder, GlowBrand Studio</p>
                    </div>
                    <div class="stars">
                        <span v-for="i in 5" :key="i">★</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════ RIGHT FORM PANEL ═══════════════ -->
        <div class="form-panel">

            <!-- Mobile logo -->
            <Link :href="home()" class="mobile-logo lg:hidden no-underline">
                <div class="logo-icon">
                    <Film class="size-4 text-white" />
                </div>
                <span class="text-base font-black text-gray-900">{{ appName }}</span>
            </Link>

            <!-- Form card -->
            <div class="form-card">
                <div class="form-header">
                    <h1 class="form-title">{{ title }}</h1>
                    <p class="form-desc">{{ description }}</p>
                </div>
                <slot />
            </div>

            <p class="terms-text">
                By continuing you agree to our
                <a href="#" class="terms-link">Terms</a> &amp; <a href="#" class="terms-link">Privacy</a>
            </p>
        </div>

    </div>
</template>


<style scoped>
/* ─────────────────────────────────────────
   ROOT — locked to viewport, no scroll
───────────────────────────────────────── */
.root {
    display: grid;
    grid-template-columns: 1fr 1fr;
    height: 100dvh;
    width: 100%;
    overflow: hidden;
    background: #f8f7f5;
}
@media (max-width: 1023px) {
    .root { grid-template-columns: 1fr; }
    .brand-panel { display: none; }
}

/* ─────────────────────────────────────────
   BRAND PANEL
───────────────────────────────────────── */
.brand-panel {
    position: relative;
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
    background: #09090c;
    padding: 0 2.5rem;
}

.bp-gradient {
    position: absolute; inset: 0;
    background: radial-gradient(ellipse 70% 50% at 60% 0%, rgba(232,86,58,0.18) 0%, transparent 65%),
                radial-gradient(ellipse 50% 40% at 10% 100%, rgba(200,60,30,0.12) 0%, transparent 60%);
    pointer-events: none;
}
.bp-grid {
    position: absolute; inset: 0;
    background-image:
        linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px);
    background-size: 36px 36px;
    pointer-events: none;
}
.bp-glow {
    position: absolute;
    width: 500px; height: 500px;
    top: -200px; right: -150px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(232,86,58,0.12) 0%, transparent 65%);
    pointer-events: none;
}

/* Logo */
.bp-logo {
    position: relative; z-index: 10;
    padding-top: 1.75rem;
    padding-bottom: 0;
    flex-shrink: 0;
}
.logo-icon {
    display: flex; align-items: center; justify-content: center;
    width: 2.25rem; height: 2.25rem;
    border-radius: 0.75rem;
    background: linear-gradient(135deg, #E8563A, #c9402a);
    box-shadow: 0 3px 12px rgba(232,86,58,0.45);
    flex-shrink: 0;
}

/* Hero text */
.bp-hero {
    position: relative; z-index: 10;
    padding-top: 1.25rem;
    flex-shrink: 0;
}
.platform-tag {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px;
    border-radius: 99px;
    background: rgba(232,86,58,0.12);
    border: 1px solid rgba(232,86,58,0.25);
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.1em;
    color: rgba(255,255,255,0.85);
    margin-bottom: 0.75rem;
}
.tag-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #E8563A;
    animation: blink 2s ease-in-out infinite;
}
.hero-title {
    font-size: 1.65rem; font-weight: 900;
    line-height: 1.25;
    color: #fff;
    margin-bottom: 0.5rem;
    letter-spacing: -0.02em;
}
.accent-text {
    background: linear-gradient(90deg, #FF8A65, #E8563A);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.hero-sub {
    font-size: 0.8125rem;
    color: rgba(255,255,255,0.5);
    line-height: 1.5;
}

/* Dashboard mockup card */
.dashboard-card {
    position: relative; z-index: 10;
    margin-top: 1.25rem;
    border-radius: 14px;
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    overflow: hidden;
    flex-shrink: 0;
}
.dc-header {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    background: rgba(255,255,255,0.03);
}
.dc-dot-row { display: flex; gap: 5px; flex-shrink: 0; }
.dc-dot { width: 8px; height: 8px; border-radius: 50%; }
.dc-dot-r { background: #ff5f57; }
.dc-dot-y { background: #febc2e; }
.dc-dot-g { background: #28c840; }
.dc-title-bar {
    display: flex; align-items: center; gap: 8px;
    flex: 1; min-width: 0;
}
.live-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 7px; border-radius: 99px;
    background: rgba(200,30,20,0.7);
    font-size: 9px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.08em;
    color: #fff;
    flex-shrink: 0;
}
.live-blink {
    width: 5px; height: 5px; border-radius: 50%;
    background: #ff5555;
    animation: blink 1.2s ease-in-out infinite;
}
.dc-name {
    font-size: 11px; font-weight: 600;
    color: rgba(255,255,255,0.6);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.dc-viewers {
    display: flex; align-items: center; gap: 4px;
    font-size: 10px; color: rgba(255,255,255,0.4);
    flex-shrink: 0;
}

/* Product rows */
.dc-products { padding: 8px 14px; display: flex; flex-direction: column; gap: 7px; }
.dc-product-row {
    display: grid;
    grid-template-columns: 20px 1fr auto 80px auto;
    align-items: center;
    gap: 8px;
}
.product-emoji { font-size: 14px; line-height: 1; }
.product-info { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.product-name { font-size: 11px; font-weight: 600; color: rgba(255,255,255,0.85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.product-meta { font-size: 9px; color: rgba(255,255,255,0.35); }
.product-price { font-size: 11px; font-weight: 700; color: #FF8A65; white-space: nowrap; }
.product-bar { height: 4px; background: rgba(255,255,255,0.08); border-radius: 99px; overflow: hidden; }
.product-bar-fill { height: 100%; background: linear-gradient(90deg, #E8563A, #FF8A65); border-radius: 99px; }
.product-sold { font-size: 9px; color: rgba(255,255,255,0.35); white-space: nowrap; text-align: right; }

/* Card footer */
.dc-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px;
    border-top: 1px solid rgba(255,255,255,0.06);
    background: rgba(255,255,255,0.025);
}
.revenue-stat {
    display: flex; align-items: center; gap: 5px;
}
.revenue-label { font-size: 10px; color: rgba(255,255,255,0.4); }
.revenue-value { font-size: 12px; font-weight: 800; color: #fff; margin-left: 2px; }
.checkout-btn-mock {
    padding: 5px 12px; border-radius: 8px;
    background: linear-gradient(135deg, #E8563A, #c9402a);
    font-size: 10px; font-weight: 800; color: #fff;
    box-shadow: 0 2px 8px rgba(232,86,58,0.4);
}

/* Stats row */
.stats-row {
    position: relative; z-index: 10;
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-top: 1rem;
    flex-shrink: 0;
}
.stat-item {
    text-align: center;
    padding: 8px 4px;
    border-radius: 10px;
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.06);
}
.stat-value { font-size: 1rem; font-weight: 900; color: #fff; line-height: 1; }
.stat-label { font-size: 9px; color: rgba(255,255,255,0.4); margin-top: 3px; }

/* Testimonial */
.testimonial {
    position: relative; z-index: 10;
    margin-top: auto;
    padding-bottom: 1.75rem;
    padding-top: 1rem;
    flex-shrink: 0;
}
.testimonial-quote {
    font-size: 0.8125rem;
    color: rgba(255,255,255,0.65);
    font-style: italic;
    line-height: 1.5;
    margin-bottom: 0.75rem;
}
.testimonial-author {
    display: flex; align-items: center; gap: 10px;
}
.author-avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #FF8A65, #E8563A);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 900; color: #fff;
    flex-shrink: 0;
}
.author-name { font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.85); }
.author-role { font-size: 10px; color: rgba(255,255,255,0.4); }
.stars { margin-left: auto; color: #FBBF24; font-size: 11px; letter-spacing: 1px; }

/* ─────────────────────────────────────────
   FORM PANEL
───────────────────────────────────────── */
.form-panel {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    overflow: hidden;
    padding: 2rem 2.5rem;
    background: #f8f7f5;
}
.mobile-logo {
    display: flex; align-items: center; gap: 8px;
    margin-bottom: 1.5rem;
}
.form-card {
    width: 100%;
    max-width: 22rem;
    background: #fff;
    border-radius: 18px;
    padding: 1.75rem;
    box-shadow: 0 1px 0 rgba(0,0,0,0.03), 0 4px 8px rgba(0,0,0,0.05), 0 20px 40px rgba(0,0,0,0.08);
}
.form-header { margin-bottom: 1.25rem; }
.form-title {
    font-size: 1.25rem; font-weight: 900;
    color: #111; letter-spacing: -0.02em;
    line-height: 1.2; margin-bottom: 4px;
}
.form-desc { font-size: 0.8125rem; color: #9ca3af; line-height: 1.4; }
.terms-text {
    margin-top: 1rem;
    font-size: 11px; color: #9ca3af;
    text-align: center;
}
.terms-link { color: #9ca3af; text-decoration: underline; }
.terms-link:hover { color: #6b7280; }

/* ─────────────────────────────────────────
   KEYFRAMES
───────────────────────────────────────── */
@keyframes blink {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.3; }
}
</style>
