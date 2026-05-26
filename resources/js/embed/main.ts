import { createApp } from 'vue';
import EmbedShell from '@/embed/EmbedShell.vue';

const root = document.getElementById('embed-player-app');

if (root) {
    createApp(EmbedShell, {
        embedSlug: root.getAttribute('data-embed-slug') ?? '',
        embedType: root.getAttribute('data-embed-type') ?? 'vertical_feed',
        embedName: root.getAttribute('data-embed-name') ?? '',
    }).mount(root);
}
