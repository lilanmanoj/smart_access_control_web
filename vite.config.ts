import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// The address the browser will use to reach the dev server. Only ever
// 'localhost' when the browser is on this machine.
const devHost = process.env.VITE_DEV_HOST || 'localhost';
const devPort = Number(process.env.VITE_PORT || 5173);

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
        port: devPort,
        strictPort: true,

        /*
         * `origin` is what laravel-vite-plugin writes into public/hot, and
         * @vite prefers that file over the compiled manifest. So this string
         * becomes the origin of every asset URL on the page — and it has to be
         * an address the *browser* can reach.
         *
         * 'localhost' is correct only while the browser is on this machine.
         * Open the app from a phone or another laptop and every asset request
         * goes to that machine's own localhost and fails. Set VITE_DEV_HOST to
         * the address they actually use, or stop the dev server and let nginx
         * serve the built assets same-origin.
         */
        origin: `http://${devHost}:${devPort}`,

        // The page comes from :8000 and the assets from :5173, so the dev
        // server is genuinely cross-origin and has to say so.
        cors: true,

        // Reached directly by IP or hostname rather than through a proxy, so
        // the Host header is whatever the visitor typed.
        allowedHosts: true,

        hmr: {
            host: devHost,
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
