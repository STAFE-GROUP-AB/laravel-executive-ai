// Shape tests for the S1-12 DMG pipeline: the build-macos workflow, sign.sh and make-dmg.sh.
// They cannot sign (no identity in CI by design) — they prove the pipeline is structurally
// correct and safe: no hard-coded identity, secrets only via `${{ secrets.* }}` into `env:`,
// hardened runtime + timestamp, the Supervisor signed before the app with its own entitlements,
// fail closed without an identity, an honest README in the image. Dependency-free node:test,
// runnable on Linux (the `fixtures` lane) and macOS.

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync, lstatSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, readlinkSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const signingDir = join(here, '..');
const root = join(here, '..', '..', '..');

const workflowPath = join(root, '.github', 'workflows', 'build-macos.yml');
const ciPath = join(root, '.github', 'workflows', 'ci.yml');
const signScript = join(signingDir, 'sign.sh');
const dmgScript = join(signingDir, 'make-dmg.sh');
const readmePath = join(signingDir, 'README.md');
const releaseBuildDoc = join(root, 'docs', 'operations', 'release-build.md');

const workflow = readFileSync(workflowPath, 'utf8');
const sign = readFileSync(signScript, 'utf8');
const dmg = readFileSync(dmgScript, 'utf8');
const readme = readFileSync(readmePath, 'utf8');

const SECRET_NAMES = ['APPLE_CERTIFICATE_P12', 'APPLE_CERTIFICATE_PASSWORD', 'APPLE_TEAM_ID', 'APPLE_KEYCHAIN_PASSWORD'];

const minimalEnv = { PATH: process.env.PATH, HOME: process.env.HOME ?? tmpdir(), CI: '1' };

function run(script, args, env = {}) {
    return spawnSync('bash', [script, ...args], { encoding: 'utf8', env: { ...minimalEnv, ...env } });
}

function fakeApp(base, name = 'LaravelExecutiveAI.app') {
    const app = join(base, name);
    mkdirSync(join(app, 'Contents', 'MacOS'), { recursive: true });
    writeFileSync(join(app, 'Contents', 'Info.plist'), '<?xml version="1.0" encoding="UTF-8"?>\n<plist version="1.0"><dict><key>CFBundleShortVersionString</key><string>0.0.0</string></dict></plist>\n');
    writeFileSync(join(app, 'Contents', 'MacOS', 'LaravelExecutiveAI'), '#!/bin/sh\n');

    return app;
}

function indentation(line) {
    return line.length - line.trimStart().length;
}

function section(text, start, end) {
    const from = text.indexOf(start);
    assert.notEqual(from, -1, `${start} present`);
    const to = text.indexOf(end, from + start.length);
    assert.notEqual(to, -1, `${end} present after ${start}`);

    return text.slice(from, to);
}

test('the workflow runs on push to main, on manual dispatch and on PRs touching the Mac app, the signing directory or itself', () => {
    const triggers = section(workflow, '\non:\n', '\npermissions:');

    assert.match(triggers, /push:\n\s+branches:\n\s+- main\n/);
    assert.match(triggers, /workflow_dispatch:\n/);
    assert.match(triggers, /pull_request:\n\s+paths:\n/);
    for (const path of ["'apps/macos/**'", "'packaging/signing/**'", "'.github/workflows/build-macos.yml'"]) {
        assert.ok(triggers.includes(`- ${path}`), `pull_request paths include ${path}`);
    }
});

test('macos-15 runner, Xcode pinned to the version ci.yml uses, every action pinned to a full commit SHA, read-only token', () => {
    const ci = readFileSync(ciPath, 'utf8');
    const xcode = (text) => text.match(/^\s+XCODE_VERSION: '([0-9.]+)'$/m)?.[1];

    assert.ok(xcode(workflow), 'XCODE_VERSION set');
    assert.equal(xcode(workflow), xcode(ci), 'same Xcode as ci.yml');
    assert.match(workflow, /^\s+runs-on: macos-15$/m);

    const uses = [...workflow.matchAll(/^\s+uses: (\S+)(?: #.*)?$/gm)].map((match) => match[1]);
    assert.ok(uses.length >= 4, 'actions are used');
    for (const reference of uses) {
        assert.match(reference, /^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/, `${reference} pinned to a full SHA`);
    }

    assert.match(workflow, /^permissions:\n  contents: read\n/m);
    assert.doesNotMatch(workflow, /:\s*write\b/);
});

test('secrets are referenced only as `${{ secrets.APPLE_* }}`, only inside env blocks, never echoed or tested anywhere else', () => {
    const lines = workflow.split('\n');
    const secretLines = lines.map((line, index) => [line, index]).filter(([line]) => line.includes('secrets.'));
    assert.ok(secretLines.length >= 4, 'secrets are referenced');

    for (const [line, index] of secretLines) {
        assert.match(
            line,
            /^\s+(APPLE_[A-Z0-9_]+|HAS_[A-Z0-9_]+): \$\{\{ secrets\.APPLE_(CERTIFICATE_P12|CERTIFICATE_PASSWORD|TEAM_ID|KEYCHAIN_PASSWORD)( != '')? \}\}$/,
            `line ${index + 1} maps a secret into env: ${line.trim()}`,
        );

        // The nearest less-indented line above must be `env:` — the secret lives in an env block.
        const depth = indentation(line);
        let parent = index - 1;
        while (parent >= 0 && (lines[parent].trim() === '' || indentation(lines[parent]) >= depth)) {
            parent -= 1;
        }
        assert.equal(lines[parent].trim(), 'env:', `line ${index + 1} is inside an env block`);
    }

    // The boolean probe maps `!= ''`; real values only reach the two signing steps, both gated.
    const valueLines = secretLines.filter(([line]) => ! line.includes("!= ''"));
    assert.ok(valueLines.length >= 2, 'secret values are mapped for signing');
    for (const [, index] of valueLines) {
        let cursor = index;
        while (cursor >= 0 && ! /^\s+- name:/.test(lines[cursor])) {
            cursor -= 1;
        }
        const step = lines.slice(cursor, index + 1).join('\n');
        assert.match(step, /\n\s+if: steps\.mode\.outputs\.signed == 'true'\n/, `step at line ${cursor + 1} is gated on the signing mode`);
    }

    assert.doesNotMatch(workflow, /echo[^\n]*\$APPLE_/, 'no echo of an APPLE_* variable');
    assert.doesNotMatch(workflow, /echo[^\n]*secrets\./, 'no echo of a secret');
});

test('pull requests always build unsigned; signing needs all three identity secrets', () => {
    const mode = section(workflow, '- name: Decide signing mode', '- name: Select Xcode');

    assert.ok(mode.indexOf('"$EVENT_NAME" = pull_request ]; then') < mode.indexOf('signed=true'), 'the PR branch comes first and never sets signed=true');
    assert.match(mode, /"\$HAS_CERTIFICATE" = true \] && \[ "\$HAS_CERTIFICATE_PASSWORD" = true \] && \[ "\$HAS_TEAM_ID" = true \]/);
    assert.match(mode, /::warning[^\n]*UNSIGNED/);
});

test('xcodebuild itself never signs: Release build with CODE_SIGNING_ALLOWED=NO, and no `set -x` anywhere in the pipeline', () => {
    const build = section(workflow, '- name: Build Release (no signing)', '- name: Locate the bundle');

    assert.match(build, /-configuration Release/);
    assert.match(build, /CODE_SIGN_IDENTITY="" \\\n\s+CODE_SIGNING_REQUIRED=NO \\\n\s+CODE_SIGNING_ALLOWED=NO \\\n\s+build/);
    assert.doesNotMatch(build, /--sign/);

    // Comments may mention `set -x` (to forbid it); code may not contain it.
    const code = (text) => text.split('\n').filter((line) => ! /^\s*#/.test(line)).join('\n');
    for (const [name, text] of [['workflow', workflow], ['sign.sh', sign], ['make-dmg.sh', dmg]]) {
        assert.doesNotMatch(code(text), /^\s*set -x\b/m, `${name} never enables xtrace`);
        assert.doesNotMatch(code(text), /\bset -[a-wyz]*x[a-z]*\b/m, `${name} never enables xtrace via a combined flag`);
    }
    assert.match(sign, /^set \+x$/m);
    assert.match(dmg, /^set \+x$/m);
});

test('the bundle check requires the Supervisor tool and the LaunchAgent plist that S1-07 embeds', () => {
    const locate = section(workflow, '- name: Locate the bundle', '- name: Sign the app and the Supervisor');
    const plists = readdirSync(join(root, 'packaging', 'manifest', 'launchd')).filter((name) => name.endsWith('.plist'));

    assert.equal(plists.length, 1, 'one LaunchAgent plist template');
    assert.ok(locate.includes('Contents/MacOS/LaravelExecutiveAISupervisor'));
    assert.ok(locate.includes(`Contents/Library/LaunchAgents/${plists[0]}`));
});

test('the signing report and the image are uploaded for 14 days; the report and the keychain cleanup always run', () => {
    assert.ok((workflow.match(/retention-days: 14/g) ?? []).length >= 2, 'both artifacts kept 14 days');

    const record = section(workflow, '- name: Record codesign and spctl verdicts', '- name: Upload the disk image');
    assert.match(record, /if: always\(\) && steps\.locate\.outcome == 'success'/);
    assert.match(record, /sign\.sh report "\$APP_PATH" "\$SIGNING_REPORT"/);
    assert.match(record, /LXAI_EXPECT_SIGNED: \$\{\{ steps\.mode\.outputs\.signed == 'true' && '1' \|\| '0' \}\}/);

    const report = section(workflow, '- name: Upload the signing report', '- name: Upload xcresult bundle');
    assert.match(report, /if: always\(\) && steps\.locate\.outcome == 'success'/);
    assert.match(report, /path: \$\{\{ env\.SIGNING_REPORT \}\}/);
    assert.match(workflow, /SIGNING_REPORT: build\/signing-report\.txt/);

    const cleanup = section(workflow, '- name: Delete the temporary keychain', '\n  # Sprint 4');
    assert.match(cleanup, /if: always\(\)/);
    assert.match(cleanup, /sign\.sh cleanup/);
    // The `runner` context is not available in workflow- or job-level env (GitHub rejects the
    // file), so the keychain path is sign.sh's $RUNNER_TEMP default, identical in every step.
    assert.doesNotMatch(workflow, /^\s+LXAI_SIGNING_KEYCHAIN:/m, 'the workflow does not override the keychain path');
    assert.doesNotMatch(workflow, /^\s+[A-Z_]+: \$\{\{ runner\./m, 'no runner context in an env mapping');
    assert.match(sign, /printf '%s\/lxai-signing\.keychain-db' "\$RUNNER_TEMP"/, 'sign.sh defaults the keychain to $RUNNER_TEMP');
});

test('the Supervisor is signed before the app, the image after it, and the scripts the workflow calls exist and are executable', () => {
    const supervisor = workflow.indexOf('- name: Sign the app and the Supervisor');
    const image = workflow.indexOf('- name: Create the disk image');
    const signImage = workflow.indexOf('- name: Sign the disk image');
    const record = workflow.indexOf('- name: Record codesign and spctl verdicts');
    assert.ok(supervisor > 0 && supervisor < image && image < signImage && signImage < record, 'sign app → make dmg → sign dmg → report');

    for (const script of [signScript, dmgScript]) {
        assert.ok(existsSync(script), `${script} exists`);
        assert.equal(statSync(script).mode & 0o111, 0o111, `${script} is executable`);
        assert.equal(spawnSync('bash', ['-n', script], { encoding: 'utf8' }).status, 0, `${script} parses`);
    }
    assert.ok(workflow.includes('packaging/signing/sign.sh app "$APP_PATH"'));
    assert.ok(workflow.includes('packaging/signing/make-dmg.sh --app "$APP_PATH"'));
    assert.ok(workflow.includes('packaging/signing/sign.sh dmg "$DMG_PATH"'));
});

test('the notarize job is present, disabled, downstream of build-macos and points at the Sprint 4 gate', () => {
    const notarize = section(workflow, '\n  notarize:\n', '\n      - name: Not implemented');

    assert.match(notarize, /^\s+if: false$/m);
    assert.match(notarize, /^\s+needs: build-macos$/m);
    assert.ok(workflow.includes('E4-6'), 'the Sprint 4 backlog row is named');
    assert.ok(workflow.includes('packaging/notarization/README.md'));
});

test('sign.sh: hardened runtime, secure timestamp, --force, never --deep when signing, Supervisor (own entitlements, Team ID prefix expanded) before the app', () => {
    assert.match(sign, /codesign --force --options runtime --timestamp "\$@" --sign "\$identity" "\$target"/);
    for (const line of sign.split('\n').filter((candidate) => /codesign\b/.test(candidate) && /--sign\b/.test(candidate))) {
        assert.doesNotMatch(line, /--deep/, `no --deep while signing: ${line.trim()}`);
    }
    assert.match(sign, /codesign --verify --deep --strict/);

    const supervisor = sign.indexOf('sign_one "$identity" "$supervisor"');
    const app = sign.indexOf('sign_one "$identity" "$app"');
    assert.ok(supervisor > 0 && app > supervisor, 'the Supervisor tool is signed before the app bundle');
    assert.match(sign, /--identifier "\$\{prefix\}\.supervisor" --entitlements "\$processed"/);
    assert.match(sign, /sign_one "\$identity" "\$app" --entitlements "\$APP_ENTITLEMENTS"/);
    assert.match(sign, /sed "s\/\\\$\(AppIdentifierPrefix\)\/\$\{APPLE_TEAM_ID\}\.\/g"/, 'expands $(AppIdentifierPrefix) to <TEAM ID>.');
});

test('the entitlements files sign.sh references exist, and only the Supervisor carries keychain-access-groups', () => {
    const resolve = (name) => {
        const match = sign.match(new RegExp(`^${name}="\\$REPO_ROOT/([^"]+)"$`, 'm'));
        assert.ok(match, `${name} defined`);

        return join(root, ...match[1].split('/'));
    };
    const appEntitlements = resolve('APP_ENTITLEMENTS');
    const supervisorEntitlements = resolve('SUPERVISOR_ENTITLEMENTS');
    const sharedXcconfig = resolve('SHARED_XCCONFIG');

    for (const file of [appEntitlements, supervisorEntitlements, sharedXcconfig]) {
        assert.ok(existsSync(file), `${file} exists`);
    }

    const appText = readFileSync(appEntitlements, 'utf8');
    const supervisorText = readFileSync(supervisorEntitlements, 'utf8');
    const prefix = readFileSync(sharedXcconfig, 'utf8').match(/^LXAI_BUNDLE_PREFIX\s*=\s*(\S+)$/m)?.[1];

    assert.ok(prefix, 'LXAI_BUNDLE_PREFIX in Shared.xcconfig');
    assert.doesNotMatch(appText, /<key>keychain-access-groups<\/key>/, 'the GUI app has no keychain entitlement (ADR-003 D5)');
    assert.match(supervisorText, /<key>keychain-access-groups<\/key>/);
    assert.ok(supervisorText.includes(`<string>$(AppIdentifierPrefix)${prefix}.secrets</string>`), 'the one access group, prefixed by the Team ID at signing time');
    assert.equal((supervisorText.match(/<key>/g) ?? []).length, 2, 'app-sandbox false + keychain-access-groups, nothing else');
});

test('no hard-coded identity, Team ID, certificate material or home path anywhere in the pipeline or its docs', () => {
    const files = [workflowPath, signScript, dmgScript, readmePath, releaseBuildDoc, join(root, 'packaging', 'notarization', 'README.md')];

    for (const file of files) {
        assert.ok(existsSync(file), `${file} exists`);
        const text = readFileSync(file, 'utf8');
        assert.doesNotMatch(text, /-----BEGIN/, `${file}: no PEM block`);
        assert.doesNotMatch(text, /[A-Za-z0-9+/=]{120,}/, `${file}: no base64 blob`);
        assert.doesNotMatch(text, /\/Users\/[a-z]/, `${file}: no home path`);
        assert.doesNotMatch(text, /Developer ID Application: [A-Z][a-z]+ [A-Z][a-z]+/, `${file}: no real identity name`);
        assert.doesNotMatch(text, /\(([A-Z0-9]{10})\)/, `${file}: no literal Team ID`);
        assert.doesNotMatch(text, /DEVELOPMENT_TEAM\s*=\s*[A-Z0-9]{10}/, `${file}: no hard-coded DEVELOPMENT_TEAM`);
    }
});

test('sign.sh fails closed without an identity, skips only with LXAI_ALLOW_UNSIGNED=1, and validates the Team ID before touching a keychain', () => {
    const base = mkdtempSync(join(tmpdir(), 'lxai-signing-'));
    const app = fakeApp(base);
    const image = join(base, 'x.dmg');
    writeFileSync(image, 'not really a dmg');

    const closed = run(signScript, ['app', app]);
    assert.equal(closed.status, 2, closed.stderr);
    assert.match(closed.stderr, /refusing to continue: no signing identity configured/);
    assert.match(closed.stderr, /fails closed/);

    const closedImage = run(signScript, ['dmg', image]);
    assert.equal(closedImage.status, 2, closedImage.stderr);

    const skipped = run(signScript, ['app', app], { LXAI_ALLOW_UNSIGNED: '1' });
    assert.equal(skipped.status, 0, skipped.stderr);
    assert.match(skipped.stderr, /stays UNSIGNED/);

    const skippedImage = run(signScript, ['dmg', image], { LXAI_ALLOW_UNSIGNED: '1' });
    assert.equal(skippedImage.status, 0, skippedImage.stderr);

    const badTeam = run(signScript, ['app', app], { APPLE_TEAM_ID: 'not-a-team', APPLE_CERTIFICATE_P12: 'eA==', APPLE_CERTIFICATE_PASSWORD: 'x' });
    assert.equal(badTeam.status, 1, badTeam.stderr);
    assert.match(badTeam.stderr, /APPLE_TEAM_ID must be the 10-character/);
    assert.doesNotMatch(badTeam.stderr, /eA==|not-a-team/, 'values are not echoed back');

    const notAnApp = run(signScript, ['app', join(base, 'missing.app')], { LXAI_ALLOW_UNSIGNED: '1' });
    assert.equal(notAnApp.status, 1);

    const usage = run(signScript, []);
    assert.equal(usage.status, 64);

    rmSync(base, { recursive: true, force: true });
});

test('make-dmg.sh stages the app, an /Applications link and an honest README, and names the image <product>-<version>-<sha>[-unsigned].dmg', () => {
    const base = mkdtempSync(join(tmpdir(), 'lxai-dmg-'));
    const app = fakeApp(base);
    const out = join(base, 'out');

    const unsigned = run(dmgScript, ['--app', app, '--out-dir', out, '--stage-only', '--sha', 'abcdef1', '--version', '0.0.0', '--status', 'unsigned']);
    assert.equal(unsigned.status, 0, unsigned.stderr);
    assert.match(unsigned.stderr, /not creating LaravelExecutiveAI-0\.0\.0-abcdef1-unsigned\.dmg/);
    assert.equal(unsigned.stdout.trim(), join(out, 'dmg-staging'));

    const staging = join(out, 'dmg-staging');
    assert.ok(existsSync(join(staging, 'LaravelExecutiveAI.app', 'Contents', 'Info.plist')));
    assert.ok(lstatSync(join(staging, 'Applications')).isSymbolicLink());
    assert.equal(readlinkSync(join(staging, 'Applications')), '/Applications');

    const readmeText = readFileSync(join(staging, 'README.txt'), 'utf8');
    assert.match(readmeText, /LaravelExecutiveAI 0\.0\.0 \(build abcdef1\)/);
    assert.match(readmeText, /UNSIGNED debug build/);
    assert.match(readmeText, /NO PHP runtime package/);
    assert.match(readmeText, /A23/);
    assert.match(readmeText, /This is not a release/);
    assert.match(readmeText, /docs\/operations\/release-build\.md/);

    const signed = run(dmgScript, ['--app', app, '--out-dir', out, '--stage-only', '--sha', 'abcdef1', '--version', '0.0.0', '--status', 'signed']);
    assert.equal(signed.status, 0, signed.stderr);
    assert.match(signed.stderr, /not creating LaravelExecutiveAI-0\.0\.0-abcdef1\.dmg/);
    const signedReadme = readFileSync(join(staging, 'README.txt'), 'utf8');
    assert.match(signedReadme, /Developer ID signed with the hardened runtime, NOT notarized/);
    assert.match(signedReadme, /E4-6/);

    assert.notEqual(run(dmgScript, ['--app', app, '--out-dir', out, '--stage-only', '--sha', 'abcdef1', '--version', '1.0', '--status', 'unsigned']).status, 0, 'rejects a non-semver version');
    assert.notEqual(run(dmgScript, ['--app', app, '--out-dir', out, '--stage-only', '--sha', 'zzz', '--version', '0.0.0']).status, 0, 'rejects a non-hex sha');
    assert.notEqual(run(dmgScript, ['--app', app, '--out-dir', out, '--stage-only', '--sha', 'abcdef1', '--version', '0.0.0', '--status', 'notarized']).status, 0, 'rejects an unknown status');
    assert.equal(run(dmgScript, []).status, 64);

    assert.match(dmg, /hdiutil create -volname "\$VOLNAME" -srcfolder "\$STAGING" -ov -format UDZO/);
    assert.match(dmg, /hdiutil verify "\$DMG_PATH"/);
    rmSync(base, { recursive: true, force: true });
});

test('ci.yml runs this suite in the fixtures lane and the README names every secret and the unsigned escape hatch', () => {
    const ci = readFileSync(ciPath, 'utf8');
    assert.ok(ci.includes("node --test 'packaging/signing/test/*.test.mjs'"));

    for (const name of SECRET_NAMES) {
        assert.ok(readme.includes(name), `README documents ${name}`);
    }
    assert.ok(readme.includes('LXAI_ALLOW_UNSIGNED'));
    assert.ok(readme.includes('A24'), 'README carries the A24 first-signed-run checklist');
    assert.ok(readme.includes('A23'), 'README states that no runtime is bundled');
});
