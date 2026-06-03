import '../../css/app.css';
import { createApp } from 'vue';
import EmbedShell from '@/embed/EmbedShell.vue';
import { normalizeEmbedDisplayType } from '@/lib/videoEmbed';

function injectStylesheet(origin: string): void {
    if (document.querySelector('link[data-supreme-embed]')) {
        return;
    }

    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = `${origin}/embed/embed.css`;
    link.setAttribute('data-supreme-embed', 'true');
    document.head.appendChild(link);
}

function findEmbedScript(): HTMLScriptElement | null {
    const current = document.currentScript;

    if (current instanceof HTMLScriptElement && current.src) {
        return current;
    }

    return document.querySelector<HTMLScriptElement>(
        'script[data-embed]:not([data-supreme-mounted])[src*="embed.js"], script[data-slug]:not([data-supreme-mounted])[src*="embed.js"]',
    );
}

function bootstrap(): void {
    const script = findEmbedScript();

    if (!script?.src) {
        console.error(
            '[Supreme] Embed loader must be loaded via <script src=".../embed/embed.js" data-embed="your-slug">',
        );

        return;
    }

    const slug =
        script.getAttribute('data-embed') ?? script.getAttribute('data-slug');

    if (!slug) {
        console.error('[Supreme] Missing data-embed attribute on embed script.');

        return;
    }

    const embedType = normalizeEmbedDisplayType(
        script.getAttribute('data-type'),
    );
    const embedName = script.getAttribute('data-name') ?? '';
    const height = script.getAttribute('data-height') ?? '700';
    const targetSelector = script.getAttribute('data-target');

    const origin = new URL(script.src).origin;
    window.__SUPREME_EMBED_ORIGIN__ = origin;

    injectStylesheet(origin);

    let container: HTMLElement | null = null;

    if (targetSelector) {
        container = document.querySelector(targetSelector) as HTMLElement | null;

        if (!container) {
            container = document.createElement('div');
            container.id = targetSelector.startsWith('#')
                ? targetSelector.slice(1)
                : targetSelector;
            script.parentNode?.insertBefore(container, script.nextSibling);
        }
    } else if (embedType === 'floating_widget') {
        container = document.createElement('div');
        container.id = `supreme-embed-${slug}`;
        container.setAttribute('data-supreme-floating', 'true');
        container.style.position = 'relative';
        container.style.width = '0';
        container.style.height = '0';
        container.style.overflow = 'visible';
        script.parentNode?.insertBefore(container, script.nextSibling);
    } else {
        container = document.createElement('div');
        container.id = `supreme-embed-${slug}`;
        container.style.width = '100%';
        const autoHeight =
            embedType === 'carousel' || embedType === 'product_page';
        container.style.height = autoHeight ? 'auto' : `${height}px`;
        container.style.minHeight = autoHeight ? `${height}px` : '';
        container.style.position = 'relative';
        container.style.overflow = 'visible';
        const elevatedEmbed =
            embedType === 'carousel' || embedType === 'product_page';
        container.style.zIndex = elevatedEmbed ? '1000' : '';
        container.style.isolation = elevatedEmbed ? 'isolate' : '';
        script.parentNode?.insertBefore(container, script.nextSibling);
    }

    createApp(EmbedShell, {
        embedSlug: slug,
        embedType,
        embedName,
    }).mount(container);

    script.setAttribute('data-supreme-mounted', 'true');
}

bootstrap();
