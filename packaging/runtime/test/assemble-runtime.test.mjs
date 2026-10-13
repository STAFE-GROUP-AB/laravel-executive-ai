// Assembles a tiny runtime from a stub binary and proves the directory satisfies every rule
// PackageVerifier (apps/macos) enforces: schema-1 manifest, SHA256SUMS agreement, exact sizes
// and digests, no unlisted file, no symlink, safe paths — and that the forbidden inputs are
// refused rather than dropped.

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { chmodSync, existsSync, readFileSync, rmSync, statSync, symlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';

import { AssembleError, EXECUTABLE_NAME, assembleRuntime, listAppFiles, packageFromRuntimeJson, parseArgs, recordArtifact, updateRuntimeJson } from '../assemble-runtime.mjs';
import {
    CHECKSUM_FILE_NAME,
    MANIFEST_FILE_NAME,
    RuntimeManifestError,
    isSafeRelativePath,
    listFiles,
    parseChecksumFile,
    sha256Hex,
    validateManifest,
    verifyRuntimeDirectory,
} from '../lib/manifest.mjs';
import { COMMIT, COMMITTED_RUNTIME_JSON, RUNTIME_DIR, readJson, tempDir, write, writeRuntimeJsonCopy, writeStubApp, writeStubBinary } from './helpers.mjs';

function assemble(root, overrides = {}) {
    const app = writeStubApp(root);
    const binary = writeStubBinary(root);
    const manifest = writeRuntimeJsonCopy(root);

    return assembleRuntime({
        binary,
        app,
        version: '0.1.0',
        out: join(root, 'out', '0.1.0'),
        manifest,
        sourceCommit: COMMIT,
        builtAt: '2026-10-10T12:00:00.000Z',
        ...overrides,
    });
}

const expectRefusal = (fn, pattern) => assert.throws(fn, (error) => error instanceof AssembleError && pattern.test(error.message));

test('assembles the ADR-001 D4 layout with a schema-1 manifest and SHA256SUMS that agree', () => {
    const root = tempDir();
    const { directory, manifest } = assemble(root);

    for (const name of [EXECUTABLE_NAME, 'Caddyfile', 'php.ini', 'app/public/index.php', MANIFEST_FILE_NAME, CHECKSUM_FILE_NAME]) {
        assert.ok(existsSync(join(directory, name)), `${name} present`);
    }

    assert.equal(manifest.schema_version, 1);
    assert.equal(manifest.runtime_version, '0.1.0');
    assert.equal(manifest.executable, EXECUTABLE_NAME);
    assert.deepEqual(Object.keys(manifest), ['schema_version', 'runtime_version', 'executable', 'files']);

    const paths = manifest.files.map((entry) => entry.path);
    assert.deepEqual(paths, [...paths].sort(), 'files are sorted');
    assert.ok(paths.includes(EXECUTABLE_NAME));
    assert.ok(paths.every(isSafeRelativePath));

    const sums = parseChecksumFile(readFileSync(join(directory, CHECKSUM_FILE_NAME), 'utf8'));
    assert.equal(sums.size, manifest.files.length);

    for (const entry of manifest.files) {
        assert.equal(sums.get(entry.path), entry.sha256);
        assert.equal(statSync(join(directory, entry.path)).size, entry.bytes);
        assert.equal(sha256Hex(join(directory, entry.path)), entry.sha256);
    }

    assert.equal(statSync(join(directory, EXECUTABLE_NAME)).mode & 0o111, 0o111, 'binary is executable');
    assert.deepEqual(verifyRuntimeDirectory(directory), manifest);
    rmSync(root, { recursive: true, force: true });
});

test('the written manifest.json is byte-for-byte what runtime.json records as package', () => {
    const root = tempDir();
    const { directory } = assemble(root);
    const runtimeJson = readJson(join(root, 'runtime.json'));

    assert.equal(runtimeJson.status, 'built');
    assert.equal(runtimeJson.built_at, '2026-10-10T12:00:00.000Z');
    assert.equal(runtimeJson.source_commit, COMMIT);
    assert.deepEqual(runtimeJson.package, readJson(join(directory, MANIFEST_FILE_NAME)));
    assert.deepEqual(packageFromRuntimeJson(join(root, 'runtime.json')), runtimeJson.package);
    rmSync(root, { recursive: true, force: true });
});

test('the committed runtime.json is internally consistent: a package only when built, an artifact only when signed', () => {
    const committed = readJson(COMMITTED_RUNTIME_JSON);

    assert.equal(committed.schema_version, 1);
    assert.ok(['unbuilt', 'built', 'signed'].includes(committed.status));
    assert.equal(committed.components.frankenphp.version, '1.13.0');
    assert.equal(committed.http.preferred_port, 8787);
    assert.ok(! committed.components.php_extensions.some((ext) => ['gmp', 'gettext', 'readline', 'imagick'].includes(ext)));
    assert.ok(committed.components.caddy_modules_excluded.some((module) => module.includes('mercure')));

    if (committed.status === 'unbuilt') {
        assert.equal(committed.package, null);
        assert.equal(committed.artifact, null);
        assert.equal(packageFromRuntimeJson(COMMITTED_RUNTIME_JSON), null);

        return;
    }

    // Built or signed: `package` is a manifest the Swift verifier would accept, and it is the
    // one PackageVerifier will compare the installed directory against.
    const manifest = packageFromRuntimeJson(COMMITTED_RUNTIME_JSON);
    assert.notEqual(manifest, null);
    assert.equal(manifest.runtime_version, committed.runtime_version);
    assert.equal(manifest.executable, EXECUTABLE_NAME);
    assert.match(committed.source_commit, /^[0-9a-f]{40}$/);

    if (committed.artifact !== null) {
        assert.equal(committed.status, 'signed', 'only a signed package may be published');
        assert.match(committed.artifact.sha256, /^[0-9a-f]{64}$/);
        assert.ok(committed.artifact.url.endsWith(`/releases/download/${committed.artifact.tag}/${committed.artifact.asset}`));
    }
});

test('recordArtifact pins where a signed package was published, and refuses everything else', () => {
    const root = tempDir();
    const good = {
        tag: 'runtime-8.5.11-1.13.0-1',
        asset: 'laravel-executive-ai-runtime-0.1.0-8.5.11-1.13.0-1.tar.gz',
        sha256: 'b'.repeat(64),
        bytes: 123456,
    };
    good.url = `https://github.com/STAFE-GROUP-AB/laravel-executive-ai/releases/download/${good.tag}/${good.asset}`;

    // An unbuilt manifest has nothing to publish.
    const unbuilt = writeRuntimeJsonCopy(root);
    expectRefusal(() => recordArtifact(unbuilt, good), /status "signed"/);

    // Assemble, then sign-and-assemble: the signed assembly is what may be published.
    const signed = assemble(root, { manifest: unbuilt, status: 'signed' }).manifest;
    assert.equal(readJson(unbuilt).status, 'signed');
    assert.equal(readJson(unbuilt).artifact, null, 'an assembly leaves no stale artifact behind');

    const document = recordArtifact(unbuilt, good);
    assert.deepEqual(document.artifact, { tag: good.tag, asset: good.asset, url: good.url, sha256: good.sha256, bytes: good.bytes });
    assert.deepEqual(document.package, signed, 'recording an artifact does not touch the package');

    for (const [broken, pattern] of [
        [{ ...good, tag: 'runtime-8.5.11-1.13.0' }, /artifact-tag/],
        [{ ...good, tag: 'v1' }, /artifact-tag/],
        [{ ...good, url: 'http://github.com/x/y/releases/download/runtime-8.5.11-1.13.0-1/a.tar.gz' }, /release-asset URL/],
        [{ ...good, url: 'https://example.com/a.tar.gz' }, /release-asset URL/],
        [{ ...good, asset: 'other.tar.gz' }, /must point at the given tag and asset/],
        [{ ...good, sha256: 'B'.repeat(64) }, /64 lowercase hex/],
        [{ ...good, sha256: 'abc' }, /64 lowercase hex/],
        [{ ...good, bytes: 0 }, /positive integer/],
        [{ ...good, bytes: 'many' }, /positive integer/],
    ]) {
        expectRefusal(() => recordArtifact(unbuilt, broken), pattern);
    }

    // Re-recording the package after publishing clears the artifact: a re-assembled directory is
    // not the one behind the published tarball, so the URL must not survive it.
    assert.notEqual(readJson(unbuilt).artifact, null);
    updateRuntimeJson(unbuilt, { manifest: signed, status: 'signed', sourceCommit: COMMIT, builtAt: '2026-10-13T00:00:00.000Z' });
    assert.equal(readJson(unbuilt).artifact, null);

    rmSync(root, { recursive: true, force: true });
});

test('--verify re-applies the installer rules to an existing directory', () => {
    const root = tempDir();
    const { directory } = assemble(root);
    const cli = join(RUNTIME_DIR, 'assemble-runtime.mjs');

    const ok = spawnSync(process.execPath, [cli, '--verify', directory], { encoding: 'utf8' });
    assert.equal(ok.status, 0, ok.stderr);
    assert.match(ok.stdout, /runtime 0\.1\.0, \d+ files/);

    writeFileSync(join(directory, 'app', 'public', 'extra.php'), '<?php');
    const unlisted = spawnSync(process.execPath, [cli, '--verify', directory], { encoding: 'utf8' });
    assert.equal(unlisted.status, 1);
    assert.match(unlisted.stderr, /not listed in the manifest/);

    const missing = spawnSync(process.execPath, [cli, '--verify', join(root, 'nope')], { encoding: 'utf8' });
    assert.equal(missing.status, 1);
    assert.match(missing.stderr, /manifest\.json/);

    rmSync(root, { recursive: true, force: true });
});

test('Caddyfile and php.ini are static: loopback only, env placeholders, no path, no secret', () => {
    const root = tempDir();
    const { directory } = assemble(root);
    const caddy = readFileSync(join(directory, 'Caddyfile'), 'utf8');
    const ini = readFileSync(join(directory, 'php.ini'), 'utf8');

    assert.match(caddy, /bind 127\.0\.0\.1/);
    assert.match(caddy, /http:\/\/127\.0\.0\.1:\{\$LEA_HTTP_PORT\}/);
    assert.match(caddy, /root \* "\{\$LEA_RUNTIME_DIR\}\/app\/public"/);
    assert.match(caddy, /admin off/);
    assert.match(caddy, /persist_config off/);
    assert.doesNotMatch(caddy, /\{\{[A-Z_]+\}\}|\/Users\/|:8787|0\.0\.0\.0|tls /);

    assert.match(ini, /^opcache\.enable = 1$/m);
    assert.match(ini, /^opcache\.jit = disable$/m);
    assert.match(ini, /^opcache\.validate_timestamps = 0$/m);
    assert.match(ini, /^pcre\.jit = 1$/m);
    assert.match(ini, /^expose_php = Off$/m);
    assert.match(ini, /^display_errors = Off$/m);
    assert.doesNotMatch(ini, /\/Users\/|APP_KEY|password|token|\{\{/i);

    // Every path-valued variable must be quoted. `LEA_RUNTIME_DIR` expands to a path under
    // `~/Library/Application Support/`, which contains a space, and the Caddyfile parser splits an
    // unquoted token on whitespace: `root * {$LEA_RUNTIME_DIR}/app/public` was read as three
    // arguments, `adapt` refused the file, and the core exited straight after "using config from
    // file" with nothing else in its log — restarted on the backoff forever (2026-10-13, on the
    // owner's Mac). No other test could show it, because they all run under temporary directories
    // whose paths have no spaces, which is exactly why this one is a *shape* assertion.
    for (const match of caddy.matchAll(/\{\$(LEA_[A-Z_]*(?:DIR|PATH|FILE|ROOT))\}/g)) {
        const line = caddy.slice(0, match.index).split('\n').pop() + caddy.slice(match.index).split('\n')[0];
        assert.ok(
            /"[^"\n]*\{\$LEA_[A-Z_]+\}[^"\n]*"/.test(line),
            `${match[1]} expands to a path that may contain a space, so it must be quoted: ${line.trim()}`
        );
    }

    assert.equal(caddy, readFileSync(join(RUNTIME_DIR, 'templates', 'Caddyfile'), 'utf8'));
    assert.equal(ini, readFileSync(join(RUNTIME_DIR, 'templates', 'php.ini'), 'utf8'));
    rmSync(root, { recursive: true, force: true });
});

test('excludes tests, storage and node_modules from the app tree but keeps bootstrap/cache package discovery', () => {
    const root = tempDir();
    const app = writeStubApp(root);
    write(app, 'tests/Feature/ExampleTest.php', '<?php');
    write(app, 'storage/logs/laravel.log', 'nope');
    write(app, 'node_modules/left-pad/index.js', '');

    const files = listAppFiles(app);

    assert.ok(files.includes('bootstrap/cache/packages.php'));
    assert.ok(files.includes('bootstrap/cache/services.php'));
    assert.ok(! files.some((path) => path.startsWith('tests/') || path.startsWith('storage/') || path.startsWith('node_modules/')));
    rmSync(root, { recursive: true, force: true });
});

test('`--app .` with the default output works: an out directory under an excluded entry is allowed', () => {
    // What the release build does (README §3): the tree is the repository root and the output
    // lands in packaging/build/, which the walk never descends into.
    const root = tempDir();
    const app = writeStubApp(root);
    const binary = writeStubBinary(root);
    const manifest = writeRuntimeJsonCopy(root);

    const { directory, manifest: written } = assembleRuntime({
        binary,
        app,
        version: '0.1.0',
        out: join(app, 'packaging', 'build', 'runtime', '0.1.0'),
        manifest,
        sourceCommit: COMMIT,
    });

    assert.ok(directory.includes(join('packaging', 'build', 'runtime')));
    assert.ok(! written.files.some((entry) => entry.path.includes('packaging/')), 'the output is not copied into itself');
    assert.doesNotThrow(() => verifyRuntimeDirectory(directory));
    rmSync(root, { recursive: true, force: true });
});

test('drops the committed .env.example from the top level but keeps refusing every other .env', () => {
    const root = tempDir();
    const app = writeStubApp(root);
    write(app, '.env.example', "APP_NAME=Laravel Executive AI\nAPP_KEY=\n");

    const files = listAppFiles(app);

    assert.ok(! files.includes('.env.example'), 'the template is dropped, not shipped');
    assert.ok(files.includes('artisan'), 'the rest of the tree is unaffected');

    // Deeper in the tree it is still an abort: only the repository's own root template is known
    // to be value-free, and the whole point of the rule is that nothing env-shaped slips in.
    write(app, 'config/.env.example', 'APP_KEY=');
    expectRefusal(() => listAppFiles(app), /environment file/);

    rmSync(root, { recursive: true, force: true });
});

test('refuses .env files, environment-derived caches, databases, keys, symlinks and dev dependencies', () => {
    const cases = [
        ['.env', 'secret', /environment file/],
        ['.env.production', 'secret', /environment file/],
        ['config/.env.local', 'secret', /environment file/],
        ['bootstrap/cache/config.php', '<?php', /environment-derived cache/],
        ['bootstrap/cache/routes-v7.php', '<?php', /environment-derived cache/],
        ['database/database.sqlite', '', /database/],
        ['storage-keys/oauth-private.key', 'k', /key/],
        ['auth.json', '{}', /Composer credentials/],
        ['vendor/pestphp/pest/src/Pest.php', '<?php', /dev dependencies/],
    ];

    for (const [path, contents, pattern] of cases) {
        const root = tempDir();
        const app = writeStubApp(root);
        write(app, path, contents);
        expectRefusal(() => listAppFiles(app), pattern);
        rmSync(root, { recursive: true, force: true });
    }

    const root = tempDir();
    const app = writeStubApp(root);
    symlinkSync('/etc/hosts', join(app, 'public', 'hosts'));
    expectRefusal(() => listAppFiles(app), /symlink/);
    rmSync(root, { recursive: true, force: true });
});

test('refuses an incomplete app tree, an empty binary, a bad version and a version runtime.json does not pin', () => {
    const root = tempDir();
    const app = join(root, 'bare');
    write(app, 'README.md', '');
    expectRefusal(() => listAppFiles(app), /has no artisan/);

    // Every call targets a throw-away runtime.json copy, never the committed one.
    const stub = writeStubApp(root);
    const copy = writeRuntimeJsonCopy(root);
    const base = { app: stub, version: '0.1.0', out: join(root, 'o'), manifest: copy, sourceCommit: COMMIT };
    const empty = write(root, 'empty', '');
    expectRefusal(() => assembleRuntime({ ...base, binary: empty }), /is empty/);

    const binary = writeStubBinary(root);
    expectRefusal(() => assembleRuntime({ ...base, binary, version: '1.0' }), /MAJOR\.MINOR\.PATCH/);
    expectRefusal(() => assembleRuntime({ ...base, binary, sourceCommit: 'abc' }), /40-character/);
    expectRefusal(() => assembleRuntime({ ...base, binary, out: join(stub, 'nested') }), /inside --app/);
    expectRefusal(() => assembleRuntime({ ...base, binary, out: join(stub, 'public', 'runtime') }), /inside --app/);
    expectRefusal(() => assembleRuntime({ ...base, binary, sourceCommit: undefined }), /needs a source_commit/);

    const mismatched = writeRuntimeJsonCopy(join(root, 'm'), { runtime_version: '0.2.0' });
    expectRefusal(() => assembleRuntime({ ...base, binary, manifest: mismatched }), /bump it/);
    rmSync(root, { recursive: true, force: true });
});

test('verifier parity: tampering, an unlisted file, a symlink and a bad checksum file are each refused', () => {
    const root = tempDir();
    const { directory } = assemble(root);
    const code = (fn) => {
        try {
            fn();
        } catch (error) {
            assert.ok(error instanceof RuntimeManifestError, `expected RuntimeManifestError, got ${error}`);

            return error.code;
        }

        return 'passed';
    };

    assert.equal(code(() => verifyRuntimeDirectory(directory)), 'passed');

    const caddy = join(directory, 'Caddyfile');
    const original = readFileSync(caddy);
    writeFileSync(caddy, original.toString().replace('bind 127.0.0.1', 'bind 127.0.0.2'));
    assert.equal(code(() => verifyRuntimeDirectory(directory)), 'checksumMismatch');
    writeFileSync(caddy, original);

    writeFileSync(join(directory, 'app', 'public', 'shell.php'), '<?php system($_GET["c"]);');
    assert.equal(code(() => verifyRuntimeDirectory(directory)), 'unlistedFile');
    rmSync(join(directory, 'app', 'public', 'shell.php'));

    rmSync(caddy);
    symlinkSync('/etc/hosts', caddy);
    assert.equal(code(() => verifyRuntimeDirectory(directory)), 'notARegularFile');
    rmSync(caddy);
    writeFileSync(caddy, original);

    const sums = join(directory, CHECKSUM_FILE_NAME);
    const sumsText = readFileSync(sums, 'utf8');
    writeFileSync(sums, sumsText.replace(/^./, sumsText[0] === '0' ? '1' : '0'));
    assert.equal(code(() => verifyRuntimeDirectory(directory)), 'checksumFileDisagreesWithManifest');
    writeFileSync(sums, sumsText);

    writeFileSync(join(directory, EXECUTABLE_NAME), `${readFileSync(join(directory, EXECUTABLE_NAME))}\n`);
    assert.equal(code(() => verifyRuntimeDirectory(directory)), 'sizeMismatch');

    rmSync(join(directory, MANIFEST_FILE_NAME));
    assert.equal(code(() => verifyRuntimeDirectory(directory)), 'manifestMissing');
    rmSync(root, { recursive: true, force: true });
});

test('manifest structure rules match RuntimeManifest.validate', () => {
    const good = { path: 'frankenphp', sha256: 'a'.repeat(64), bytes: 1 };
    const manifest = (files, extra = {}) => ({ schema_version: 1, runtime_version: '0.1.0', executable: 'frankenphp', files, ...extra });

    assert.deepEqual(validateManifest(manifest([good])), manifest([good]));
    assert.throws(() => validateManifest(manifest([good], { schema_version: 2 })));
    assert.throws(() => validateManifest(manifest([good], { runtime_version: '1.0' })));
    assert.throws(() => validateManifest(manifest([])));
    assert.throws(() => validateManifest(manifest([good], { executable: 'missing' })));
    assert.throws(() => validateManifest(manifest([good, good])), /duplicate/);
    assert.throws(() => validateManifest(manifest([{ ...good, path: '../escape' }])), /unsafe/);
    assert.throws(() => validateManifest(manifest([{ ...good, path: '/abs' }])), /unsafe/);
    assert.throws(() => validateManifest(manifest([{ ...good, sha256: 'ABC' }])), /hex/);
    assert.throws(() => validateManifest(manifest([{ ...good, bytes: -1 }])), /negative/);
    assert.throws(() => validateManifest(manifest([good], { generated: true })), /exactly the keys/);

    assert.ok(isSafeRelativePath('app/public/index.php'));
    assert.ok(! isSafeRelativePath('~/x') && ! isSafeRelativePath('a//b') && ! isSafeRelativePath('a/./b') && ! isSafeRelativePath(''));

    const digest = '0'.repeat(64);
    assert.deepEqual([...parseChecksumFile(`# c\n${digest}  frankenphp\n${digest} *Caddyfile\n\n`)], [['frankenphp', digest], ['Caddyfile', digest]]);
    assert.throws(() => parseChecksumFile(''));
    assert.throws(() => parseChecksumFile('nonsense'));
    assert.throws(() => parseChecksumFile(`${digest}  a\n${digest}  a\n`), /duplicate/);
    assert.throws(() => parseChecksumFile(`${digest}  ../x\n`));
});

test('listFiles reports symlinks so they can be refused, and returns sorted slash paths', () => {
    const root = tempDir();
    write(root, 'b/two', '');
    write(root, 'a', '');
    symlinkSync('/etc/hosts', join(root, 'link'));

    assert.deepEqual(listFiles(root), ['a', 'b/two', 'link']);
    rmSync(root, { recursive: true, force: true });
});

test('a reassembly replaces the output directory instead of merging into it', () => {
    const root = tempDir();
    const { directory } = assemble(root);
    writeFileSync(join(directory, 'stale'), 'left over from a previous build');

    assemble(root);
    assert.ok(! existsSync(join(directory, 'stale')));
    assert.doesNotThrow(() => verifyRuntimeDirectory(directory));
    rmSync(root, { recursive: true, force: true });
});

test('the CLI assembles end to end and exits non-zero with a clear message on bad input', () => {
    const root = tempDir();
    const app = writeStubApp(root);
    const binary = writeStubBinary(root);
    const manifest = writeRuntimeJsonCopy(root);
    const out = join(root, 'cli-out');
    const cli = join(RUNTIME_DIR, 'assemble-runtime.mjs');

    const ok = spawnSync(process.execPath, [cli, '--binary', binary, '--app', app, '--version', '0.1.0', '--out', out, '--manifest', manifest, '--source-commit', COMMIT], { encoding: 'utf8' });
    assert.equal(ok.status, 0, ok.stderr);
    assert.match(ok.stdout, /manifest\.json \+ SHA256SUMS written/);
    assert.doesNotThrow(() => verifyRuntimeDirectory(out));

    chmodSync(binary, 0o644);
    const bad = spawnSync(process.execPath, [cli, '--binary', binary, '--app', app, '--version', 'x'], { encoding: 'utf8' });
    assert.equal(bad.status, 1);
    assert.match(bad.stderr, /assemble-runtime: --version x is not MAJOR\.MINOR\.PATCH/);

    assert.deepEqual(parseArgs(['--no-update-manifest', '--status', 'signed']).status, 'signed');
    assert.throws(() => parseArgs(['--bogus']), /unknown argument/);
    rmSync(root, { recursive: true, force: true });
});
