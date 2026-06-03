<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, CheckCheck } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useInitials } from '@/composables/useInitials';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const showAvatar = computed(
    () => user.value?.avatar && user.value.avatar !== '',
);

type NotificationItem = {
    id: string;
    title: string;
    body: string;
    time: string;
    href?: string;
    unread?: boolean;
};

const notifications = ref<NotificationItem[]>([
    {
        id: '1',
        title: 'Embed activity',
        body: 'Your shoppable feed recorded new views in the last 24 hours.',
        time: '2h ago',
        href: '/analytics',
        unread: true,
    },
    {
        id: '2',
        title: 'Ready to publish',
        body: 'Finish tagging products on your latest video to go live.',
        time: 'Yesterday',
        href: '/content',
        unread: true,
    },
    {
        id: '3',
        title: 'Playlist tip',
        body: 'Copy your embed snippet from Playlists and paste it on your store.',
        time: '3d ago',
        href: '/playlists',
        unread: false,
    },
]);

const unreadCount = computed(
    () => notifications.value.filter((n) => n.unread).length,
);

function markAllRead() {
    notifications.value = notifications.value.map((n) => ({ ...n, unread: false }));
}

function markRead(id: string) {
    notifications.value = notifications.value.map((n) =>
        n.id === id ? { ...n, unread: false } : n,
    );
}
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center justify-between gap-3 border-b border-sidebar-border/70 bg-white px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex min-w-0 items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <div v-if="user" class="flex shrink-0 items-center gap-1.5">
            <!-- Notifications -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="relative flex size-9 items-center justify-center rounded-xl text-gray-500 transition hover:bg-gray-100 hover:text-gray-800"
                        aria-label="Notifications"
                    >
                        <Bell class="size-[18px]" />
                        <span
                            v-if="unreadCount > 0"
                            class="absolute right-1.5 top-1.5 flex size-4 items-center justify-center rounded-full bg-[#E8563A] text-[9px] font-bold text-white ring-2 ring-white"
                        >
                            {{ unreadCount > 9 ? '9+' : unreadCount }}
                        </span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-80 rounded-xl p-0">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                        <p class="text-sm font-bold text-gray-900">Notifications</p>
                        <button
                            v-if="unreadCount > 0"
                            type="button"
                            class="flex items-center gap-1 text-[11px] font-semibold text-[#E8563A] hover:underline"
                            @click="markAllRead"
                        >
                            <CheckCheck class="size-3.5" />
                            Mark all read
                        </button>
                    </div>
                    <div class="max-h-72 overflow-y-auto">
                        <template v-if="notifications.length">
                            <component
                                :is="item.href ? Link : 'div'"
                                v-for="item in notifications"
                                :key="item.id"
                                :href="item.href"
                                class="flex gap-3 border-b border-gray-50 px-4 py-3 text-left transition last:border-0 hover:bg-gray-50"
                                :class="item.href ? 'cursor-pointer no-underline' : ''"
                                @click="markRead(item.id)"
                            >
                                <span
                                    class="mt-1.5 size-2 shrink-0 rounded-full"
                                    :class="item.unread ? 'bg-[#E8563A]' : 'bg-transparent'"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-semibold text-gray-900">{{ item.title }}</p>
                                    <p class="mt-0.5 text-[11px] leading-snug text-gray-500">{{ item.body }}</p>
                                    <p class="mt-1 text-[10px] text-gray-400">{{ item.time }}</p>
                                </div>
                            </component>
                        </template>
                        <p v-else class="px-4 py-8 text-center text-xs text-gray-400">
                            No notifications yet
                        </p>
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>

            <!-- User menu: name + avatar -->
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="flex max-w-[200px] items-center gap-2 rounded-xl py-1 pl-1 pr-2 outline-none transition hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-[#E8563A]/30 sm:max-w-[240px]"
                        aria-label="Account menu"
                    >
                        <Avatar class="size-8 shrink-0 overflow-hidden rounded-full ring-1 ring-gray-200">
                            <AvatarImage
                                v-if="showAvatar"
                                :src="user.avatar!"
                                :alt="user.name"
                            />
                            <AvatarFallback class="rounded-full bg-[#E8563A]/10 text-xs font-bold text-[#E8563A]">
                                {{ getInitials(user.name) }}
                            </AvatarFallback>
                        </Avatar>
                        <span class="min-w-0 text-left">
                            <span class="block max-w-[120px] truncate text-sm font-semibold leading-tight text-gray-900 sm:max-w-[160px]">
                                {{ user.name }}
                            </span>
                            <span class="hidden truncate text-[11px] leading-tight text-gray-400 md:block">
                                {{ user.email }}
                            </span>
                        </span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="min-w-56 rounded-xl">
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </header>
</template>
