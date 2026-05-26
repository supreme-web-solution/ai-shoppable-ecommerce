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
        title: 'Create your account',
        description: 'Start building shoppable videos in minutes',
    },
});
</script>

<template>
    <Head title="Register" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="grid gap-4">
            <div class="grid gap-1.5">
                <Label for="name" class="text-sm font-semibold text-gray-700">Full name</Label>
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

            <div class="grid gap-1.5">
                <Label for="email" class="text-sm font-semibold text-gray-700">Email address</Label>
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

            <div class="grid gap-1.5">
                <Label for="password" class="text-sm font-semibold text-gray-700">Password</Label>
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

            <div class="grid gap-1.5">
                <Label for="password_confirmation" class="text-sm font-semibold text-gray-700">Confirm password</Label>
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
                class="auth-submit mt-1 flex h-11 w-full items-center justify-center gap-2 rounded-xl text-sm font-bold text-white transition-all"
                tabindex="5"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" class="size-4" />
                {{ processing ? 'Creating account…' : 'Create free account' }}
            </button>
        </div>

        <p class="text-center text-sm text-gray-500">
            Already have an account?
            <TextLink :href="login()" class="font-semibold text-[#E8563A] hover:underline" :tabindex="6">
                Sign in
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
