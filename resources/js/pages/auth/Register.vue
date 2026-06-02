<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Start for free today',
        description: 'Create shoppable videos and sell more in minutes',
    },
});
</script>

<template>
    <Head title="Register" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-3"
    >
        <div class="grid gap-3">
            <div class="grid gap-1">
                <Label for="name" class="field-label">Full name</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="name"
                    name="name"
                    placeholder="Jane Smith"
                    class="auth-input"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-1">
                <Label for="email" class="field-label">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    :tabindex="2"
                    autocomplete="email"
                    name="email"
                    placeholder="you@example.com"
                    class="auth-input"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-1">
                <Label for="password" class="field-label">Password</Label>
                <PasswordInput
                    id="password"
                    required
                    :tabindex="3"
                    autocomplete="new-password"
                    name="password"
                    placeholder="Choose a strong password"
                    :passwordrules="passwordRules"
                    class="auth-input"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-1">
                <Label for="password_confirmation" class="field-label">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    :tabindex="4"
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Repeat your password"
                    :passwordrules="passwordRules"
                    class="auth-input"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <button
                type="submit"
                class="auth-submit flex h-11 w-full items-center justify-center gap-2 rounded-xl text-sm font-black text-white transition-all"
                tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" class="size-4" />
                {{ processing ? 'Creating account…' : 'Create free account →' }}
            </button>

            <p class="text-center text-[11px] text-gray-400">
                No credit card required &nbsp;·&nbsp; Free forever plan
            </p>
        </div>

        <div class="divider flex items-center gap-3">
            <span class="divider-line" />
            <span class="text-xs text-gray-400 font-medium whitespace-nowrap">Already have an account?</span>
            <span class="divider-line" />
        </div>

        <TextLink
            :href="login()"
            :tabindex="6"
            class="signin-link flex h-10 w-full items-center justify-center rounded-xl text-sm font-semibold text-gray-600 border border-gray-200 hover:border-gray-300 hover:bg-gray-50 transition-colors"
        >
            Sign in instead
        </TextLink>
    </Form>
</template>

<style scoped>
.field-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #374151;
}

.auth-input {
    border-color: #e9e9eb;
    background: #fafafa;
    border-radius: 10px;
    height: 40px;
    font-size: 0.875rem;
    transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
}
.auth-input:focus {
    border-color: #E8563A;
    box-shadow: 0 0 0 3px rgba(232,86,58,0.1);
    outline: none;
    background: #fff;
}

.auth-submit {
    background: linear-gradient(135deg, #E8563A 0%, #c9402a 100%);
    box-shadow: 0 4px 14px rgba(232,86,58,0.38), 0 1px 3px rgba(0,0,0,0.1);
    letter-spacing: -0.01em;
}
.auth-submit:hover:not(:disabled) {
    background: linear-gradient(135deg, #f06040 0%, #d9472e 100%);
    box-shadow: 0 6px 18px rgba(232,86,58,0.48), 0 1px 3px rgba(0,0,0,0.1);
    transform: translateY(-1px);
}
.auth-submit:active:not(:disabled) { transform: translateY(0); }
.auth-submit:disabled { opacity: 0.6; cursor: not-allowed; }

.divider { margin: 0; }
.divider-line { flex: 1; height: 1px; background: #e9e9eb; }
.signin-link { text-decoration: none; }
</style>
