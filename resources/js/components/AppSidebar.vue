<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    Clapperboard,
    Film,
    GraduationCap,
    LayoutGrid,
    Layers3,
    MessageSquare,
    Package,
    Receipt,
    Settings,
    UserRound,
    ShieldCheck,
    Sparkles,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

type NavItem = {
    title: string;
    href: string;
    icon: object;
};

type NavSection = {
    label: string;
    items: NavItem[];
};

const baseNavSections: NavSection[] = [
    {
        label: 'Overview',
        items: [
            { title: 'Tutorial', href: '/tutorial', icon: GraduationCap },
            { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
            { title: 'Analytics', href: '/analytics', icon: BarChart3 },
        ],
    },
    {
        label: 'Commerce',
        items: [
            { title: 'Shoppable Videos', href: '/content', icon: Film },
            { title: 'Create Video', href: '/content/create', icon: Sparkles },
            { title: 'Products', href: '/products', icon: Package },
            { title: 'Orders', href: '/orders', icon: Receipt },
            { title: 'Leads', href: '/leads', icon: UserRound },
            { title: 'Playlists', href: '/playlists', icon: Layers3 },
        ],
    },
    {
        label: 'Live',
        items: [
            { title: 'Live Cast', href: '/live-shows', icon: Clapperboard },
            { title: 'Chats', href: '/live-shows/chats', icon: MessageSquare },
        ],
    },
    {
        label: 'Account',
        items: [
            { title: 'Stores', href: '/teams', icon: Users },
            { title: 'Integrations', href: '/settings/integrations', icon: Settings },
        ],
    },
];

const page = usePage();
const appName = computed(() => (page.props as { name?: string }).name ?? 'My Stream Cart');

const navSections = computed((): NavSection[] => {
    const sections = [...baseNavSections];

    if ((page.props as { isPlatformAdmin?: boolean }).isPlatformAdmin) {
        sections.push({
            label: 'Platform',
            items: [{ title: 'Users', href: '/admin/users', icon: ShieldCheck }],
        });
    }

    return sections;
});
const currentPath = computed(() => {
    try {
        return new URL(page.url).pathname;
    } catch {
        return page.url.split('?')[0];
    }
});

function isActive(href: string) {
    const path = currentPath.value;

    if (href === '/tutorial' || href === '/dashboard') {
return path === href;
}

    if (href === '/content') {
return path === '/content' || (path.startsWith('/content/') && !path.startsWith('/content/create'));
}

    if (href === '/admin/users') {
        return path === '/admin/users' || path.startsWith('/admin/users/');
    }

    return path === href || path.startsWith(href + '/');
}
</script>

<template>
    <Sidebar collapsible="icon" variant="inset" class="app-sb">
        <!-- Brand -->
        <SidebarHeader class="border-b border-gray-100 pb-3">
            <SidebarMenu>
                <SidebarMenuItem>
                    <Link href="/dashboard" class="flex items-center gap-3 px-2 py-1.5 no-underline">
                        <div class="brand-mark flex size-9 shrink-0 items-center justify-center rounded-xl">
                            <Film class="size-5 text-white" />
                        </div>
                        <div class="grid leading-tight">
                            <span class="truncate text-sm font-extrabold text-gray-900">{{ appName }}</span>
                            <span class="truncate text-[10px] text-gray-400">Shoppable Video Commerce</span>
                        </div>
                    </Link>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="px-2 py-3">
            <div v-for="section in navSections" :key="section.label" class="mb-4">
                <p class="mb-1.5 px-2.5 text-[9px] font-bold uppercase tracking-[0.15em] text-gray-400">
                    {{ section.label }}
                </p>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in section.items" :key="item.href">
                        <Link
                            :href="item.href"
                            :class="[
                                'sb-item flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-sm transition-all no-underline',
                                isActive(item.href) ? 'sb-item-active' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-800',
                            ]"
                        >
                            <div :class="['sb-icon flex size-7 shrink-0 items-center justify-center rounded-lg', isActive(item.href) ? 'sb-icon-active' : '']">
                                <component :is="item.icon" class="size-4" />
                            </div>
                            <span class="truncate font-medium">{{ item.title }}</span>
                        </Link>
                    </SidebarMenuItem>
                </SidebarMenu>
            </div>
        </SidebarContent>

        <SidebarFooter class="border-t border-gray-100 pt-2">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

<style scoped>
/* Sidebar: clean white to match the financial dashboard aesthetic */
:deep([data-slot="sidebar"]) {
    background: #FFFFFF !important;
    border-right: 1px solid #F0EDE8 !important;
}

/* Brand mark: coral gradient */
.brand-mark {
    background: linear-gradient(135deg, #E8563A, #ff8c42);
    box-shadow: 0 4px 12px rgba(232,86,58,0.35);
}

/* Active nav item */
.sb-item-active {
    background: rgba(232,86,58,0.08);
    color: #E8563A;
    font-weight: 700;
}
.sb-item-active:hover {
    background: rgba(232,86,58,0.12);
}

/* Active icon */
.sb-icon-active {
    background: rgba(232,86,58,0.12);
    color: #E8563A;
    box-shadow: 0 0 0 1px rgba(232,86,58,0.2);
}
.sb-icon-active > * {
    color: #E8563A;
}
</style>
