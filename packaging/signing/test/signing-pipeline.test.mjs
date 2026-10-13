// Shape tests for the S1-12 DMG pipeline: the build-macos workflow, sign.sh and make-dmg.sh.
// They cannot sign (no identity in CI by design) — they prove the pipeline is structurally
// correct and safe: no hard-coded identity, secrets only via `${{ secrets.* }}` into `env:`,
// hardened runtime + timestamp, the Supervisor signed before the app with its own entitlements,
// fail closed without an identity, an honest README in the image. Dependency-free node:test,
// runnable on Linux (the `fixtures` lane) and macOS.

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
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

const SECRET_NAMES = ['APPLE_CERTIFICATE_P12', 'APPLE_CERTIFICATE_PASSWORD', 'APPLE_TEAM_ID', 'APPLE_KEYCHAIN_PASSWORD', 'APPLE_SUPERVISOR_PROFILE'];

// A24: the Supervisor is a helper application bundle, so it can carry the Developer ID
// provisioning profile that authorises its restricted keychain-access-groups entitlement.
const SUPERVISOR_BUNDLE = 'Contents/Helpers/LaravelExecutiveAISupervisor.app';

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

/// The embedded Supervisor helper bundle, as the app build produces it (A24).
function fakeHelper(app) {
    const helper = join(app, ...SUPERVISOR_BUNDLE.split('/'));
    mkdirSync(join(helper, 'Contents', 'MacOS'), { recursive: true });
    writeFileSync(join(helper, 'Contents', 'Info.plist'), '<?xml version="1.0" encoding="UTF-8"?>\n<plist version="1.0"><dict><key>CFBundleIdentifier</key><string>se.stafe.executive-ai.supervisor</string></dict></plist>\n');
    writeFileSync(join(helper, 'Contents', 'MacOS', 'LaravelExecutiveAISupervisor'), '#!/bin/sh\n');

    return helper;
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
            /^\s+(APPLE_[A-Z0-9_]+|HAS_[A-Z0-9_]+): \$\{\{ secrets\.APPLE_(CERTIFICATE_P12|CERTIFICATE_PASSWORD|TEAM_ID|KEYCHAIN_PASSWORD|SUPERVISOR_PROFILE)( != '')? \}\}$/,
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
        // Every step that sees a secret value is gated on the signing mode. A step may add
        // further conditions (the runtime Team ID check also needs a bundled runtime), so the
        // mode condition has to be part of the `if:`, not necessarily all of it.
        const step = lines.slice(cursor, index + 1).join('\n');
        assert.match(step, /\n\s+if: [^\n]*steps\.mode\.outputs\.signed == 'true'/, `step at line ${cursor + 1} is gated on the signing mode`);
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

test('the bundle check requires the Supervisor helper bundle and the LaunchAgent plist that S1-07 embeds', () => {
    const locate = section(workflow, '- name: Locate the bundle', '- name: Sign the app and the Supervisor');
    const plists = readdirSync(join(root, 'packaging', 'manifest', 'launchd')).filter((name) => name.endsWith('.plist'));

    assert.equal(plists.length, 1, 'one LaunchAgent plist template');
    assert.ok(locate.includes(`${SUPERVISOR_BUNDLE}/Contents/MacOS/LaravelExecutiveAISupervisor`), 'the helper bundle executable');
    assert.ok(locate.includes(`${SUPERVISOR_BUNDLE}/Contents/Info.plist`), "the helper bundle's Info.plist");
    assert.ok(locate.includes(`Contents/Library/LaunchAgents/${plists[0]}`));
    assert.doesNotMatch(locate, /(?<!\.app\/)Contents\/MacOS\/LaravelExecutiveAISupervisor\b/, 'never the bare tool path next to the app executable (A24)');
});

test('the profile secret reaches only the signing step, and sign.sh embeds it in the helper bundle before signing', () => {
    const signStep = section(workflow, '- name: Sign the app and the Supervisor', '- name: Create the disk image');
    assert.match(signStep, /^\s+APPLE_SUPERVISOR_PROFILE: \$\{\{ secrets\.APPLE_SUPERVISOR_PROFILE \}\}$/m);

    // The only step that sees it: no other occurrence anywhere in the workflow.
    assert.equal((workflow.match(/secrets\.APPLE_SUPERVISOR_PROFILE/g) ?? []).length, 1);

    const embed = sign.indexOf('embed_supervisor_profile "$supervisor"');
    const signHelper = sign.indexOf('sign_one "$identity" "$supervisor"');
    assert.ok(embed > 0 && embed < signHelper, 'the profile is embedded before the helper is signed');
    assert.match(sign, /EMBEDDED_PROFILE_PATH="Contents\/embedded\.provisionprofile"/);
    assert.match(sign, /SUPERVISOR_BUNDLE_PATH="Contents\/Helpers\/\$SUPERVISOR_NAME\.app"/);

    // Fail closed: signing a helper with a restricted entitlement and no profile is what the lab
    // run proved macOS kills, so there is no escape hatch for it.
    assert.match(sign, /refusing to sign the Supervisor helper without APPLE_SUPERVISOR_PROFILE/);
    assert.match(sign, /security cms -D -i "\$profile"/, 'the decoded profile is validated as a CMS blob');
    assert.doesNotMatch(sign, /echo[^\n]*\$APPLE_SUPERVISOR_PROFILE/, 'the profile is never echoed');
    assert.doesNotMatch(sign, /printf[^\n]*\$APPLE_SUPERVISOR_PROFILE[^|]*$/m, 'the profile only ever goes into base64 --decode');
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

test('sign.sh: hardened runtime, secure timestamp, --force, never --deep when signing, the Supervisor helper bundle (own entitlements, Team ID prefix expanded) before the app', () => {
    assert.match(sign, /codesign --force --options runtime --timestamp "\$@" --sign "\$identity" "\$target"/);
    for (const line of sign.split('\n').filter((candidate) => /codesign\b/.test(candidate) && /--sign\b/.test(candidate))) {
        assert.doesNotMatch(line, /--deep/, `no --deep while signing: ${line.trim()}`);
    }
    assert.match(sign, /codesign --verify --deep --strict/);

    const supervisor = sign.indexOf('sign_one "$identity" "$supervisor"');
    const app = sign.indexOf('sign_one "$identity" "$app"');
    assert.ok(supervisor > 0 && app > supervisor, 'the Supervisor helper bundle is signed before the app bundle');
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
    assert.doesNotMatch(appText, /<key>com\.apple\.application-identifier<\/key>/, 'and no restricted entitlement at all, so it needs no profile');
    assert.match(supervisorText, /<key>keychain-access-groups<\/key>/);
    assert.ok(supervisorText.includes(`<string>$(AppIdentifierPrefix)${prefix}.secrets</string>`), 'the one access group, prefixed by the Team ID at signing time');

    // A24: both restricted entitlements mirror what the embedded Developer ID profile carries.
    assert.ok(supervisorText.includes(`<string>$(AppIdentifierPrefix)${prefix}.supervisor</string>`), 'the application identifier the profile authorises');
    assert.equal((supervisorText.match(/<key>/g) ?? []).length, 3, 'app-sandbox false + application-identifier + keychain-access-groups, nothing else');
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

    // A24: with an identity configured and a helper bundle in place, a missing or malformed
    // provisioning profile stops the run before codesign is ever called — a signed helper without
    // one is SIGKILLed by macOS over its restricted entitlement.
    fakeHelper(app);
    const identity = { APPLE_TEAM_ID: 'ABCDE12345', APPLE_SIGNING_IDENTITY: 'deadbeef' };

    const noProfile = run(signScript, ['app', app], identity);
    assert.equal(noProfile.status, 1, noProfile.stderr);
    assert.match(noProfile.stderr, /refusing to sign the Supervisor helper without APPLE_SUPERVISOR_PROFILE/);
    assert.match(noProfile.stderr, /A24/);

    const badProfile = run(signScript, ['app', app], { ...identity, APPLE_SUPERVISOR_PROFILE: 'bm90LWEtcHJvZmlsZQ==' });
    assert.equal(badProfile.status, 1, badProfile.stderr);
    assert.match(badProfile.stderr, /does not decode to a provisioning profile/);
    assert.doesNotMatch(badProfile.stderr, /bm90LWEtcHJvZmlsZQ==/, 'the value is not echoed back');
    assert.ok(! existsSync(join(app, ...SUPERVISOR_BUNDLE.split('/'), 'Contents', 'embedded.provisionprofile')), 'a rejected profile is not left in the bundle');

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
    // No Contents/Resources/runtime/ in this fake bundle, so the image must say so rather than
    // claim a runtime it does not carry.
    assert.match(readmeText, /NO PHP runtime package/);
    assert.match(readmeText, /PACKAGE_VERIFY/);
    assert.match(readmeText, /build-runtime workflow/);
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

test('the signing report has a runtime section: the package version, the binary verdict and the SHA256SUMS check', () => {
    // `report` shells out to codesign; it is macOS-only, like the signing it reports on.
    if (process.platform !== 'darwin') {
        return;
    }

    const base = mkdtempSync(join(tmpdir(), 'lxai-report-runtime-'));
    const app = fakeApp(base);

    const without = run(signScript, ['report', app, join(base, 'report.txt')]);
    assert.equal(without.status, 0, without.stderr);
    assert.match(readFileSync(join(base, 'report.txt'), 'utf8'), /^runtime: +no package in Contents\/Resources\/runtime/m);

    // A minimal, internally consistent package, as build-macos.yml unpacks it.
    const runtime = join(app, 'Contents', 'Resources', 'runtime');
    mkdirSync(runtime, { recursive: true });
    const binary = '#!/bin/sh\necho stub\n';
    writeFileSync(join(runtime, 'frankenphp'), binary);
    const digest = createHash('sha256').update(binary).digest('hex');
    writeFileSync(
        join(runtime, 'manifest.json'),
        `${JSON.stringify({ schema_version: 1, runtime_version: '0.1.0', executable: 'frankenphp', files: [{ path: 'frankenphp', sha256: digest, bytes: binary.length }] }, null, 4)}\n`,
    );
    writeFileSync(join(runtime, 'SHA256SUMS'), `${digest}  frankenphp\n`);

    const withRuntime = run(signScript, ['report', app, join(base, 'report2.txt')]);
    assert.equal(withRuntime.status, 0, withRuntime.stderr);
    const report = readFileSync(join(base, 'report2.txt'), 'utf8');

    assert.match(report, /^runtime: +package 0\.1\.0 present, frankenphp verdict unsigned$/m);
    assert.match(report, /## runtime package \(Contents\/Resources\/runtime\)/);
    assert.match(report, /## entitlements \(runtime binary\) — ADR-001 D2: exactly allow-jit/);
    assert.match(report, /## SHA256SUMS of the bundled package/);
    assert.match(report, /frankenphp: OK/);

    // An unsigned runtime in a build that claims to be signed must fail the report, not pass it.
    const expected = run(signScript, ['report', app, join(base, 'report3.txt')], { LXAI_EXPECT_SIGNED: '1' });
    assert.equal(expected.status, 1, expected.stderr);
    assert.match(expected.stderr, /the app is 'unsigned'|bundled runtime binary is/);

    rmSync(base, { recursive: true, force: true });
});

test('with a runtime package in the bundle, the image README names its version instead of the caveat', () => {
    const base = mkdtempSync(join(tmpdir(), 'lxai-dmg-runtime-'));
    const app = fakeApp(base);
    const out = join(base, 'out');

    mkdirSync(join(app, 'Contents', 'Resources', 'runtime'), { recursive: true });
    writeFileSync(
        join(app, 'Contents', 'Resources', 'runtime', 'manifest.json'),
        `${JSON.stringify({ schema_version: 1, runtime_version: '0.1.0', executable: 'frankenphp', files: [] }, null, 4)}\n`,
    );

    const result = run(dmgScript, ['--app', app, '--out-dir', out, '--stage-only', '--sha', 'abcdef1', '--version', '0.0.0', '--status', 'signed']);
    assert.equal(result.status, 0, result.stderr);

    const readmeText = readFileSync(join(out, 'dmg-staging', 'README.txt'), 'utf8');
    assert.match(readmeText, /contains the PHP runtime package 0\.1\.0/);
    assert.match(readmeText, /verifies every file against that manifest/);
    assert.doesNotMatch(readmeText, /NO PHP runtime package/);
    assert.match(readmeText, /No\nHomebrew, Docker or system PHP is used or required\./);

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
