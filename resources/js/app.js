/*
 * PWA bootstrap (S1-04). The only script the pages load; there is no inline
 * JavaScript anywhere in the Blade templates (strict CSP, threat-model A16).
 *
 * The WebAuthn ceremonies (S2-05) ride in the same bundle, so the login page
 * needs no second entry point and no CDN: see resources/js/passkeys.js.
 *
 * Registers the service worker built from resources/js/service-worker.js to
 * public/sw.js. The worker caches the app shell only — see
 * docs/architecture/pwa.md for the allow/deny lists. Secure contexts only:
 * loopback counts as secure, and the pages are served on `localhost` (ADR-006
 * D3), which is also the only loopback host the passkeys above can run on.
 */
import './passkeys.js';

if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // The app works without the shell cache; nothing to show the user.
        });
    });
}
