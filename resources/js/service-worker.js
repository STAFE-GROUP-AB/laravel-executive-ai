/*
 * Laravel Executive AI — service worker (S1-04; blueprint §1.2; ADR-006 D3).
 *
 * Policy, enforced by shape not by discipline:
 *
 *   1. Only paths matching SHELL_ALLOWLIST may ever be written to a cache, and
 *      only through putInShellCache(), the single call site of cache.put().
 *   2. Everything under NEVER_CACHE is refused even if it ever matched the
 *      allowlist: no cache read, no cache write, straight to the network.
 *   3. Non-GET requests and cross-origin requests are never touched.
 *   4. A response that is not ok, not same-origin ("basic"), carries
 *      Set-Cookie or says Cache-Control: no-store is never stored.
 *   5. Offline navigation falls back to the precached /offline page, which is
 *      served without a session (routes/pwa.php). Never to a cached brief:
 *      there is none.
 *
 * No IndexedDB, no localStorage, no background sync, no push handling here.
 * packaging/fixtures/e2e/sw-cache-audit.test.mjs parses this file (and the
 * built public/sw.js) and fails CI if the lists or the shape change.
 * Vite keeps the build unminified for that reason (vite.sw.config.js).
 */

const VERSION = __SW_VERSION__;
const SHELL_CACHE = `lxai-shell-${VERSION}`;

// App shell: built assets, icons, manifest, offline page. Entries ending in "/"
// are prefixes; the others match exactly.
const SHELL_ALLOWLIST = [
    "/build/",
    "/icons/",
    "/manifest.webmanifest",
    "/offline",
    "/favicon.ico"
];

// Never cached, never served from cache (§1.2: briefs, tokens, credentials,
// anything with high sensitivity; ADR-006 D3: /ipc, /health, /auth, Set-Cookie).
const NEVER_CACHE = [
    "/briefs",
    "/home",
    "/settings",
    "/login",
    "/logout",
    "/auth",
    "/ipc",
    "/health",
    "/livewire",
    "/api",
    "/admin",
    "/approvals",
    "/assistant",
    "/crm",
    "/inbox",
    "/integrations",
    "/mcp",
    "/automation",
    "/sanctum",
    "/up"
];

const PRECACHE = [
    "/offline",
    "/manifest.webmanifest",
    "/icons/icon.svg",
    "/icons/icon-192.png",
    "/icons/icon-512.png",
    "/icons/icon-maskable-192.png",
    "/icons/icon-maskable-512.png",
    "/icons/apple-touch-icon.png"
];

function isDenied(pathname) {
    return NEVER_CACHE.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
}

function isShellAsset(pathname) {
    if (isDenied(pathname)) {
        return false;
    }

    return SHELL_ALLOWLIST.some((entry) => (entry.endsWith('/') ? pathname.startsWith(entry) : pathname === entry));
}

function isStorable(response) {
    if (! response || ! response.ok || response.type !== 'basic') {
        return false;
    }

    if (response.headers.has('set-cookie')) {
        return false;
    }

    const cacheControl = (response.headers.get('cache-control') || '').toLowerCase();

    return ! cacheControl.includes('no-store') && ! cacheControl.includes('private');
}

// The only place a cache is written. Both guards run again here on purpose.
async function putInShellCache(request, response) {
    if (! isShellAsset(new URL(request.url).pathname) || ! isStorable(response)) {
        return;
    }

    const cache = await caches.open(SHELL_CACHE);
    await cache.put(request, response.clone());
}

async function builtAssetUrls() {
    try {
        const response = await fetch('/build/manifest.json', { credentials: 'omit', cache: 'no-cache' });

        if (! response.ok) {
            return [];
        }

        const manifest = await response.json();

        return Object.values(manifest)
            .flatMap((entry) => [entry.file, ...(entry.css || [])])
            .filter((file) => typeof file === 'string')
            .map((file) => `/build/${file}`);
    } catch {
        return [];
    }
}

async function precacheShell() {
    const urls = [...PRECACHE, ...(await builtAssetUrls())];

    await Promise.all(urls.map(async (url) => {
        const request = new Request(url, { credentials: 'omit' });

        try {
            const response = await fetch(request);
            await putInShellCache(request, response);
        } catch {
            // A missing shell file is not fatal; it is fetched from the network later.
        }
    }));
}

async function cacheFirst(request) {
    const cache = await caches.open(SHELL_CACHE);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);
    await putInShellCache(request, response);

    return response;
}

async function networkWithOfflineFallback(request) {
    try {
        return await fetch(request);
    } catch {
        const cache = await caches.open(SHELL_CACHE);
        const offline = await cache.match('/offline');

        return offline || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(precacheShell().then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key.startsWith('lxai-shell-') && key !== SHELL_CACHE).map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return; // network only, never cached
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return; // other origins are never touched
    }

    if (isShellAsset(url.pathname)) {
        event.respondWith(cacheFirst(request));

        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkWithOfflineFallback(request));

        return;
    }

    // Everything else, including every NEVER_CACHE path: the browser fetches it
    // from the network. No cache read, no cache write.
});
