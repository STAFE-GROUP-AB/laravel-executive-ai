/*
 * Second Vite build (S1-04): resources/js/service-worker.js -> public/sw.js.
 *
 * A service worker must be served from the scope it controls, so it cannot
 * live under public/build with the hashed assets. It is built unminified on
 * purpose: packaging/fixtures/e2e/sw-cache-audit.test.mjs parses the output
 * for the cache allow/deny lists. `npm run build` runs both configs.
 */
import { defineConfig } from 'vite';

const version = process.env.SW_VERSION || Date.now().toString(36);

export default defineConfig({
    publicDir: false,
    define: {
        __SW_VERSION__: JSON.stringify(version),
    },
    build: {
        outDir: 'public',
        emptyOutDir: false,
        copyPublicDir: false,
        minify: false,
        sourcemap: false,
        manifest: false,
        rolldownOptions: {
            input: 'resources/js/service-worker.js',
            output: {
                format: 'iife',
                entryFileNames: 'sw.js',
            },
        },
    },
});
