<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Welcome back',
        description: 'Sign in to your SupremeVid account',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 rounded-xl bg-green-50 px-4 py-3 text-center text-sm font-medium text-green-700"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="grid gap-4">
            <div class="grid gap-1.5">
                <Label for="email" class="text-sm font-semibold text-gray-700">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    :tabindex="1"
                    autocomplete="email"
                    placeholder="you@example.com"
                    class="auth-input"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-1.5">
                <div class="flex items-center justify-between">
                    <Label for="password" class="text-sm font-semibold text-gray-700">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-xs font-semibold text-[#E8563A] hover:underline"
                        :tabindex="5"
                    >
                        Forgot password?
                    </TextLink>
                </div>
                <PasswordInput
                    id="password"
                    name="password"
                    required
                    :tabindex="2"
                    autocomplete="current-password"
                    placeholder="Your password"
                    class="auth-input"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center gap-2.5">
                <Checkbox id="remember" name="remember" :tabindex="3" class="auth-check" />
                <Label for="remember" class="text-sm text-gray-600 cursor-pointer">Remember me for 30 days</Label>
            </div>

            <button
                type="submit"
                class="auth-submit mt-1 flex h-11 w-full items-center justify-center gap-2 rounded-xl text-sm font-bold text-white transition-all"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" class="size-4" />
                {{ processing ? 'Signing in…' : 'Sign in' }}
            </button>
        </div>

        <p class="text-center text-sm text-gray-500">
            Don't have an account?
            <TextLink :href="register()" :tabindex="5" class="font-semibold text-[#E8563A] hover:underline">
                Create one free
            </TextLink>
        </p>
    </Form>
</template>

<style scoped>
.auth-input {
    border-color: #e5e7eb;
    background: #fafafa;
    border-radius: 10px;
    height: 42px;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.auth-input:focus {
    border-color: #E8563A;
    box-shadow: 0 0 0 3px rgba(232,86,58,0.12);
    outline: none;
    background: #fff;
}
.auth-submit {
    background: #E8563A;
    box-shadow: 0 4px 16px rgba(232,86,58,0.35);
}
.auth-submit:hover:not(:disabled) {
    background: #D44A2F;
    box-shadow: 0 6px 20px rgba(232,86,58,0.45);
    transform: translateY(-1px);
}
.auth-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
</style>
