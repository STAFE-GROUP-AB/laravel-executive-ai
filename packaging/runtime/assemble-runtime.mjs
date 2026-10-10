#!/usr/bin/env node
// Assembles one immutable runtime directory (ADR-001 D4) from a FrankenPHP binary and a
// production-installed Laravel tree, then writes the directory manifest the Mac app verifies
// and records the result in packaging/manifest/runtime.json.
//
//   runtime/<version>/
//     frankenphp        the binary (signed by S1-12 before the manifest is final)
//     Caddyfile         templates/Caddyfile, verbatim: loopback only, port from the environment
//     php.ini           templates/php.ini, verbatim: opcache on, opcache.jit=disable, pcre.jit=1
//     app/              the Laravel tree, minus everything that is not needed to run it
//     manifest.json     schema 1, generated here — never hand-written
//     SHA256SUMS        sha256sum format, same digests as the manifest
//
// Usage:
//   node packaging/runtime/assemble-runtime.mjs --binary <frankenphp> --app <laravel tree> --version 0.1.0
//       [--out <dir>] [--manifest packaging/manifest/runtime.json] [--source-commit <sha>]
//       [--built-at <iso>] [--status built|signed] [--no-update-manifest]
//
// The app tree must already be `composer install --no-dev --optimize-autoloader`-ed and have run
// `php artisan package:discover`. It must NOT contain `.env*`, a config/route/event cache
// (ADR-003: nothing derived from the environment may be baked into the runtime), a database file,
// or a symlink; the assembler refuses those instead of silently dropping them.
//
// Dependency-free so it runs on a bare macOS CI runner.

import { chmodSync, copyFileSync, existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    CHECKSUM_FILE_NAME,
    MANIFEST_FILE_NAME,
    RuntimeManifestError,
    formatChecksumFile,
    isSafeRelativePath,
    isSha256Hex,
    isVersion,
    sha256Hex,
    validateManifest,
    verifyRuntimeDirectory,
} from './lib/manifest.mjs';

const here = dirname(fileURLToPath(import.meta.url));
export const REPO_ROOT = resolve(here, '..', '..');
export const TEMPLATES_DIR = join(here, 'templates');
export const DEFAULT_MANIFEST = join(REPO_ROOT, 'packaging', 'manifest', 'runtime.json');
export const EXECUTABLE_NAME = 'frankenphp';

/** Top-level entries of the Laravel tree that never belong in a runtime. */
export const EXCLUDED_APP_ENTRIES = new Set([
    '.git',
    '.github',
    '.claude',
    '.idea',
    '.vscode',
    'node_modules',
    'tests',
    'storage',
    'apps',
    'packaging',
    'docs',
    'Mission',
    '.DS_Store',
]);

/** Files whose presence anywhere in the tree aborts the build (fail closed, ADR-003). */
export const FORBIDDEN_APP_FILES = [
    { test: (path) => /(^|\/)\.env(\..*)?$/.test(path), reason: 'environment file (ADR-001 D4: no .env in the runtime)' },
    { test: (path) => /^bootstrap\/cache\/(config|routes-v7|events|routes)\.php$/.test(path), reason: 'environment-derived cache (ADR-003: config/route/event caches are built per install, never shipped)' },
    { test: (path) => /\.(sqlite|sqlite-wal|sqlite-shm|key|pem|p12|p8|gguf|mlmodelc)$/.test(path), reason: 'database, key or model file' },
    { test: (path) => /^database\/.*\.sqlite/.test(path), reason: 'database file' },
    { test: (path) => /(^|\/)auth\.json$/.test(path), reason: 'Composer credentials' },
];

export class AssembleError extends Error {
    constructor(message) {
        super(message);
        this.name = 'AssembleError';
    }
}

const fail = (message) => {
    throw new AssembleError(message);
};

export function parseArgs(argv) {
    const options = { updateManifest: true, status: 'built', manifest: DEFAULT_MANIFEST };

    for (let i = 0; i < argv.length; i += 1) {
        const arg = argv[i];
        const next = () => {
            i += 1;

            if (i >= argv.length) {
                fail(`${arg} needs a value`);
            }

            return argv[i];
        };

        switch (arg) {
            case '--binary': options.binary = next(); break;
            case '--app': options.app = next(); break;
            case '--version': options.version = next(); break;
            case '--out': options.out = next(); break;
            case '--manifest': options.manifest = next(); break;
            case '--source-commit': options.sourceCommit = next(); break;
            case '--built-at': options.builtAt = next(); break;
            case '--status': options.status = next(); break;
            case '--no-update-manifest': options.updateManifest = false; break;
            default: fail(`unknown argument ${arg}`);
        }
    }

    return options;
}

/** Walks the app tree, returns sorted relative paths; refuses symlinks and forbidden files. */
export function listAppFiles(appDir) {
    const files = [];

    const walk = (dir, depth) => {
        for (const entry of readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
            if (depth === 0 && EXCLUDED_APP_ENTRIES.has(entry.name)) {
                continue;
            }

            const full = join(dir, entry.name);
            const rel = relative(appDir, full).split(sep).join('/');

            if (entry.isSymbolicLink()) {
                fail(`${rel} is a symlink; the runtime may not contain symlinks (PackageVerifier refuses them)`);
            }

            if (entry.isDirectory()) {
                walk(full, depth + 1);
                continue;
            }

            if (! entry.isFile()) {
                fail(`${rel} is not a regular file`);
            }

            for (const rule of FORBIDDEN_APP_FILES) {
                if (rule.test(rel)) {
                    fail(`${rel}: ${rule.reason}`);
                }
            }

            if (! isSafeRelativePath(rel)) {
                fail(`${rel} is not a safe relative path`);
            }

            files.push(rel);
        }
    };

    walk(appDir, 0);

    for (const required of ['artisan', 'bootstrap/app.php', 'public/index.php', 'vendor/autoload.php']) {
        if (! files.includes(required)) {
            fail(`the app tree has no ${required}; pass a composer-installed Laravel tree`);
        }
    }

    if (files.some((path) => /^vendor\/(pestphp|phpunit|mockery|larastan|laravel\/pint)\//.test(path))) {
        fail('the app tree contains dev dependencies; install it with composer install --no-dev');
    }

    return files;
}

function copyFile(source, destination, mode) {
    mkdirSync(dirname(destination), { recursive: true });
    copyFileSync(source, destination);
    chmodSync(destination, mode);
}

/**
 * Builds `runtime/<version>/` under `out`, returns `{ directory, manifest }`. Pure in the sense
 * that it only ever writes under `out` and (optionally) the runtime.json it was given.
 */
export function assembleRuntime({ binary, app, version, out, manifest: manifestPath, sourceCommit, builtAt, status = 'built', updateManifest = true, templatesDir = TEMPLATES_DIR }) {
    if (! binary || ! app || ! version) {
        fail('--binary, --app and --version are required');
    }

    if (! isVersion(version)) {
        fail(`--version ${version} is not MAJOR.MINOR.PATCH`);
    }

    if (! ['built', 'signed'].includes(status)) {
        fail(`--status must be built or signed, got ${status}`);
    }

    if (sourceCommit !== undefined && ! /^[0-9a-f]{40}$/.test(sourceCommit)) {
        fail('--source-commit must be a full 40-character SHA-1');
    }

    const binaryStat = lstatSync(binary);

    if (! binaryStat.isFile()) {
        fail(`--binary ${binary} is not a regular file`);
    }

    if (binaryStat.size === 0) {
        fail(`--binary ${binary} is empty`);
    }

    if (! statSync(app).isDirectory()) {
        fail(`--app ${app} is not a directory`);
    }

    const directory = resolve(out ?? join(REPO_ROOT, 'packaging', 'build', 'runtime', version));

    const appDirectory = resolve(app);

    if (directory === appDirectory || directory.startsWith(`${appDirectory}${sep}`) || appDirectory.startsWith(`${directory}${sep}`)) {
        fail('--out must not be inside --app (or the other way round)');
    }

    const appFiles = listAppFiles(app);

    rmSync(directory, { recursive: true, force: true });
    mkdirSync(directory, { recursive: true });

    copyFile(binary, join(directory, EXECUTABLE_NAME), 0o755);

    for (const name of ['Caddyfile', 'php.ini']) {
        const template = join(templatesDir, name);
        const text = readFileSync(template, 'utf8');

        // The whole point of a static template: nothing machine-specific may be in it.
        if (/\{\{[A-Z_]+\}\}/.test(text) || /\/Users\/|\/home\//.test(text)) {
            fail(`${name} contains an unrendered placeholder or an absolute user path`);
        }

        copyFile(template, join(directory, name), 0o644);
    }

    for (const rel of appFiles) {
        const source = join(app, ...rel.split('/'));
        copyFile(source, join(directory, 'app', ...rel.split('/')), rel === 'artisan' ? 0o755 : 0o644);
    }

    const paths = [EXECUTABLE_NAME, 'Caddyfile', 'php.ini', ...appFiles.map((rel) => `app/${rel}`)].sort();
    const files = paths.map((path) => {
        const full = join(directory, ...path.split('/'));

        return { path, sha256: sha256Hex(full), bytes: statSync(full).size };
    });

    const manifest = validateManifest({
        schema_version: 1,
        runtime_version: version,
        executable: EXECUTABLE_NAME,
        files,
    });

    writeFileSync(join(directory, MANIFEST_FILE_NAME), `${JSON.stringify(manifest, null, 4)}\n`);
    writeFileSync(join(directory, CHECKSUM_FILE_NAME), formatChecksumFile(files));

    // Re-read what was written with the verifier's rules, so a bug here is caught here.
    verifyRuntimeDirectory(directory);

    if (updateManifest) {
        updateRuntimeJson(manifestPath ?? DEFAULT_MANIFEST, { manifest, status, sourceCommit, builtAt });
    }

    return { directory, manifest };
}

/** Records the assembled package in runtime.json; refuses a version mismatch. */
export function updateRuntimeJson(path, { manifest, status, sourceCommit, builtAt }) {
    const document = JSON.parse(readFileSync(path, 'utf8'));

    if (document.runtime_version !== manifest.runtime_version) {
        fail(`runtime.json pins runtime_version ${document.runtime_version}; bump it before assembling ${manifest.runtime_version}`);
    }

    document.status = status;
    document.built_at = builtAt ?? new Date().toISOString();
    document.source_commit = sourceCommit ?? document.source_commit ?? null;
    document.package = manifest;

    if (document.source_commit === null) {
        fail('runtime.json needs a source_commit; pass --source-commit <sha> (CI: $GITHUB_SHA)');
    }

    writeFileSync(path, `${JSON.stringify(document, null, 4)}\n`);

    return document;
}

/** The manifest the installed runtime must carry, read back from runtime.json for comparison. */
export function packageFromRuntimeJson(path) {
    const document = JSON.parse(readFileSync(path, 'utf8'));

    if (document.package === null) {
        return null;
    }

    const manifest = validateManifest(document.package);

    if (! manifest.files.every((entry) => isSha256Hex(entry.sha256))) {
        fail('runtime.json package has a malformed digest');
    }

    return manifest;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try {
        const options = parseArgs(process.argv.slice(2));

        if (! existsSync(options.manifest)) {
            fail(`${options.manifest} does not exist`);
        }

        const { directory, manifest } = assembleRuntime(options);
        const total = manifest.files.reduce((sum, entry) => sum + entry.bytes, 0);

        console.log(`ok    ${relative(REPO_ROOT, directory)}: ${manifest.files.length} files, ${total} bytes, manifest.json + SHA256SUMS written`);

        if (options.updateManifest) {
            console.log(`ok    ${relative(REPO_ROOT, options.manifest)} updated (status ${options.status})`);
        }
    } catch (error) {
        if (error instanceof AssembleError || error instanceof RuntimeManifestError) {
            console.error(`assemble-runtime: ${error.message}`);
            process.exit(1);
        }

        throw error;
    }
}
