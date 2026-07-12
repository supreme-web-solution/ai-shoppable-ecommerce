import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import { createPinia } from 'pinia';
import { createApp, h } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { echoOptionsFromConfig, resolveReverbConfig } from '@/lib/reverbConfig';

const reverb = resolveReverbConfig();

if (reverb) {
    configureEcho(echoOptionsFromConfig(reverb));
}

const appName = import.meta.env.VITE_APP_NAME || 'My Stream Cart';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('webinars/'):
                return null;
            case name.startsWith('virofeed/'):
                return null;
            case name.startsWith('checkout/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name === 'settings/Integrations':
                return AppLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
    setup({ App, props, plugin }) {
        const pinia = createPinia();
        const vueApp = createApp({ render: () => h(App, props) });

        vueApp.use(plugin).use(pinia).mount('#app');

        return vueApp;
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
