/**
 * Service-worker cache audit (S1-04, blueprint §1.2, ADR-006 D3).
 *
 * Statically parses the service worker and fails when its shape would let
 * brief content, tokens or credentials into a cache. Checks the source
 * (resources/js/service-worker.js) always and the built public/sw.js when it
 * exists, so the audit holds both before and after `npm run build`.
 *
 *   node --test 'packaging/fixtures/e2e/**\/*.test.mjs'
 *
 * Pure Node, no dependencies.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../../..');

const candidates = [
    resolve(root, 'resources/js/service-worker.js'),
    resolve(root, 'public/sw.js'),
].filter((path) => existsSync(path));

// Every path a page, a session, a token or the IPC lives under. The worker must
// list each one in NEVER_CACHE and none may be reachable from SHELL_ALLOWLIST.
const REQUIRED_DENY = ['/briefs', '/home', '/login', '/logout', '/auth', '/ipc', '/health', '/livewire', '/api', '/admin', '/approvals'];

// What the app shell is allowed to be. Anything else in the allowlist is a regression.
const PERMITTED_ALLOW = ['/build/', '/icons/', '/manifest.webmanifest', '/offline', '/favicon.ico'];

const STORAGE_APIS = ['indexedDB', 'localStorage', 'sessionStorage', 'openDatabase'];

function stringList(source, name) {
    const match = source.match(new RegExp(`(?:const|let|var)\\s+${name}\\s*=\\s*\\[([\\s\\S]*?)\\];`));

    assert.ok(match, `${name} array literal not found`);

    return [...match[1].matchAll(/["'`]([^"'`]*)["'`]/g)].map((m) => m[1]);
}

function count(source, needle) {
    return source.split(needle).length - 1;
}

// The brace-balanced body of a top-level function, whatever the bundler's indentation.
function functionBody(source, name) {
    const start = source.search(new RegExp(`(?:async\\s+)?function\\s+${name}\\s*\\(`));

    assert.notEqual(start, -1, `${name} not found`);

    let depth = 0;

    for (let i = source.indexOf('{', start); i < source.length; i++) {
        if (source[i] === '{') {
            depth++;
        }

        if (source[i] === '}') {
            depth--;

            if (depth === 0) {
                return source.slice(start, i + 1);
            }
        }
    }

    assert.fail(`${name} has unbalanced braces`);
}

// Comments document the policy and name the forbidden APIs; only code is audited.
function stripComments(source) {
    return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
}

assert.ok(candidates.length > 0, 'resources/js/service-worker.js is missing');

for (const path of candidates) {
    const label = path.replace(`${root}/`, '');
    const source = stripComments(readFileSync(path, 'utf8'));

    test(`${label}: the deny list covers every page, auth, IPC and health path`, () => {
        const denied = stringList(source, 'NEVER_CACHE');

        for (const prefix of REQUIRED_DENY) {
            assert.ok(denied.includes(prefix), `${prefix} missing from NEVER_CACHE`);
        }
    });

    test(`${label}: the allow list is the app shell and nothing else`, () => {
        const allowed = stringList(source, 'SHELL_ALLOWLIST');

        assert.ok(allowed.length > 0, 'SHELL_ALLOWLIST is empty');

        for (const entry of allowed) {
            assert.ok(PERMITTED_ALLOW.includes(entry), `${entry} is not part of the app shell`);

            for (const prefix of REQUIRED_DENY) {
                assert.ok(! entry.startsWith(prefix), `${entry} overlaps denied prefix ${prefix}`);
            }
        }
    });

    test(`${label}: precache entries are shell assets only`, () => {
        const precache = stringList(source, 'PRECACHE');

        for (const entry of precache) {
            const shell = PERMITTED_ALLOW.some((allow) => (allow.endsWith('/') ? entry.startsWith(allow) : entry === allow));

            assert.ok(shell, `${entry} precached but not part of the app shell`);
        }
    });

    test(`${label}: Cache.put has exactly one call site, guarded by the allow list and the response check`, () => {
        // The bundler may rewrite `cache.put(` as `(await caches.open(…)).put(`; count the method call.
        assert.equal((source.match(/\.put\(/g) || []).length, 1, 'Cache.put must be called from putInShellCache only');
        assert.equal((source.match(/\.add(All)?\(/g) || []).length, 0, 'Cache.add/addAll bypass the guards');

        const guard = functionBody(source, 'putInShellCache');

        assert.ok(guard.includes('isShellAsset('), 'putInShellCache must re-check the allow list');
        assert.ok(guard.includes('isStorable('), 'putInShellCache must re-check the response');
        assert.ok(guard.includes('.put('), 'the single Cache.put must live inside putInShellCache');
    });

    test(`${label}: the response guard refuses Set-Cookie and no-store responses`, () => {
        assert.match(source, /headers\.has\(['"]set-cookie['"]\)/);
        assert.match(source, /no-store/);
        assert.match(source, /response\.type !== ['"]basic['"]/);
    });

    test(`${label}: the deny list is consulted before the allow list`, () => {
        const isShellAsset = functionBody(source, 'isShellAsset');

        assert.ok(isShellAsset.includes('isDenied('), 'isShellAsset must call isDenied first');
    });

    test(`${label}: non-GET and cross-origin requests are never handled`, () => {
        assert.match(source, /request\.method !== ['"]GET['"]/);
        assert.match(source, /url\.origin !== self\.location\.origin/);
    });

    test(`${label}: no browser storage API other than the Cache API is used`, () => {
        for (const api of STORAGE_APIS) {
            assert.equal(count(source, api), 0, `${api} must not be used in the service worker`);
        }

        assert.equal(count(source, 'importScripts'), 0, 'no external scripts');
        assert.equal(count(source, "addEventListener('push'"), 0, 'push is S1-11+/Sprint 2, not here');
        assert.equal(count(source, "addEventListener('sync'"), 0, 'background sync is not part of the shell');
    });
}
