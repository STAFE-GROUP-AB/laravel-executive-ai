#!/usr/bin/env node
/**
 * Vertical-slice driver (S1-15, blueprint §16 step 8, §16 step 9).
 *
 * Runs the Pest slice `tests/E2E/VerticalSliceTest.php` against a freshly
 * installed core, then validates the report it writes
 * (`build/e2e/vertical-slice.json`) and prints the one-page summary the CI job
 * shows. There is no HTTP server and no browser: the slice drives the real
 * HTTP kernel in-process (ADR-001 forbids `artisan serve` in the product, and
 * §14 only *permits* Playwright, it does not require it).
 *
 *   node packaging/fixtures/e2e/vertical-slice.mjs            run the slice, validate, summarise
 *   node packaging/fixtures/e2e/vertical-slice.mjs --check    validate and summarise an existing report only
 *   node packaging/fixtures/e2e/vertical-slice.mjs --swift    export the menu bar surface with xcodebuild first
 *                                                             (macOS; sets LXAI_SWIFT_PARITY_EXPORT_DIR=build/parity)
 *   --summary <file>   append the Markdown summary to <file> (CI passes $GITHUB_STEP_SUMMARY)
 *   --report <file>    report path (default build/e2e/vertical-slice.json)
 *
 * Pure Node, no dependencies. The exported functions are what
 * vertical-slice.test.mjs checks: the step list is the blueprint's, verbatim,
 * and a report is only "green" when every step passed and the three surfaces
 * carry one `version` and one `content_hash`.
 */

import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
export const repoRoot = resolve(here, '../../..');

export const REPORT_SCHEMA_VERSION = '1.0.0';
export const DEFAULT_REPORT = 'build/e2e/vertical-slice.json';
export const PEST_TEST = 'tests/E2E/VerticalSliceTest.php';
export const SWIFT_EXPORT_DIR = 'build/parity';
export const SWIFT_PROJECT = 'apps/macos/LaravelExecutiveAI.xcodeproj';
export const SWIFT_TEST = 'LaravelExecutiveAITests/BriefParitySnapshotTests';

/** Blueprint §16 step 8, in order. `id` is what the Pest report records; `title` is the blueprint's wording. */
export const STEPS = Object.freeze([
    { index: 1, id: 'install', title: 'Install' },
    { index: 2, id: 'create_owner', title: 'Create owner' },
    { index: 3, id: 'open_pwa', title: 'Open PWA' },
    { index: 4, id: 'generate_seeded_brief', title: 'Generate seeded brief' },
    { index: 5, id: 'show_in_menu_bar', title: 'Show same brief in menu bar' },
    { index: 6, id: 'preview_email', title: 'Preview exact email' },
]);

export const SURFACES = Object.freeze(['pwa', 'menu_bar', 'email']);

export const ORACLES = Object.freeze(['committed snapshot', 'swift export']);

const CONTENT_HASH = /^sha256:[0-9a-f]{64}$/;

/**
 * Reads §16 step 8 out of the blueprint so the step list above can be held to
 * the source rather than to a copy of it.
 */
export function blueprintSliceSteps(blueprintPath = resolve(repoRoot, 'Mission/Laravel_Executive_AI_Engineering_Blueprint.md')) {
    const text = readFileSync(blueprintPath, 'utf8');
    const line = text.split('\n').find((candidate) => /^8\. .*vertical slice/i.test(candidate));

    if (! line) {
        throw new Error('blueprint §16 step 8 not found');
    }

    const match = line.match(/\*([^*]+)\*/);

    if (! match) {
        throw new Error('blueprint §16 step 8 carries no italic step list');
    }

    return match[1].split('→').map((step) => step.trim());
}

/**
 * Validates a report. Returns the list of problems; an empty list means the
 * report is well-formed *and* green.
 */
export function validateReport(report) {
    const problems = [];
    const problem = (message) => problems.push(message);

    if (! report || typeof report !== 'object' || Array.isArray(report)) {
        return ['report is not an object'];
    }

    if (report.schema_version !== REPORT_SCHEMA_VERSION) {
        problem(`schema_version must be ${REPORT_SCHEMA_VERSION}, got ${JSON.stringify(report.schema_version)}`);
    }

    if (report.slice !== 'blueprint §16 step 8') {
        problem(`slice must name blueprint §16 step 8, got ${JSON.stringify(report.slice)}`);
    }

    if (typeof report.run_at !== 'string' || Number.isNaN(Date.parse(report.run_at))) {
        problem('run_at must be an RFC 3339 timestamp');
    }

    if (! Number.isInteger(report.duration_ms) || report.duration_ms < 0) {
        problem('duration_ms must be a non-negative integer');
    }

    if (! ORACLES.includes(report.menu_bar_oracle)) {
        problem(`menu_bar_oracle must be one of ${ORACLES.join(' | ')}, got ${JSON.stringify(report.menu_bar_oracle)}`);
    }

    if (typeof report.passed !== 'boolean') {
        problem('passed must be a boolean');
    }

    if (! Array.isArray(report.steps)) {
        problem('steps must be an array');
    } else {
        STEPS.forEach((expected, position) => {
            const step = report.steps[position];

            if (! step) {
                problem(`step ${expected.index} (${expected.id}) is missing`);

                return;
            }

            if (step.index !== expected.index || step.id !== expected.id || step.title !== expected.title) {
                problem(`step ${position + 1} must be ${expected.index}/${expected.id}/"${expected.title}", got ${step.index}/${step.id}/"${step.title}"`);
            }

            if (! ['passed', 'failed'].includes(step.status)) {
                problem(`step ${expected.id}: status must be passed or failed`);
            }

            if (! Number.isInteger(step.duration_ms) || step.duration_ms < 0) {
                problem(`step ${expected.id}: duration_ms must be a non-negative integer`);
            }

            if (! step.evidence || typeof step.evidence !== 'object' || Array.isArray(step.evidence)) {
                problem(`step ${expected.id}: evidence must be an object`);
            }

            if (step.status === 'failed' && typeof step.failure !== 'string') {
                problem(`step ${expected.id}: a failed step must say why`);
            }
        });

        if (report.steps.length > STEPS.length) {
            problem(`${report.steps.length} steps recorded; the slice has exactly ${STEPS.length}`);
        }
    }

    const allPassed = Array.isArray(report.steps) && report.steps.length === STEPS.length && report.steps.every((step) => step.status === 'passed');

    if (report.passed === true && ! allPassed) {
        problem('passed is true although not every step passed');
    }

    if (report.passed === true && report.failure !== null) {
        problem('a passed report must carry failure: null');
    }

    if (report.passed !== true) {
        return problems;
    }

    // A green report must prove the §16 step 8 identity: one version, one content_hash.
    if (! report.brief || typeof report.brief !== 'object') {
        problem('brief is missing');
    } else {
        if (! Number.isInteger(report.brief.version) || report.brief.version < 1) {
            problem('brief.version must be a positive integer');
        }

        if (! CONTENT_HASH.test(String(report.brief.content_hash))) {
            problem('brief.content_hash must be sha256:<64 hex>');
        }

        if (typeof report.brief.document_id !== 'string' || report.brief.document_id === '') {
            problem('brief.document_id must be a non-empty string');
        }

        if (typeof report.brief.source !== 'string' || ! report.brief.source.startsWith('packaging/fixtures/briefs/')) {
            problem('brief.source must name the single JSON source under packaging/fixtures/briefs/');
        }
    }

    if (! report.surfaces || typeof report.surfaces !== 'object') {
        problem('surfaces is missing');
    } else {
        for (const surface of SURFACES) {
            const entry = report.surfaces[surface];

            if (! entry) {
                problem(`surfaces.${surface} is missing`);

                continue;
            }

            if (report.brief && entry.version !== report.brief.version) {
                problem(`surfaces.${surface}.version ${entry.version} differs from brief.version ${report.brief.version}`);
            }

            if (report.brief && entry.content_hash !== report.brief.content_hash) {
                problem(`surfaces.${surface}.content_hash differs from brief.content_hash`);
            }
        }

        if (report.surfaces.menu_bar && report.surfaces.menu_bar.oracle !== report.menu_bar_oracle) {
            problem('surfaces.menu_bar.oracle must equal menu_bar_oracle');
        }
    }

    const assurances = report.assurances ?? {};

    if (assurances.ai_ready !== false) {
        problem('assurances.ai_ready must be false (§13.1: no model call)');
    }

    if (assurances.ai_provider !== null) {
        problem('assurances.ai_provider must be null');
    }

    if (assurances.failing_transport_attempts !== 0) {
        problem('assurances.failing_transport_attempts must be 0 (nothing was sent)');
    }

    if (assurances.external_accounts !== 0) {
        problem('assurances.external_accounts must be 0 (§13.1: no third-party account)');
    }

    if (typeof assurances.outbound_http !== 'string' || ! assurances.outbound_http.includes('preventStrayRequests')) {
        problem('assurances.outbound_http must record Http::preventStrayRequests()');
    }

    return problems;
}

/** The one-page Markdown summary the CI job prints. */
export function summaryMarkdown(report, problems = validateReport(report)) {
    const ok = problems.length === 0;
    const lines = [];

    lines.push(`## Vertical slice (§16 step 8) — ${ok ? 'green' : 'RED'}`);
    lines.push('');
    lines.push(`Menu bar oracle: **${report.menu_bar_oracle ?? 'unknown'}** · run at ${report.run_at ?? '?'} · ${report.duration_ms ?? '?'} ms`);
    lines.push('');
    lines.push('| # | Step | Status | ms |');
    lines.push('|---|---|---|---|');

    for (const expected of STEPS) {
        const step = (report.steps ?? []).find((candidate) => candidate.id === expected.id);
        const status = step ? (step.status === 'passed' ? 'passed' : `**failed** — ${step.failure ?? ''}`) : 'not run';

        lines.push(`| ${expected.index} | ${expected.title} | ${status} | ${step?.duration_ms ?? ''} |`);
    }

    lines.push('');

    if (report.brief) {
        lines.push(`Brief \`${report.brief.document_id}\` from \`${report.brief.source}\` (generated ${report.brief.generated_at}):`);
        lines.push('');
        lines.push('| Surface | version | content_hash |');
        lines.push('|---|---|---|');

        for (const surface of SURFACES) {
            const entry = report.surfaces?.[surface];
            const label = surface === 'menu_bar' && entry?.oracle ? `menu bar (${entry.oracle})` : surface.replace('_', ' ');

            lines.push(`| ${label} | ${entry?.version ?? '—'} | \`${entry?.content_hash ?? '—'}\` |`);
        }

        lines.push('');
    }

    const assurances = report.assurances ?? {};

    lines.push(`§13.1: ai_ready=${JSON.stringify(assurances.ai_ready)}, ai_provider=${JSON.stringify(assurances.ai_provider)}, outbound HTTP: ${assurances.outbound_http ?? '?'}, failing-transport attempts: ${assurances.failing_transport_attempts ?? '?'}, external accounts: ${assurances.external_accounts ?? '?'}.`);

    if (! ok) {
        lines.push('');
        lines.push('Problems:');

        for (const problem of problems) {
            lines.push(`- ${problem}`);
        }
    }

    lines.push('');

    return lines.join('\n');
}

export function readReport(path) {
    return JSON.parse(readFileSync(path, 'utf8'));
}

function parseArgs(argv) {
    const options = { check: false, swift: false, summary: null, report: resolve(repoRoot, DEFAULT_REPORT) };

    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i];

        if (arg === '--check') {
            options.check = true;
        } else if (arg === '--swift') {
            options.swift = true;
        } else if (arg === '--summary') {
            options.summary = resolve(argv[++i] ?? '');
        } else if (arg === '--report') {
            options.report = resolve(argv[++i] ?? '');
        } else {
            throw new Error(`unknown argument ${arg}`);
        }
    }

    return options;
}

function run(command, args, env) {
    console.log(`$ ${[command, ...args].join(' ')}`);

    const result = spawnSync(command, args, { cwd: repoRoot, stdio: 'inherit', env: { ...process.env, ...env } });

    if (result.error) {
        throw result.error;
    }

    return result.status ?? 1;
}

function exportMenuBarSurface() {
    if (process.platform !== 'darwin') {
        throw new Error('--swift needs macOS and Xcode');
    }

    const status = run('xcodebuild', [
        '-project', SWIFT_PROJECT,
        '-scheme', 'LaravelExecutiveAI',
        '-destination', 'platform=macOS',
        `-only-testing:${SWIFT_TEST}`,
        'CODE_SIGN_IDENTITY=',
        'CODE_SIGNING_REQUIRED=NO',
        'CODE_SIGNING_ALLOWED=NO',
        'test',
    ], {});

    if (status !== 0) {
        throw new Error(`xcodebuild exited with ${status}`);
    }
}

function main(argv) {
    const options = parseArgs(argv);
    const env = { LXAI_SLICE_REPORT: options.report };
    let pestStatus = null;

    if (! options.check) {
        if (options.swift) {
            exportMenuBarSurface();
            env.LXAI_SWIFT_PARITY_EXPORT_DIR = resolve(repoRoot, SWIFT_EXPORT_DIR);
        }

        const pest = resolve(repoRoot, 'vendor/bin/pest');

        if (! existsSync(pest)) {
            throw new Error('vendor/bin/pest is missing; run composer install first');
        }

        mkdirSync(dirname(options.report), { recursive: true });
        pestStatus = run('php', [pest, PEST_TEST, '--ci'], env);
    }

    if (! existsSync(options.report)) {
        throw new Error(`no report at ${options.report}`);
    }

    const report = readReport(options.report);
    const problems = validateReport(report);

    if (pestStatus !== null) {
        report.driver = { pest_exit_code: pestStatus, node: process.version, platform: process.platform, swift_export: Boolean(env.LXAI_SWIFT_PARITY_EXPORT_DIR) };
        writeFileSync(options.report, `${JSON.stringify(report, null, 2)}\n`);
    }

    const summary = summaryMarkdown(report, problems);

    console.log(summary);

    if (options.summary) {
        writeFileSync(options.summary, summary, { flag: 'a' });
    }

    if (pestStatus !== null && pestStatus !== 0) {
        console.error(`Pest exited with ${pestStatus}`);

        return 1;
    }

    if (problems.length > 0) {
        console.error(`report at ${options.report} is not green:\n- ${problems.join('\n- ')}`);

        return 1;
    }

    return 0;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    try {
        process.exitCode = main(process.argv.slice(2));
    } catch (error) {
        console.error(error.message);
        process.exitCode = 1;
    }
}
