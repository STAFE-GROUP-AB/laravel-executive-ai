// Shape tests for the runtime release pipeline (S1-12, OPEN-QUESTIONS A23): the dispatch-only
// build-runtime workflow, the `sign.sh runtime` step it calls, the one entitlement the runtime
// binary may carry, and the `artifact` member build-macos.yml downloads. Like
// packaging/signing/test/, these cannot sign or build — they prove the pipeline is structurally
// correct and cannot silently ship something unsigned, unverified or copyleft-linked.
// Dependency-free node:test, runnable on Linux (the `fixtures` lane) and macOS.

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..', '..');

const workflowPath = join(root, '.github', 'workflows', 'build-runtime.yml');
const macosWorkflowPath = join(root, '.github', 'workflows', 'build-macos.yml');
const ciPath = join(root, '.github', 'workflows', 'ci.yml');
const buildScript = join(root, 'packaging', 'runtime', 'build-runtime.sh');
const signScript = join(root, 'packaging', 'signing', 'sign.sh');
const entitlementsPath = join(root, 'packaging', 'signing', 'frankenphp.entitlements');
const runtimeJsonPath = join(root, 'packaging', 'manifest', 'runtime.json');
const schemaPath = join(root, 'packaging', 'manifest', 'runtime.schema.json');

const workflow = readFileSync(workflowPath, 'utf8');
const macosWorkflow = readFileSync(macosWorkflowPath, 'utf8');
const build = readFileSync(buildScript, 'utf8');
const sign = readFileSync(signScript, 'utf8');
const entitlements = readFileSync(entitlementsPath, 'utf8');
const runtimeJson = JSON.parse(readFileSync(runtimeJsonPath, 'utf8'));
const schema = JSON.parse(readFileSync(schemaPath, 'utf8'));

function indentation(line) {
    return line.length - line.trimStart().length;
}

const order = (text, ...needles) => {
    const positions = needles.map((needle) => {
        const at = text.indexOf(needle);
        assert.notEqual(at, -1, `${needle} present`);

        return at;
    });

    assert.deepEqual(positions, [...positions].sort((a, b) => a - b), `in order: ${needles.join(' -> ')}`);
};

test('build-runtime runs on manual dispatch only — never on a pull request or a push', () => {
    const triggers = workflow.slice(workflow.indexOf('\non:\n'), workflow.indexOf('\npermissions:'));

    assert.match(triggers, /workflow_dispatch:\n/);
    assert.doesNotMatch(triggers, /pull_request/);
    assert.doesNotMatch(triggers, /\n\s*push:/);
    assert.doesNotMatch(triggers, /schedule/);

    // Both inputs exist, because the two things an operator chooses are the package revision and
    // whether the run publishes.
    assert.match(triggers, /revision:\n/);
    assert.match(triggers, /publish:\n/);
});

test('macos-15, a long timeout, and every action pinned to a full commit SHA', () => {
    assert.match(workflow, /^\s+runs-on: macos-15$/m);

    const timeout = Number(workflow.match(/^\s+timeout-minutes: (\d+)$/m)?.[1]);
    assert.ok(timeout >= 60, `the build needs a long timeout, got ${timeout}`);

    const uses = [...workflow.matchAll(/^\s+uses: (\S+)(?: #.*)?$/gm)].map((match) => match[1]);
    assert.ok(uses.length >= 3, 'actions are used');
    for (const reference of uses) {
        assert.match(reference, /^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/, `${reference} pinned to a full SHA`);
    }
});

test('the only write permission is contents, which exists to create the pre-release', () => {
    const permissions = workflow.slice(workflow.indexOf('\npermissions:'), workflow.indexOf('\nconcurrency:'));

    assert.match(permissions, /contents: write/);
    assert.equal((permissions.match(/:\s*write\b/g) ?? []).length, 1, 'exactly one write scope');
    // The workflow publishes a release; it must not push a commit to the repository.
    assert.doesNotMatch(workflow, /git (commit|push)/);
});

test('secrets reach only the signing step, as `${{ secrets.APPLE_* }}` inside env blocks, never echoed', () => {
    const lines = workflow.split('\n');
    const secretLines = lines.map((line, index) => [line, index]).filter(([line]) => line.includes('secrets.'));
    assert.ok(secretLines.length >= 4, 'secrets are referenced');

    for (const [line, index] of secretLines) {
        assert.match(
            line,
            /^\s+(APPLE_[A-Z0-9_]+|HAS_[A-Z0-9_]+): \$\{\{ secrets\.APPLE_(CERTIFICATE_P12|CERTIFICATE_PASSWORD|TEAM_ID|KEYCHAIN_PASSWORD)( != '')? \}\}$/,
            `line ${index + 1} maps a secret into env: ${line.trim()}`,
        );

        const depth = indentation(line);
        let parent = index - 1;
        while (parent >= 0 && (lines[parent].trim() === '' || indentation(lines[parent]) >= depth)) {
            parent -= 1;
        }
        assert.equal(lines[parent].trim(), 'env:', `line ${index + 1} is inside an env block`);
    }

    // The certificate itself appears in exactly one step: the one that signs the binary.
    const certificateSteps = secretLines
        .filter(([line]) => line.includes('secrets.APPLE_CERTIFICATE_P12') && ! line.includes("!= ''"))
        .map(([, index]) => {
            let cursor = index;
            while (cursor >= 0 && ! /^\s+- name:/.test(lines[cursor])) {
                cursor -= 1;
            }

            return lines[cursor].trim();
        });
    assert.deepEqual(certificateSteps, ['- name: Sign the runtime binary']);

    assert.doesNotMatch(workflow, /echo[^\n]*\$APPLE_/, 'no echo of an APPLE_* variable');
    assert.doesNotMatch(workflow, /echo[^\n]*secrets\./, 'no echo of a secret');
});

test('it fails closed without an identity instead of publishing an unsigned package', () => {
    const gate = workflow.slice(workflow.indexOf('- name: Require a signing identity'), workflow.indexOf('- name: Read the pinned versions'));

    assert.match(gate, /HAS_CERTIFICATE" != true \] \|\| \[ "\$HAS_CERTIFICATE_PASSWORD" != true \] \|\| \[ "\$HAS_TEAM_ID" != true \]/);
    assert.match(gate, /::error/);
    assert.match(gate, /exit 1/);
    assert.doesNotMatch(workflow, /LXAI_ALLOW_UNSIGNED/, 'there is no unsigned escape hatch here');
});

test('the licence gate is never overridden, in this workflow or in build-macos', () => {
    // Comments may name the override (to say it is never used); no step may set it.
    const code = (text) => text.split('\n').filter((line) => ! /^\s*#/.test(line)).join('\n');

    for (const [name, text] of [['build-runtime.yml', workflow], ['build-macos.yml', macosWorkflow]]) {
        assert.doesNotMatch(code(text), /LXAI_ALLOW_COPYLEFT_FOR_MEASUREMENT/, `${name} never sets the copyleft override`);
    }

    // The gate itself still exists, fails closed, and the A23 fixes are both in the recipe.
    assert.match(build, /die "copyleft licence text in the notice set/);
    assert.match(build, /nobadger,nomysql,nopgx,nomercure/);
    assert.match(build, /the binary was not built with the nomercure tag/);
    assert.match(build, /lib-depends-linux/);
    assert.match(build, /-DIconv_INCLUDE_DIR=/);
    assert.match(build, /libiconv\.a" "\$spc_dir\/buildroot\/lib\/libcharset\.a/);
});

test('build, gate, sign, assemble --status signed, pack, record, publish — in that order', () => {
    order(
        workflow,
        'packaging/runtime/build-runtime.sh',
        '- name: Summarise the licence gate',
        'packaging/signing/sign.sh runtime',
        '--status signed',
        '- name: Pack and verify the package',
        '--record-artifact',
        'gh release create',
    );

    // `--status signed` must hash the signed bytes: the assembler runs after the signing step.
    assert.ok(workflow.indexOf('sign.sh runtime') < workflow.indexOf('assemble-runtime.mjs \\'), 'the binary is signed before it is hashed');
    assert.match(workflow, /--source-commit "\$GITHUB_SHA"/);
    assert.match(workflow, /gh release create "\$TAG"[\s\S]*--prerelease/);
    assert.match(workflow, /--no-mac-metadata/, 'the tarball carries no AppleDouble files, which would be unlisted');
    assert.match(workflow, /assemble-runtime\.mjs --verify "\$roundtrip/, 'the unpacked tarball is verified with the installer rules');
    assert.match(workflow, /codesign --verify --strict --verbose=2 "\$roundtrip/);
});

test('the Laravel tree is prepared for release: production dependencies, built assets, no env cache', () => {
    const install = workflow.slice(workflow.indexOf('- name: Install the Laravel tree for release'), workflow.indexOf('- name: Assemble the signed runtime directory'));

    assert.match(install, /composer install --no-dev --optimize-autoloader/);
    assert.match(install, /npm run build/);
    assert.match(install, /php artisan package:discover/);
    assert.match(install, /test -f public\/build\/manifest\.json/);
    assert.doesNotMatch(install, /config:cache|route:cache|event:cache/, 'ADR-003: no environment-derived cache in the runtime');
    assert.doesNotMatch(workflow, /cp \.env|\.env\.example/, 'no .env is ever created (the assembler refuses one)');
});

test('the runtime binary is signed with the hardened runtime and exactly one entitlement', () => {
    assert.equal((entitlements.match(/<key>/g) ?? []).length, 1, 'exactly one entitlement key');
    assert.match(entitlements, /<key>com\.apple\.security\.cs\.allow-jit<\/key>\s*<true\/>/);
    for (const forbidden of ['allow-unsigned-executable-memory', 'disable-library-validation', 'allow-dyld-environment-variables', 'keychain-access-groups', 'app-sandbox']) {
        assert.ok(! entitlements.includes(`<key>${forbidden}`), `${forbidden} is not granted`);
    }

    // ADR-001 D2 is also what runtime.json promises, and the two must not drift.
    assert.deepEqual(runtimeJson.signing.entitlements, ['com.apple.security.cs.allow-jit']);
    assert.equal(runtimeJson.signing.hardened_runtime, true);

    // sign.sh runtime: hardened runtime + timestamp come from sign_one; the rest is its own.
    const runtime = sign.slice(sign.indexOf('sign_runtime() {'), sign.indexOf('sign_dmg() {'));
    assert.match(runtime, /RUNTIME_ENTITLEMENTS must grant exactly com\.apple\.security\.cs\.allow-jit/);
    assert.match(runtime, /sign_one "\$identity" "\$binary" --identifier "\$identifier" --entitlements "\$RUNTIME_ENTITLEMENTS"/);
    assert.match(runtime, /codesign --verify --strict/);
    assert.match(runtime, /keychain entitlement; ADR-003 D2 forbids it/);
    assert.match(runtime, /reports TeamIdentifier/);
    assert.match(sign, /RUNTIME_ENTITLEMENTS="\$SCRIPT_DIR\/frankenphp\.entitlements"/);
});

test('sign.sh runtime fails closed without an identity and refuses a missing binary', () => {
    if (process.platform !== 'darwin') {
        return;
    }

    const minimalEnv = { PATH: process.env.PATH, HOME: process.env.HOME, CI: '1' };
    const run = (args, env = {}) => spawnSync('bash', [signScript, ...args], { encoding: 'utf8', env: { ...minimalEnv, ...env } });

    const missing = run(['runtime', join(root, 'packaging', 'build', 'does-not-exist')]);
    assert.equal(missing.status, 1, missing.stderr);
    assert.match(missing.stderr, /is not a file/);

    const noIdentity = run(['runtime', signScript]);
    assert.equal(noIdentity.status, 2, noIdentity.stderr);
    assert.match(noIdentity.stderr, /refusing to continue: no signing identity configured/);

    const badTeam = run(['runtime', signScript], { APPLE_TEAM_ID: 'not-a-team', APPLE_CERTIFICATE_P12: 'eA==', APPLE_CERTIFICATE_PASSWORD: 'x' });
    assert.equal(badTeam.status, 1, badTeam.stderr);
    assert.match(badTeam.stderr, /APPLE_TEAM_ID must be the 10-character/);

    assert.equal(run([]).status, 64, 'usage');
});

test('the release tag the workflow builds matches the one the schema and runtime.json accept', () => {
    const pattern = new RegExp(schema.$defs.artifact.properties.tag.pattern);

    assert.match(workflow, /tag="runtime-\$\{php\}-\$\{frankenphp\}-\$\{REVISION\}"/);
    assert.ok(pattern.test(`runtime-${runtimeJson.components.php.version}-${runtimeJson.components.frankenphp.version}-1`), 'the pinned versions produce a valid tag');
    assert.ok(! pattern.test('runtime-8.5.11-1.13.0-0'), 'revision 0 is not a revision');
    assert.ok(! pattern.test('runtime-8.5.11-1.13.0'), 'the revision is required');

    assert.match(workflow, /REVISION" \| grep -Eq '\^\[1-9\]\[0-9\]\*\$'/, 'the revision input is validated, not interpolated blindly');
    assert.doesNotMatch(workflow, /\$\{\{ inputs\.revision \}\}[^\n]*\$\(/, 'the input never reaches a shell substitution');
    assert.match(workflow, /gh release view "\$TAG"[\s\S]*Bump the revision input/, 'a published tag is never overwritten');
});

test('the schema and the committed runtime.json agree about `artifact`', () => {
    assert.ok(schema.required.includes('artifact'));
    assert.deepEqual(Object.keys(schema.$defs.artifact.properties).sort(), ['asset', 'bytes', 'sha256', 'tag', 'url']);
    assert.equal(schema.$defs.artifact.additionalProperties, false);
    assert.match(new RegExp(schema.$defs.artifact.properties.url.pattern).source, /releases/);

    // `unbuilt` means nothing is published, and that is enforced by the schema, not by a comment.
    assert.equal(schema.allOf[0].then.properties.artifact.type, 'null');

    assert.ok('artifact' in runtimeJson, 'runtime.json carries the member');

    if (runtimeJson.artifact !== null) {
        assert.equal(runtimeJson.status, 'signed', 'only a signed package may be published');
        assert.notEqual(runtimeJson.package, null);
        assert.equal(runtimeJson.artifact.url, `https://github.com/STAFE-GROUP-AB/laravel-executive-ai/releases/download/${runtimeJson.artifact.tag}/${runtimeJson.artifact.asset}`);
        assert.match(runtimeJson.artifact.sha256, /^[0-9a-f]{64}$/);
        assert.ok(runtimeJson.artifact.bytes > 0);
    }
});

test('build-macos downloads the pinned asset, verifies it and unpacks it before anything is signed', () => {
    const step = macosWorkflow.slice(macosWorkflow.indexOf('- name: Add the runtime package to the bundle'), macosWorkflow.indexOf('- name: Verify the runtime carries the app\'s Team ID'));

    assert.match(step, /curl --fail --silent --show-error --location --retry 3/);
    assert.match(step, /shasum -a 256 --check --strict/);
    assert.match(step, /runtime\.json says \$bytes/);
    assert.match(step, /tar -xzf[^\n]*--strip-components=1 -C "\$target"/);
    assert.match(step, /assemble-runtime\.mjs --verify "\$target"/);
    assert.match(step, /codesign --verify --strict --verbose=2 "\$target\/frankenphp"/);
    assert.match(step, /allow-jit/);
    assert.match(step, /::notice[^\n]*no runtime package/, 'an unpublished runtime is a notice, not a failure');

    order(
        macosWorkflow,
        '- name: Add the runtime package to the bundle',
        "- name: Verify the runtime carries the app's Team ID",
        '- name: Sign the app and the Supervisor',
        '- name: Create the disk image',
    );

    // The runtime is already signed; `sign.sh app` must not walk into it.
    const signApp = sign.slice(sign.indexOf('sign_app() {'), sign.indexOf('# The bundled FrankenPHP binary'));
    assert.doesNotMatch(signApp, /Resources/, 'sign.sh app touches nothing under Contents/Resources');
    for (const line of sign.split('\n').filter((candidate) => candidate.includes('--sign'))) {
        assert.doesNotMatch(line, /Resources\/runtime/, `no codesign call targets the bundled runtime: ${line.trim()}`);
    }
    assert.match(macosWorkflow, /Team ID is not compared/, 'unsigned PR runs say what they skipped');
});

test('ci.yml runs this suite, and the files the workflows call exist and parse', () => {
    const ci = readFileSync(ciPath, 'utf8');
    assert.ok(ci.includes("node --test 'packaging/runtime/test/*.test.mjs'"), 'the fixtures lane runs the runtime suite');

    for (const script of [buildScript, signScript]) {
        assert.ok(existsSync(script), `${script} exists`);
        assert.equal(statSync(script).mode & 0o111, 0o111, `${script} is executable`);
        assert.equal(spawnSync('bash', ['-n', script], { encoding: 'utf8' }).status, 0, `${script} parses`);
    }

    const code = (text) => text.split('\n').filter((line) => ! /^\s*#/.test(line)).join('\n');
    for (const [name, text] of [['build-runtime.yml', workflow], ['build-runtime.sh', build]]) {
        assert.doesNotMatch(code(text), /^\s*set -x\b/m, `${name} never enables xtrace`);
    }

    for (const [name, text] of [['build-runtime.yml', workflow], ['build-runtime.sh', build], ['frankenphp.entitlements', entitlements]]) {
        assert.doesNotMatch(text, /-----BEGIN/, `${name}: no PEM block`);
        assert.doesNotMatch(text, /\/Users\/[a-z]/, `${name}: no home path`);
        assert.doesNotMatch(text, /DEVELOPMENT_TEAM\s*=\s*[A-Z0-9]{10}/, `${name}: no hard-coded team`);
        assert.doesNotMatch(text, /\(([A-Z0-9]{10})\)/, `${name}: no literal Team ID`);
    }
});
