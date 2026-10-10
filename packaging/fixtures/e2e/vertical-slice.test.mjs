/**
 * Shape tests for the vertical-slice driver and its report (S1-15, blueprint
 * §16 step 8). Dependency-free node:test; runs in the `fixtures` CI lane,
 * which has no PHP, so it never runs the slice itself: it holds the driver's
 * step list to the blueprint, the validator to its contract, and — when a
 * report exists from a local or CI run — that report to the validator.
 *
 *   node --test 'packaging/fixtures/e2e/**\/*.test.mjs'
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import {
    DEFAULT_REPORT,
    ORACLES,
    REPORT_SCHEMA_VERSION,
    STEPS,
    SURFACES,
    blueprintSliceSteps,
    readReport,
    repoRoot,
    summaryMarkdown,
    validateReport,
} from './vertical-slice.mjs';

const HASH = `sha256:${'a'.repeat(64)}`;

function greenReport() {
    const brief = { document_id: '01M4FJ7GY0PZH6YA3D9574XN5K', version: 1, generated_at: '2026-10-09T05:30:00+02:00', content_hash: HASH, source: 'packaging/fixtures/briefs/brief-canonical.json' };

    return {
        schema_version: REPORT_SCHEMA_VERSION,
        slice: 'blueprint §16 step 8',
        run_at: '2026-10-10T20:00:00+00:00',
        duration_ms: 300,
        menu_bar_oracle: 'committed snapshot',
        passed: true,
        failure: null,
        steps: STEPS.map((step) => ({ ...step, status: 'passed', duration_ms: 10, evidence: { ok: true } })),
        brief,
        surfaces: {
            pwa: { version: 1, content_hash: HASH },
            menu_bar: { version: 1, content_hash: HASH, oracle: 'committed snapshot' },
            email: { version: 1, content_hash: HASH },
        },
        assurances: {
            outbound_http: 'Http::preventStrayRequests() — any request throws; none recorded',
            ai_ready: false,
            ai_state: 'not_configured',
            ai_provider: null,
            failing_transport_attempts: 0,
            external_accounts: 0,
        },
    };
}

test('the driver walks exactly the six steps of blueprint §16 step 8, in the blueprint order', () => {
    const blueprint = blueprintSliceSteps();

    assert.equal(blueprint.length, 6);
    assert.deepEqual(STEPS.map((step) => step.title), blueprint);
    assert.deepEqual(STEPS.map((step) => step.index), [1, 2, 3, 4, 5, 6]);
    assert.deepEqual(STEPS.map((step) => step.id), ['install', 'create_owner', 'open_pwa', 'generate_seeded_brief', 'show_in_menu_bar', 'preview_email']);
});

test('the Pest side records the same step ids and titles', () => {
    const source = `${repoRoot}/tests/Support/VerticalSliceReport.php`;

    assert.ok(existsSync(source), 'tests/Support/VerticalSliceReport.php is missing');

    const php = readFileSync(source, 'utf8');

    for (const step of STEPS) {
        assert.ok(php.includes(`'${step.id}' => '${step.title}'`), `VerticalSliceReport::STEPS lacks ${step.id} => ${step.title}`);
    }

    assert.ok(php.includes(`SCHEMA_VERSION = '${REPORT_SCHEMA_VERSION}'`), 'report schema versions differ between PHP and the driver');
    assert.ok(php.includes(`DEFAULT_PATH = '${DEFAULT_REPORT}'`), 'default report paths differ between PHP and the driver');
});

test('a green report validates', () => {
    assert.deepEqual(validateReport(greenReport()), []);
});

test('the three surfaces must carry one version and one content_hash', () => {
    const versionDrift = greenReport();
    versionDrift.surfaces.email.version = 2;

    const hashDrift = greenReport();
    hashDrift.surfaces.menu_bar.content_hash = `sha256:${'b'.repeat(64)}`;

    assert.match(validateReport(versionDrift).join('\n'), /surfaces\.email\.version 2 differs/);
    assert.match(validateReport(hashDrift).join('\n'), /surfaces\.menu_bar\.content_hash differs/);

    for (const surface of SURFACES) {
        const missing = greenReport();
        delete missing.surfaces[surface];

        assert.match(validateReport(missing).join('\n'), new RegExp(`surfaces\\.${surface} is missing`));
    }
});

test('a missing, reordered or failed step is not green', () => {
    const missing = greenReport();
    missing.steps.pop();

    const reordered = greenReport();
    [reordered.steps[0], reordered.steps[1]] = [reordered.steps[1], reordered.steps[0]];

    const failed = greenReport();
    failed.steps[4] = { ...failed.steps[4], status: 'failed', failure: 'menu bar export differs' };

    assert.match(validateReport(missing).join('\n'), /step 6 \(preview_email\) is missing/);
    assert.match(validateReport(reordered).join('\n'), /step 1 must be 1\/install/);
    assert.match(validateReport(failed).join('\n'), /passed is true although not every step passed/);
});

test('§13.1 assurances are required for a green report', () => {
    const ai = greenReport();
    ai.assurances.ai_ready = true;

    const mail = greenReport();
    mail.assurances.failing_transport_attempts = 1;

    const http = greenReport();
    http.assurances.outbound_http = 'not checked';

    assert.match(validateReport(ai).join('\n'), /ai_ready must be false/);
    assert.match(validateReport(mail).join('\n'), /failing_transport_attempts must be 0/);
    assert.match(validateReport(http).join('\n'), /preventStrayRequests/);
});

test('the menu bar oracle is named and consistent', () => {
    const unknown = greenReport();
    unknown.menu_bar_oracle = 'guess';

    const inconsistent = greenReport();
    inconsistent.surfaces.menu_bar.oracle = ORACLES[1];

    assert.match(validateReport(unknown).join('\n'), /menu_bar_oracle must be one of/);
    assert.match(validateReport(inconsistent).join('\n'), /surfaces\.menu_bar\.oracle must equal/);
});

test('a failed report is reported as such without demanding the identity it could not produce', () => {
    const report = greenReport();
    report.passed = false;
    report.failure = 'show_in_menu_bar: export missing';
    report.steps = report.steps.slice(0, 5);
    report.steps[4] = { ...report.steps[4], status: 'failed', failure: 'export missing' };
    report.brief = null;
    report.surfaces = {};

    const problems = validateReport(report);

    assert.match(problems.join('\n'), /step 6 \(preview_email\) is missing/);
    assert.doesNotMatch(problems.join('\n'), /surfaces\.pwa is missing/);
    assert.match(summaryMarkdown(report, problems), /RED/);
    assert.match(summaryMarkdown(report, problems), /\| 5 \| Show same brief in menu bar \| \*\*failed\*\* — export missing/);
});

test('the summary names every step, the triple and the oracle', () => {
    const summary = summaryMarkdown(greenReport());

    assert.match(summary, /^## Vertical slice \(§16 step 8\) — green/);

    for (const step of STEPS) {
        assert.ok(summary.includes(`| ${step.index} | ${step.title} | passed |`), `summary lacks step ${step.index}`);
    }

    assert.match(summary, /\| menu bar \(committed snapshot\) \| 1 \| `sha256:a{64}` \|/);
    assert.match(summary, /ai_ready=false/);
});

test('the report of the last local or CI run, when present, is green', (t) => {
    const path = resolve(repoRoot, DEFAULT_REPORT);

    if (! existsSync(path)) {
        t.skip(`no report at ${DEFAULT_REPORT}; run the slice first (php artisan test --testsuite=E2E --filter=VerticalSlice)`);

        return;
    }

    const report = readReport(path);
    const problems = validateReport(report);

    assert.deepEqual(problems, [], `${DEFAULT_REPORT} is not green:\n- ${problems.join('\n- ')}`);
});
