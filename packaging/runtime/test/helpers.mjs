// Builds the smallest Laravel-shaped tree and a stub binary the assembler accepts, in a
// temporary directory. Everything is synthetic; nothing here is ever a real runtime.

import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const RUNTIME_DIR = join(dirname(fileURLToPath(import.meta.url)), '..');
export const COMMITTED_RUNTIME_JSON = join(RUNTIME_DIR, '..', 'manifest', 'runtime.json');
export const COMMIT = 'a'.repeat(40);

export function tempDir(prefix = 'lxai-runtime-') {
    return mkdtempSync(join(tmpdir(), prefix));
}

export function write(root, relativePath, contents) {
    const full = join(root, ...relativePath.split('/'));
    mkdirSync(dirname(full), { recursive: true });
    writeFileSync(full, contents);

    return full;
}

/** A tree with exactly what listAppFiles requires, plus a vendor file and a pre-built package cache. */
export function writeStubApp(root) {
    const app = join(root, 'app-tree');

    write(app, 'artisan', "#!/usr/bin/env php\n<?php echo 'stub';\n");
    write(app, 'bootstrap/app.php', "<?php return null;\n");
    write(app, 'bootstrap/cache/packages.php', "<?php return [];\n");
    write(app, 'bootstrap/cache/services.php', "<?php return [];\n");
    write(app, 'public/index.php', "<?php echo 'ok';\n");
    write(app, 'vendor/autoload.php', "<?php return true;\n");
    write(app, 'vendor/laravel/framework/src/Illuminate/Foundation/Application.php', "<?php class Application {}\n");
    write(app, 'config/app.php', "<?php return ['name' => 'stub'];\n");
    write(app, 'database/migrations/0001_01_01_000000_create_users_table.php', "<?php return new class {};\n");
    write(app, 'resources/views/welcome.blade.php', '<h1>stub</h1>');
    write(app, 'routes/api.php', "<?php\n");
    write(app, 'composer.json', '{"name": "stub/stub"}\n');
    write(app, 'composer.lock', '{}\n');

    return app;
}

export function writeStubBinary(root) {
    return write(root, 'frankenphp-stub', '#!/bin/sh\necho "FrankenPHP stub (not a runtime)"\n');
}

/**
 * A copy of the committed runtime.json to mutate in tests, reset to the `unbuilt` state.
 *
 * What a test wants from the committed file is its *pins* — the component versions, the signing
 * identifier, the HTTP shape — not the build state of whatever package `main` happens to publish
 * at the time. Carrying that state over made two tests pass only while `runtime.json` was
 * `unbuilt`: one expected `recordArtifact` to refuse a manifest that is not yet signed, the other
 * expected the assembler to demand a `source_commit` that the copy already supplied. Both broke
 * the moment the first real package was pinned. Resetting here keeps every test's starting point
 * independent of the release state; a test that wants a built or signed copy passes it in
 * `overrides`, and the one test that asserts things about the *real* document reads
 * `COMMITTED_RUNTIME_JSON` directly instead of going through this helper.
 */
export function writeRuntimeJsonCopy(root, overrides = {}) {
    const document = {
        ...JSON.parse(readFileSync(COMMITTED_RUNTIME_JSON, 'utf8')),
        status: 'unbuilt',
        built_at: null,
        source_commit: null,
        package: null,
        artifact: null,
        ...overrides,
    };
    const path = join(root, 'runtime.json');
    mkdirSync(root, { recursive: true });
    writeFileSync(path, `${JSON.stringify(document, null, 4)}\n`);

    return path;
}

export function readJson(path) {
    return JSON.parse(readFileSync(path, 'utf8'));
}
