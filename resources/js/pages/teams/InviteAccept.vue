<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Check, Loader2, Users, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    token: string;
    teamName: string;
    email: string;
    role: string;
    inviterName?: string | null;
    status: 'pending' | 'expired' | 'accepted';
    expiresAt?: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Team invitation', href: '#' }],
    },
});

const page = usePage();
const accepting = ref(false);
const errorText = ref('');
const successText = ref('');

const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const authEmail = computed(() => String((page.props.auth?.user as Record<string, unknown> | null)?.email ?? ''));

const emailMatches = computed(() => {
    if (!isAuthenticated.value) {
        return false;
    }

    return authEmail.value.toLowerCase() === props.email.toLowerCase();
});

const loginHref = computed(() => `/login?invite=${encodeURIComponent(props.token)}`);
const registerHref = computed(() => `/register?invite=${encodeURIComponent(props.token)}&email=${encodeURIComponent(props.email)}`);

async function acceptInvite() {
    accepting.value = true;
    errorText.value = '';

    try {
        const response = await fetch(`/api/v1/invites/${props.token}/accept`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const payload = await response.json().catch(() => null);

        if (!response.ok) {
            throw new Error(
                payload && typeof payload === 'object' && 'message' in payload
                    ? String((payload as { message: string }).message)
                    : 'Could not accept invitation.',
            );
        }

        successText.value = `You joined ${props.teamName}. Redirecting…`;
        router.visit('/teams');
    } catch (err) {
        errorText.value = err instanceof Error ? err.message : 'Could not accept invitation.';
    } finally {
        accepting.value = false;
    }
}
</script>

<template>
    <Head :title="`Join ${teamName}`" />

    <div class="page-root flex min-h-screen items-center justify-center px-4 py-10">
        <div class="w-full max-w-lg rounded-2xl border bg-white p-6 shadow-sm sm:p-8">
            <div class="page-icon mx-auto mb-4 flex size-12 items-center justify-center rounded-2xl">
                <Users class="size-6 text-white" />
            </div>

            <h1 class="text-center text-2xl font-black text-gray-900">Team invitation</h1>
            <p class="mt-2 text-center text-sm text-gray-500">
                <span v-if="inviterName">{{ inviterName }} invited you to join</span>
                <span v-else>You have been invited to join</span>
                <strong class="text-gray-800"> {{ teamName }}</strong>
                as a <span class="capitalize">{{ role }}</span>.
            </p>

            <div class="mt-4 rounded-xl bg-gray-50 px-4 py-3 text-center text-sm text-gray-600">
                Invitation sent to <strong>{{ email }}</strong>
            </div>

            <div v-if="errorText" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ errorText }}
            </div>
            <div
                v-else-if="successText"
                class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"
            >
                {{ successText }}
            </div>

            <div v-if="status === 'accepted'" class="mt-6 text-center">
                <Check class="mx-auto size-8 text-emerald-500" />
                <p class="mt-2 text-sm text-gray-600">This invitation has already been accepted.</p>
                <Link href="/teams" class="mt-4 inline-block text-sm font-semibold text-[#E8563A] underline">
                    Go to Teams
                </Link>
            </div>

            <div v-else-if="status === 'expired'" class="mt-6 text-center">
                <XCircle class="mx-auto size-8 text-red-400" />
                <p class="mt-2 text-sm text-gray-600">This invitation has expired. Ask the team owner to send a new one.</p>
            </div>

            <div v-else class="mt-6 space-y-3">
                <template v-if="isAuthenticated">
                    <p v-if="!emailMatches" class="text-sm text-amber-700">
                        You are signed in as {{ authEmail }}, but this invite was sent to {{ email }}.
                        Sign out and use the invited email, or ask for a new invite.
                    </p>
                    <Button
                        v-else
                        class="cta-btn w-full"
                        :disabled="accepting"
                        @click="acceptInvite"
                    >
                        <Loader2 v-if="accepting" class="mr-2 size-4 animate-spin" />
                        Accept & join team
                    </Button>
                </template>

                <template v-else>
                    <p class="text-center text-sm text-gray-500">Sign in or create an account with the invited email to continue.</p>
                    <Link :href="loginHref" class="block">
                        <Button class="cta-btn w-full">Sign in to accept</Button>
                    </Link>
                    <Link :href="registerHref" class="block">
                        <Button variant="outline" class="w-full">Create account</Button>
                    </Link>
                </template>
            </div>
        </div>
    </div>
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
</style>
