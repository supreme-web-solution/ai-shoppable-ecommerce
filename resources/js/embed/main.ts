import { createApp } from 'vue';
import EmbedShell from '@/embed/EmbedShell.vue';
import { normalizeEmbedDisplayType } from '@/lib/videoEmbed';

const root = document.getElementById('embed-player-app');

if (root) {
    createApp(EmbedShell, {
        embedSlug: root.getAttribute('data-embed-slug') ?? '',
        embedType: normalizeEmbedDisplayType(
            root.getAttribute('data-embed-type'),
        ),
        embedName: root.getAttribute('data-embed-name') ?? '',
    }).mount(root);
}
