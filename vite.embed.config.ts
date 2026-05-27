import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'node:path';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return {
    mode: 'production',
    publicDir: false,
    plugins: [vue(), tailwindcss()],
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
        'process.env': JSON.stringify({ NODE_ENV: 'production' }),
        'import.meta.env.VITE_REVERB_APP_KEY': JSON.stringify(
            env.VITE_REVERB_APP_KEY || env.REVERB_APP_KEY || '',
        ),
        'import.meta.env.VITE_REVERB_HOST': JSON.stringify(
            env.VITE_REVERB_HOST || env.REVERB_HOST || '',
        ),
        'import.meta.env.VITE_REVERB_PORT': JSON.stringify(
            env.VITE_REVERB_PORT || env.REVERB_PORT || '8080',
        ),
        'import.meta.env.VITE_REVERB_SCHEME': JSON.stringify(
            env.VITE_REVERB_SCHEME || env.REVERB_SCHEME || 'http',
        ),
    },
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    build: {
        lib: {
            entry: resolve(__dirname, 'resources/js/embed/loader.ts'),
            name: 'SupremeEmbed',
            formats: ['iife'],
            fileName: () => 'embed.js',
            cssFileName: 'embed',
        },
        outDir: 'public/embed',
        emptyOutDir: true,
        cssCodeSplit: false,
        rollupOptions: {
            output: {
                inlineDynamicImports: true,
                assetFileNames: 'embed.css',
            },
        },
    },
};
});
