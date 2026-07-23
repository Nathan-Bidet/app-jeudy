import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    server: {
        host: '0.0.0.0',
    },
    plugins: [
        laravel({
            input: 'resources/js/app.jsx',
            refresh: true,
        }),
        react(),
    ],
    test: {
        environment: 'jsdom',
        globals: true,
        // Exclut les fichiers AppleDouble (._*) créés par macOS sur les
        // volumes SMB, en plus des exclusions par défaut de Vitest.
        exclude: ['**/node_modules/**', '**/._*'],
    },
});

