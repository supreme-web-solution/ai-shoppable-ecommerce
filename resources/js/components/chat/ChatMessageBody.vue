<script setup lang="ts">
import { computed } from 'vue';
import LinkPreviewCard from '@/components/chat/LinkPreviewCard.vue';
import { extractLinkHrefs, parseMessageParts } from '@/lib/linkifyMessage';

const props = withDefaults(
    defineProps<{
        text: string;
        variant?: 'default' | 'on-primary' | 'embed';
        showLinkPreviews?: boolean;
    }>(),
    {
        variant: 'default',
        showLinkPreviews: true,
    },
);

const parts = computed(() => parseMessageParts(props.text));
const previewUrls = computed(() =>
    props.showLinkPreviews ? extractLinkHrefs(props.text).slice(0, 2) : [],
);
</script>

<template>
    <span class="chat-message-body" :class="`chat-message-body--${variant}`">
        <template v-for="(part, index) in parts" :key="index">
            <a
                v-if="part.type === 'link'"
                :href="part.href"
                target="_blank"
                rel="noopener noreferrer"
                class="chat-message-link"
                @click.stop
            >{{ part.text }}</a>
            <span v-else>{{ part.text }}</span>
        </template>
        <LinkPreviewCard
            v-for="url in previewUrls"
            :key="url"
            :url="url"
            :variant="variant"
        />
    </span>
</template>

<style scoped>
.chat-message-body {
    display: block;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.chat-message-link {
    text-decoration: underline;
    text-underline-offset: 2px;
}

.chat-message-link:hover {
    opacity: 0.9;
}

.chat-message-body--default .chat-message-link {
    color: #2563eb;
}

.chat-message-body--on-primary .chat-message-link {
    color: #fff;
    font-weight: 600;
}

.chat-message-body--embed .chat-message-link {
    color: #e8563a;
    font-weight: 600;
}
</style>
