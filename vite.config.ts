import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },

    server: {
        // The dev server runs inside a container; binding to loopback would
        // make it unreachable from the host browser.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // The page is served from :8000 and the assets from :5173, so HMR has
        // to be told where to find its socket.
        hmr: {
            host: 'localhost',
            protocol: 'ws',
        },
        watch: {
            // Blade compiles into here on every render; watching it is a loop.
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },

    build: {
        // Source maps make a production stack trace readable without shipping
        // meaningfully more bytes to the browser — they load on demand.
        sourcemap: true,
        // Chunking is left to the bundler. Hand-written vendor splits were a
        // Rollup-era habit; rolldown's defaults are better than a guess.
    },
});
