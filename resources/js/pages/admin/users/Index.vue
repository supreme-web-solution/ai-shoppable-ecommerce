<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Loader2,
    Pencil,
    PlusCircle,
    Search,
    ShieldCheck,
    Trash2,
    UserRound,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import PasswordInput from '@/components/PasswordInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminApi } from '@/composables/useAdminApi';

type TeamOption = {
    id: number;
    name: string;
    slug: string;
};

type PlatformUser = {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string | null;
    team_id?: number | null;
    is_platform_admin?: boolean;
    current_team?: { id: number; name: string; slug: string } | null;
    teams_count?: number;
    owned_teams_count?: number;
    created_at?: string;
};

type PaginatedUsers = {
    data: PlatformUser[];
    meta?: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Users', href: '/admin/users' },
        ],
    },
});

const { apiFetch, postJson, patchJson, deleteResource } = useAdminApi();

const loading = ref(false);
const saving = ref(false);
const deletingId = ref<number | null>(null);
const errorText = ref('');
const successText = ref('');
const searchQuery = ref('');
const users = ref<PlatformUser[]>([]);
const teams = ref<TeamOption[]>([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const modalOpen = ref(false);
const deleteTarget = ref<PlatformUser | null>(null);
const editingUserId = ref<number | null>(null);
let searchDebounceTimer: number | null = null;

const form = ref({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    team_id: null as number | null,
    mark_verified: true,
});

const isEditing = computed(() => editingUserId.value !== null);

const modalTitle = computed(() => (isEditing.value ? 'Edit user' : 'Create user'));

function resetForm() {
    form.value = {
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        team_id: null,
        mark_verified: true,
    };
}

function openCreateModal() {
    editingUserId.value = null;
    resetForm();
    modalOpen.value = true;
}

function openEditModal(user: PlatformUser) {
    editingUserId.value = user.id;
    form.value = {
        name: user.name,
        email: user.email,
        password: '',
        password_confirmation: '',
        team_id: user.team_id ?? user.current_team?.id ?? null,
        mark_verified: Boolean(user.email_verified_at),
    };
    modalOpen.value = true;
}

function closeModal(force = false) {
    if (!force && saving.value) {
        return;
    }

    modalOpen.value = false;
    editingUserId.value = null;
    resetForm();
}

async function loadTeams() {
    try {
        const payload = await apiFetch<{ data?: TeamOption[] }>('/api/v1/admin/teams');

        teams.value = payload.data ?? [];
    } catch {
        teams.value = [];
    }
}

function normalizeUsersPayload(payload: PaginatedUsers | PlatformUser[]): {
    rows: PlatformUser[];
    meta: PaginatedUsers['meta'];
} {
    if (Array.isArray(payload)) {
        return { rows: payload, meta: undefined };
    }

    const rows = Array.isArray(payload.data) ? payload.data : [];

    return { rows, meta: payload.meta };
}

async function loadUsers(page = 1) {
    loading.value = true;
    errorText.value = '';

    try {
        const params = new URLSearchParams({
            page: String(page),
            per_page: '25',
        });

        const term = searchQuery.value.trim();

        if (term !== '') {
            params.set('search', term);
        }

        const payload = await apiFetch<PaginatedUsers>(
            `/api/v1/admin/platform/users?${params.toString()}`,
        );

        const { rows, meta } = normalizeUsersPayload(payload);

        users.value = rows;
        pagination.value = {
            current_page: meta?.current_page ?? page,
            last_page: meta?.last_page ?? 1,
            total: meta?.total ?? rows.length,
        };
    } catch (error) {
        errorText.value =
            error instanceof Error ? error.message : 'Could not load users.';
    } finally {
        loading.value = false;
    }
}

function clearSearch() {
    searchQuery.value = '';
}

async function saveUser() {
    saving.value = true;
    errorText.value = '';
    successText.value = '';

    try {
        const body: Record<string, unknown> = {
            name: form.value.name.trim(),
            email: form.value.email.trim(),
            team_id: form.value.team_id,
            mark_verified: form.value.mark_verified,
        };

        if (isEditing.value && editingUserId.value) {
            if (form.value.password.trim()) {
                body.password = form.value.password;
                body.password_confirmation = form.value.password_confirmation;
            }

            await patchJson(`/api/v1/admin/platform/users/${editingUserId.value}`, body);
            successText.value = 'User updated.';
        } else {
            body.password = form.value.password;
            body.password_confirmation = form.value.password_confirmation;

            await postJson('/api/v1/admin/platform/users', body);
            successText.value = 'User created.';
        }

        closeModal(true);
        await loadUsers(pagination.value.current_page);
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not save user.';
    } finally {
        saving.value = false;
    }
}

async function confirmDelete() {
    if (!deleteTarget.value) {
        return;
    }

    deletingId.value = deleteTarget.value.id;
    errorText.value = '';

    try {
        await deleteResource(`/api/v1/admin/platform/users/${deleteTarget.value.id}`);
        successText.value = 'User deleted.';
        deleteTarget.value = null;
        await loadUsers(pagination.value.current_page);
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not delete user.';
    } finally {
        deletingId.value = null;
    }
}

function formatDate(value?: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString();
}

watch(searchQuery, () => {
    if (searchDebounceTimer !== null) {
        window.clearTimeout(searchDebounceTimer);
    }

    searchDebounceTimer = window.setTimeout(() => {
        void loadUsers(1);
    }, 300);
});

onMounted(async () => {
    await Promise.all([loadTeams(), loadUsers()]);
});

onBeforeUnmount(() => {
    if (searchDebounceTimer !== null) {
        window.clearTimeout(searchDebounceTimer);
    }
});
</script>

<template>
    <Head title="User management" />

    <div class="page-root flex min-h-screen flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="mx-auto w-full max-w-6xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-[#E8563A]/10 px-3 py-1 text-xs font-bold text-[#E8563A]">
                    <ShieldCheck class="size-3.5" />
                    Platform admin
                </div>
                <h1 class="text-2xl font-black tracking-tight text-gray-900">Users</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Manage all accounts on the platform. Only emails in <code class="rounded bg-muted px-1">ADMIN_EMAILS</code> can access this page.
                </p>
            </div>
            <Button class="bg-[#E8563A] hover:bg-[#D44A2F]" @click="openCreateModal">
                <PlusCircle class="mr-2 size-4" />
                New user
            </Button>
        </div>

        <p v-if="successText" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
            {{ successText }}
        </p>
        <p v-if="errorText" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ errorText }}
        </p>

        <div class="users-card rounded-2xl border border-[#F0EDE8] bg-white p-4">
            <div class="relative mb-4 max-w-md">
                <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    v-model="searchQuery"
                    class="pl-9 pr-9"
                    placeholder="Search by name or email…"
                    type="search"
                />
                <button
                    v-if="searchQuery"
                    type="button"
                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-xs text-muted-foreground hover:text-foreground"
                    aria-label="Clear search"
                    @click="clearSearch"
                >
                    ×
                </button>
            </div>

            <div v-if="loading" class="space-y-2">
                <Skeleton v-for="n in 6" :key="n" class="h-14 w-full" />
            </div>

            <div v-else-if="users.length === 0" class="rounded-lg border border-dashed py-12 text-center text-sm text-muted-foreground">
                No users found.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b text-xs uppercase tracking-wide text-muted-foreground">
                            <th class="px-3 py-2 font-semibold">User</th>
                            <th class="px-3 py-2 font-semibold">Team</th>
                            <th class="px-3 py-2 font-semibold">Status</th>
                            <th class="px-3 py-2 font-semibold">Joined</th>
                            <th class="px-3 py-2 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users"
                            :key="user.id"
                            class="border-b border-gray-100 last:border-0"
                        >
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="flex size-9 items-center justify-center rounded-full bg-gray-100 text-gray-500">
                                        <UserRound class="size-4" />
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-900">{{ user.name }}</p>
                                        <p class="text-xs text-muted-foreground">{{ user.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-muted-foreground">
                                {{ user.current_team?.name ?? '—' }}
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <Badge v-if="user.email_verified_at" variant="secondary">Verified</Badge>
                                    <Badge v-else variant="outline">Unverified</Badge>
                                    <Badge v-if="user.is_platform_admin" class="bg-[#E8563A]/10 text-[#E8563A]">
                                        Admin
                                    </Badge>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-muted-foreground">
                                {{ formatDate(user.created_at) }}
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex justify-end gap-1">
                                    <Button size="icon" variant="ghost" @click="openEditModal(user)">
                                        <Pencil class="size-4" />
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        class="text-red-600 hover:text-red-700"
                                        :disabled="deletingId === user.id"
                                        @click="deleteTarget = user"
                                    >
                                        <Loader2 v-if="deletingId === user.id" class="size-4 animate-spin" />
                                        <Trash2 v-else class="size-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="pagination.last_page > 1"
                class="mt-4 flex items-center justify-between text-sm text-muted-foreground"
            >
                <span>{{ pagination.total }} users</span>
                <div class="flex gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="pagination.current_page <= 1 || loading"
                        @click="loadUsers(pagination.current_page - 1)"
                    >
                        Previous
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="pagination.current_page >= pagination.last_page || loading"
                        @click="loadUsers(pagination.current_page + 1)"
                    >
                        Next
                    </Button>
                </div>
            </div>
        </div>

        <Dialog :open="modalOpen" @update:open="(open: boolean) => !open && closeModal()">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ modalTitle }}</DialogTitle>
                    <DialogDescription>
                        {{
                            isEditing
                                ? 'Update profile, team, or password. Leave password blank to keep the current one.'
                                : 'Creates a user and a new store team unless you assign an existing team.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 py-2">
                    <div class="space-y-1.5">
                        <Label>Name</Label>
                        <Input v-model="form.name" autocomplete="name" />
                    </div>
                    <div class="space-y-1.5">
                        <Label>Email</Label>
                        <Input v-model="form.email" type="email" autocomplete="email" />
                    </div>
                    <div class="space-y-1.5">
                        <Label>{{ isEditing ? 'New password (optional)' : 'Password' }}</Label>
                        <PasswordInput
                            v-model="form.password"
                            autocomplete="new-password"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label>Confirm password</Label>
                        <PasswordInput
                            v-model="form.password_confirmation"
                            autocomplete="new-password"
                        />
                    </div>
                    <div class="space-y-1.5">
                        <Label>Current team</Label>
                        <select
                            v-model="form.team_id"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                        >
                            <option :value="null">
                                {{ isEditing ? '— No change —' : '— Create new team —' }}
                            </option>
                            <option v-for="team in teams" :key="team.id" :value="team.id">
                                {{ team.name }}
                            </option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.mark_verified" type="checkbox" class="rounded border">
                        Email verified
                    </label>
                </div>

                <DialogFooter>
                    <Button variant="outline" :disabled="saving" @click="closeModal">Cancel</Button>
                    <Button class="bg-[#E8563A] hover:bg-[#D44A2F]" :disabled="saving" @click="saveUser">
                        <Loader2 v-if="saving" class="mr-2 size-4 animate-spin" />
                        {{ isEditing ? 'Save changes' : 'Create user' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="Boolean(deleteTarget)" @update:open="(open: boolean) => !open && (deleteTarget = null)">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Delete user?</DialogTitle>
                    <DialogDescription>
                        This permanently removes
                        <strong>{{ deleteTarget?.name }}</strong>
                        ({{ deleteTarget?.email }}). Users who own teams cannot be deleted until ownership is transferred.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="deleteTarget = null">Cancel</Button>
                    <Button variant="destructive" :disabled="deletingId !== null" @click="confirmDelete">
                        Delete
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        </div>
    </div>
</template>

<style scoped>
.page-root {
    background-color: #f2efea;
    min-height: 100%;
}

.users-card {
    box-shadow:
        0 1px 3px rgba(0, 0, 0, 0.04),
        0 4px 16px rgba(0, 0, 0, 0.06);
}
</style>
