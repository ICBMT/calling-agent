import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        allowedHosts: ['.e2b.app', 'localhost'],
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('/three/build/three.core.js')) return 'three-core';
                    if (id.includes('/three/')) return 'three-renderer';
                    if (id.includes('/gsap/') || id.includes('/lenis/')) return 'motion';
                },
            },
        },
    },
});
