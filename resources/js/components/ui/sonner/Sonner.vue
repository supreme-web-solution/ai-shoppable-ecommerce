<script setup lang="ts">
import { Toaster as SonnerPrimitive } from 'vue-sonner';
import 'vue-sonner/style.css';
</script>

<template>
    <SonnerPrimitive
        theme="light"
        class="toaster group"
        position="bottom-right"
        :style="{
            '--normal-bg': 'var(--popover)',
            '--normal-text': 'var(--popover-foreground)',
            '--normal-border': 'var(--border)',
        }"
    />
</template>
