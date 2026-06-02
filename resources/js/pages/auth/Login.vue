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
        description: 'Sign in to your account and start selling',
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
        class="mb-5 rounded-xl bg-emerald-50 border border-emerald-100 px-4 py-3 text-center text-sm font-medium text-emerald-700"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-3"
    >
        <div class="grid gap-3">
            <div class="grid gap-1">
                <Label for="email" class="field-label">Email address</Label>
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

            <div class="grid gap-1">
                <div class="flex items-center justify-between">
                    <Label for="password" class="field-label">Password</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-xs font-semibold text-[#E8563A] hover:text-[#c9402a] transition-colors"
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
                <Label for="remember" class="text-sm text-gray-500 cursor-pointer select-none">Remember me for 30 days</Label>
            </div>

            <button
                type="submit"
                class="auth-submit flex h-11 w-full items-center justify-center gap-2 rounded-xl text-sm font-black text-white transition-all"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" class="size-4" />
                {{ processing ? 'Signing in…' : 'Sign in to your account' }}
            </button>
        </div>
       
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

.divider {
    margin: 0;
}
.divider-line {
    flex: 1;
    height: 1px;
    background: #e9e9eb;
}

.register-link {
    text-decoration: none;
}
</style>
