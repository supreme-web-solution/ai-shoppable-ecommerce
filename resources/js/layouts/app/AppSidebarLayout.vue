<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            class="h-svh  md:h-[calc(100svh-1rem)]"
            >
            <!-- class="h-svh overflow-x-hidden overflow-y-auto md:h-[calc(100svh-1rem)]" -->
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div class="overflow-x-hidden overflow-y-auto h-full">
                <slot />
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
