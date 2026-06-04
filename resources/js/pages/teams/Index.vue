<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Check,
    Layers3,
    Loader2,
    Mail,
    Package,
    PlusCircle,
    Settings,
    Trash2,
    UserPlus,
    Users,
    Video,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminApi } from '@/composables/useAdminApi';

type TeamItem = {
    id: number;
    name: string;
    slug: string;
    checkout_mode: string;
    external_provider: string;
    is_active: boolean;
    is_current: boolean;
    role: string;
    owner?: { id: number; name: string; email: string } | null;
    counts: {
        videos: number;
        products: number;
        playlists: number;
        embeds: number;
        live_shows: number;
    };
    created_at?: string;
};

type TeamMember = {
    id: number;
    name: string;
    email: string;
    role: string;
    joined_at?: string;
};

type PendingInvite = {
    id: number;
    email: string;
    role: string;
    expires_at?: string;
    invited_by?: { id: number; name: string } | null;
    created_at?: string;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Teams', href: '/teams' },
        ],
    },
});

const { apiFetch, postJson, patchJson, deleteResource } = useAdminApi();

const loading = ref(false);
const saving = ref(false);
const switchingId = ref<number | null>(null);
const errorText = ref('');
const successText = ref('');
const teams = ref<TeamItem[]>([]);
const currentTeamId = ref(0);
const createModalOpen = ref(false);
const membersModalOpen = ref(false);
const membersLoading = ref(false);
const membersSaving = ref(false);
const selectedTeam = ref<TeamItem | null>(null);
const members = ref<TeamMember[]>([]);
const pendingInvites = ref<PendingInvite[]>([]);

const form = ref({
    name: '',
    slug: '',
});

const inviteForm = ref({
    email: '',
    role: 'member',
});

const currentTeam = computed(() => teams.value.find((t) => t.is_current) ?? null);
const canManageMembers = computed(() => {
    const role = selectedTeam.value?.role;

    return role === 'owner' || role === 'admin';
});

function slugify(value: string): string {
    return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

function checkoutLabel(mode: string, provider: string): string {
    if (mode === 'external' && provider !== 'none') {
        return `${mode} · ${provider}`;
    }

    return mode;
}

async function loadTeams(silent = false) {
    if (!silent) {
        loading.value = true;
    }
    errorText.value = '';

    try {
        const payload = await apiFetch<{ data: TeamItem[]; current_team_id: number }>(
            '/api/v1/admin/teams',
        );
        teams.value = payload.data ?? [];
        currentTeamId.value = payload.current_team_id ?? 0;
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load teams.';
        if (!silent) {
            teams.value = [];
        }
    } finally {
        if (!silent) {
            loading.value = false;
        }
    }
}

async function loadMembers(team: TeamItem) {
    membersLoading.value = true;

    try {
        const payload = await apiFetch<{ members: TeamMember[]; pending_invites: PendingInvite[] }>(
            `/api/v1/admin/teams/${team.id}/members`,
        );
        members.value = payload.members ?? [];
        pendingInvites.value = payload.pending_invites ?? [];
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not load team members.';
        members.value = [];
        pendingInvites.value = [];
    } finally {
        membersLoading.value = false;
    }
}

async function openMembersModal(team: TeamItem) {
    selectedTeam.value = team;
    inviteForm.value = { email: '', role: 'member' };
    membersModalOpen.value = true;
    await loadMembers(team);
}

async function activateTeam(team: TeamItem) {
    if (team.is_current) {
        return;
    }

    switchingId.value = team.id;
    errorText.value = '';
    successText.value = '';

    try {
        await apiFetch(`/api/v1/admin/teams/${team.id}/activate`, { method: 'POST' });
        await loadTeams(true);
        await router.reload({ only: ['auth'] });
        successText.value = `Switched to "${team.name}".`;
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not switch team.';
    } finally {
        switchingId.value = null;
    }
}

async function createTeam() {
    if (!form.value.name.trim()) {
        errorText.value = 'Team name is required.';

        return;
    }

    saving.value = true;
    errorText.value = '';

    try {
        const created = await postJson<TeamItem>('/api/v1/admin/teams', {
            name: form.value.name.trim(),
            slug: form.value.slug.trim() || slugify(form.value.name),
        });

        await apiFetch(`/api/v1/admin/teams/${created.id}/activate`, { method: 'POST' });
        createModalOpen.value = false;
        form.value = { name: '', slug: '' };
        await loadTeams(true);
        await router.reload({ only: ['auth'] });
        successText.value = `Created "${created.name}" and set it as your active workspace.`;
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not create team.';
    } finally {
        saving.value = false;
    }
}

async function removeTeam(team: TeamItem) {
    if (team.is_current) {
        errorText.value = 'Switch to another team before deleting this workspace.';

        return;
    }

    if (!window.confirm(`Delete team "${team.name}"? Videos and products in this workspace will be removed.`)) {
        return;
    }

    saving.value = true;
    errorText.value = '';

    try {
        await deleteResource(`/api/v1/admin/teams/${team.id}`);
        successText.value = `Deleted "${team.name}".`;
        await loadTeams();
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not delete team.';
    } finally {
        saving.value = false;
    }
}

async function sendInvite() {
    if (!selectedTeam.value || !inviteForm.value.email.trim()) {
        return;
    }

    membersSaving.value = true;
    errorText.value = '';

    try {
        const result = await postJson<{ status: string; message?: string }>(
            `/api/v1/admin/teams/${selectedTeam.value.id}/invites`,
            {
                email: inviteForm.value.email.trim(),
                role: inviteForm.value.role,
            },
        );

        inviteForm.value.email = '';
        successText.value = result.status === 'added'
            ? 'Member added to the team.'
            : 'Invitation sent by email.';
        await loadMembers(selectedTeam.value);
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not send invitation.';
    } finally {
        membersSaving.value = false;
    }
}

async function updateMemberRole(member: TeamMember, role: string) {
    if (!selectedTeam.value || member.role === role) {
        return;
    }

    membersSaving.value = true;
    errorText.value = '';

    try {
        await patchJson(`/api/v1/admin/teams/${selectedTeam.value.id}/members/${member.id}`, { role });
        await loadMembers(selectedTeam.value);
        successText.value = `Updated ${member.name}'s role.`;
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not update member role.';
    } finally {
        membersSaving.value = false;
    }
}

async function removeMember(member: TeamMember) {
    if (!selectedTeam.value) {
        return;
    }

    if (!window.confirm(`Remove ${member.name} from ${selectedTeam.value.name}?`)) {
        return;
    }

    membersSaving.value = true;
    errorText.value = '';

    try {
        await deleteResource(`/api/v1/admin/teams/${selectedTeam.value.id}/members/${member.id}`);
        await loadMembers(selectedTeam.value);
        successText.value = `Removed ${member.name}.`;
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not remove member.';
    } finally {
        membersSaving.value = false;
    }
}

async function revokeInvite(invite: PendingInvite) {
    if (!selectedTeam.value) {
        return;
    }

    membersSaving.value = true;
    errorText.value = '';

    try {
        await deleteResource(`/api/v1/admin/teams/${selectedTeam.value.id}/invites/${invite.id}`);
        await loadMembers(selectedTeam.value);
        successText.value = `Revoked invite for ${invite.email}.`;
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not revoke invitation.';
    } finally {
        membersSaving.value = false;
    }
}

onMounted(loadTeams);
</script>

<template>
    <Head title="Teams" />

    <div class="page-root min-h-screen px-4 py-6 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-5xl">
            <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="page-icon mb-3 flex size-11 items-center justify-center rounded-2xl">
                        <Users class="size-5 text-white" />
                    </div>
                    <h1 class="text-2xl font-black tracking-tight text-gray-900">Teams</h1>
                    <p class="mt-1 max-w-2xl text-sm text-gray-500">
                        A team is your brand workspace. Videos, products, playlists, embeds, analytics, and
                        integrations all belong to the <strong>active team</strong> shown below.
                    </p>
                </div>
                <Button class="cta-btn" @click="createModalOpen = true">
                    <PlusCircle class="mr-2 size-4" />
                    New team
                </Button>
            </div>

            <div
                v-if="currentTeam"
                class="mb-6 rounded-2xl border border-[#E8563A]/25 bg-[#E8563A]/5 p-4 sm:p-5"
            >
                <p class="text-xs font-bold uppercase tracking-wide text-[#E8563A]">Active workspace</p>
                <p class="mt-1 text-lg font-bold text-gray-900">{{ currentTeam.name }}</p>
                <p class="mt-0.5 font-mono text-xs text-gray-500">{{ currentTeam.slug }}</p>
                <p class="mt-2 text-sm text-gray-600">
                    Dashboard, content, and checkout settings use this team until you switch.
                </p>
                <Link
                    href="/settings/integrations"
                    class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-[#E8563A] underline"
                >
                    <Settings class="size-3.5" />
                    Team integrations (Shopify, Stripe, PayPal, etc.)
                </Link>
            </div>

            <div v-if="errorText" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ errorText }}
            </div>
            <div
                v-else-if="successText"
                class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"
            >
                {{ successText }}
            </div>

            <div v-if="loading" class="space-y-3">
                <Skeleton v-for="i in 3" :key="i" class="h-36 w-full rounded-2xl" />
            </div>

            <div v-else-if="teams.length === 0" class="rounded-2xl border bg-white p-10 text-center">
                <Users class="mx-auto size-10 text-gray-300" />
                <p class="mt-3 font-semibold text-gray-800">No teams yet</p>
                <p class="mt-1 text-sm text-gray-500">Create a workspace for your first brand or store.</p>
                <Button class="cta-btn mt-4" @click="createModalOpen = true">Create team</Button>
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="team in teams"
                    :key="team.id"
                    class="rounded-2xl border bg-white p-4 shadow-sm sm:p-5"
                    :class="team.is_current ? 'border-[#E8563A]/40 ring-1 ring-[#E8563A]/15' : 'border-gray-100'"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-bold text-gray-900">{{ team.name }}</h2>
                                <Badge v-if="team.is_current" class="bg-[#E8563A] text-white hover:bg-[#E8563A]">
                                    Active
                                </Badge>
                                <Badge variant="outline" class="capitalize">{{ team.role }}</Badge>
                                <Badge v-if="!team.is_active" variant="secondary">Inactive</Badge>
                            </div>
                            <p class="mt-0.5 font-mono text-xs text-gray-400">{{ team.slug }}</p>
                            <p class="mt-1 text-xs text-gray-500">
                                Checkout: {{ checkoutLabel(team.checkout_mode, team.external_provider) }}
                            </p>
                            <p v-if="team.owner" class="mt-1 text-xs text-gray-500">
                                Owner: {{ team.owner.name }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="membersSaving"
                                @click="openMembersModal(team)"
                            >
                                <UserPlus class="mr-1.5 size-3.5" />
                                Members
                            </Button>
                            <Button
                                v-if="!team.is_current"
                                size="sm"
                                class="cta-btn"
                                :disabled="switchingId !== null || saving"
                                @click="activateTeam(team)"
                            >
                                <Loader2 v-if="switchingId === team.id" class="mr-1.5 size-3.5 animate-spin" />
                                <Check v-else class="mr-1.5 size-3.5" />
                                Switch here
                            </Button>
                            <Button
                                v-if="team.role === 'owner' && !team.is_current"
                                size="sm"
                                variant="outline"
                                class="text-red-600 hover:text-red-700"
                                :disabled="saving"
                                @click="removeTeam(team)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-5">
                        <div class="rounded-xl bg-gray-50 px-3 py-2 text-center">
                            <Video class="mx-auto size-4 text-gray-400" />
                            <p class="mt-1 text-lg font-bold text-gray-900">{{ team.counts.videos }}</p>
                            <p class="text-[10px] text-gray-500">Videos</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 px-3 py-2 text-center">
                            <Package class="mx-auto size-4 text-gray-400" />
                            <p class="mt-1 text-lg font-bold text-gray-900">{{ team.counts.products }}</p>
                            <p class="text-[10px] text-gray-500">Products</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 px-3 py-2 text-center">
                            <Layers3 class="mx-auto size-4 text-gray-400" />
                            <p class="mt-1 text-lg font-bold text-gray-900">{{ team.counts.playlists }}</p>
                            <p class="text-[10px] text-gray-500">Playlists</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 px-3 py-2 text-center">
                            <p class="mt-1 text-lg font-bold text-gray-900">{{ team.counts.embeds }}</p>
                            <p class="text-[10px] text-gray-500">Embeds</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 px-3 py-2 text-center">
                            <p class="mt-1 text-lg font-bold text-gray-900">{{ team.counts.live_shows }}</p>
                            <p class="text-[10px] text-gray-500">Live shows</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-8 rounded-2xl border border-dashed border-gray-200 bg-white/80 p-5 text-sm text-gray-600">
                <p class="font-semibold text-gray-800">What teams do</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    <li>Isolate content and commerce per brand or client.</li>
                    <li>Invite collaborators as admins or members to help manage content.</li>
                    <li>Keep Shopify, Stripe, PayPal, and Zernio settings separate per team.</li>
                    <li>Scope analytics and embed players to the active team only.</li>
                </ul>
            </div>
        </div>
    </div>

    <Dialog v-model:open="createModalOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Create team</DialogTitle>
                <DialogDescription>
                    Add another brand workspace. You will be switched to it immediately after creation.
                </DialogDescription>
            </DialogHeader>
            <div class="space-y-3 py-2">
                <div class="space-y-1.5">
                    <Label for="team-name">Team name</Label>
                    <Input
                        id="team-name"
                        v-model="form.name"
                        placeholder="Acme Store"
                        @input="!form.slug && (form.slug = slugify(form.name))"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="team-slug">URL slug</Label>
                    <Input id="team-slug" v-model="form.slug" class="font-mono text-sm" placeholder="acme-store" />
                </div>
            </div>
            <DialogFooter>
                <Button variant="ghost" @click="createModalOpen = false">Cancel</Button>
                <Button class="cta-btn" :disabled="saving" @click="createTeam">
                    <Loader2 v-if="saving" class="mr-2 size-4 animate-spin" />
                    Create & switch
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="membersModalOpen">
        <DialogContent class="members-modal flex max-h-[min(85dvh,calc(100vh-2rem))] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg">
            <DialogHeader class="shrink-0 border-b px-6 pt-6 pb-4">
                <DialogTitle>Team members</DialogTitle>
                <DialogDescription v-if="selectedTeam">
                    Manage collaborators for <strong>{{ selectedTeam.name }}</strong>.
                </DialogDescription>
            </DialogHeader>

            <div v-if="canManageMembers && !membersLoading" class="shrink-0 border-b bg-gray-50 px-6 py-4">
                <p class="text-sm font-semibold text-gray-800">Invite collaborator</p>
                <p class="mt-1 text-xs text-gray-500">
                    Existing users are added instantly. New emails receive an invite link.
                </p>
                <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_auto_auto]">
                    <Input
                        v-model="inviteForm.email"
                        type="email"
                        placeholder="colleague@example.com"
                        class="bg-white"
                    />
                    <select
                        v-model="inviteForm.role"
                        class="h-10 rounded-md border border-input bg-white px-3 text-sm"
                    >
                        <option value="member">Member</option>
                        <option value="admin">Admin</option>
                    </select>
                    <Button class="cta-btn" :disabled="membersSaving || !inviteForm.email.trim()" @click="sendInvite">
                        <Mail class="mr-1.5 size-3.5" />
                        Invite
                    </Button>
                </div>
            </div>

            <div class="members-modal-body min-h-0 flex-1 overflow-y-auto px-6 py-4">
                <div v-if="membersLoading" class="space-y-2">
                    <Skeleton v-for="i in 3" :key="i" class="h-12 w-full rounded-xl" />
                </div>

                <div v-else class="space-y-5">
                    <div>
                        <p class="mb-2 text-sm font-semibold text-gray-800">Members ({{ members.length }})</p>
                        <div v-if="members.length === 0" class="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-gray-500">
                            No members yet.
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="member in members"
                                :key="member.id"
                                class="flex items-center justify-between gap-3 rounded-xl border px-3 py-2.5"
                            >
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-gray-900">{{ member.name }}</p>
                                    <p class="truncate text-xs text-gray-500">{{ member.email }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <select
                                        v-if="canManageMembers && member.role !== 'owner'"
                                        :value="member.role"
                                        class="h-8 rounded-md border border-input bg-white px-2 text-xs capitalize"
                                        :disabled="membersSaving"
                                        @change="updateMemberRole(member, ($event.target as HTMLSelectElement).value)"
                                    >
                                        <option value="member">Member</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                    <Badge v-else variant="outline" class="capitalize">{{ member.role }}</Badge>
                                    <Button
                                        v-if="canManageMembers && member.role !== 'owner'"
                                        size="icon"
                                        variant="ghost"
                                        class="size-8 text-red-500 hover:text-red-600"
                                        :disabled="membersSaving"
                                        @click="removeMember(member)"
                                    >
                                        <X class="size-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="pendingInvites.length > 0">
                        <p class="mb-2 text-sm font-semibold text-gray-800">Pending invites</p>
                        <div class="space-y-2">
                            <div
                                v-for="invite in pendingInvites"
                                :key="invite.id"
                                class="flex items-center justify-between gap-3 rounded-xl border border-amber-100 bg-amber-50/50 px-3 py-2.5"
                            >
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-gray-900">{{ invite.email }}</p>
                                    <p class="text-xs capitalize text-gray-500">{{ invite.role }} · pending</p>
                                </div>
                                <Button
                                    v-if="canManageMembers"
                                    size="sm"
                                    variant="ghost"
                                    class="shrink-0 text-red-600"
                                    :disabled="membersSaving"
                                    @click="revokeInvite(invite)"
                                >
                                    Revoke
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <DialogFooter class="shrink-0 border-t px-6 py-4">
                <Button variant="ghost" @click="membersModalOpen = false">Close</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.page-root {
    background-color: #f2efea;
}
.page-icon {
    background: linear-gradient(135deg, #e8563a, #ff8c42);
    box-shadow: 0 4px 12px rgba(232, 86, 58, 0.35);
}
.cta-btn {
    background: #e8563a;
    color: #fff;
}
.cta-btn:hover:not(:disabled) {
    background: #d44a2f;
}
.members-modal :deep([data-slot='dialog-close']) {
    top: 1.25rem;
    right: 1.25rem;
}
</style>
