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

/** A copy of the committed runtime.json to mutate in tests. */
export function writeRuntimeJsonCopy(root, overrides = {}) {
    const document = { ...JSON.parse(readFileSync(COMMITTED_RUNTIME_JSON, 'utf8')), ...overrides };
    const path = join(root, 'runtime.json');
    mkdirSync(root, { recursive: true });
    writeFileSync(path, `${JSON.stringify(document, null, 4)}\n`);

    return path;
}

export function readJson(path) {
    return JSON.parse(readFileSync(path, 'utf8'));
}
