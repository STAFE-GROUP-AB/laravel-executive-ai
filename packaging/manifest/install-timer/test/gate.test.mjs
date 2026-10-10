import assert from 'node:assert/strict';
import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';

import { aggregateRecords, evaluateGate } from '../install-timer.mjs';
import { dataset, runCli, tempDir, writeDataset } from './helpers.mjs';

const GATE_ARGS = ['--p50', '180000', '--p95', '300000', '--min-runs', '30'];

function writeBothProfiles(dir, options = {}) {
    writeDataset(dir, dataset({ count: 35, profile: 'mac-a', seed: 1, ...options }), 'mac-a');
    writeDataset(dir, dataset({ count: 35, profile: 'mac-b', seed: 2, ...options }), 'mac-b');
}

test('gate: a synthetic 35-run dataset per profile passes', () => {
    const dir = tempDir();
    writeBothProfiles(dir);

    const result = runCli(['gate', dir, ...GATE_ARGS, '--out', join(dir, 'gate.json')]);

    assert.equal(result.status, 0, result.stdout + result.stderr);
    assert.match(result.stdout, /PASS\s+mac-a: core-ready p50 \d+\.\ds < 180\.0s, p95 \d+\.\ds < 300\.0s over 35 runs/);
    assert.match(result.stdout, /PASS\s+mac-b:/);
    assert.match(result.stdout, /GATE PASS/);
    assert.match(result.stdout, /Async post-core steps \(network-conditional, NOT part of core-ready\)/);
    assert.match(result.stdout, /MODEL_DOWNLOAD/);

    const gate = JSON.parse(readFileSync(join(dir, 'gate.json'), 'utf8'));

    assert.equal(gate.passed, true);
    assert.equal(gate.results.length, 2);
    assert.ok(gate.results.every((r) => r.status === 'PASS' && r.n === 35));
});

test('gate: p95 at or above 300 s fails even when p50 is fine', () => {
    const dir = tempDir();
    writeDataset(dir, dataset({ count: 35, profile: 'mac-a', seed: 3, slowRuns: 3, slowTotalMs: 330_000 }), 'mac-a');
    writeDataset(dir, dataset({ count: 35, profile: 'mac-b', seed: 4 }), 'mac-b');

    const result = runCli(['gate', dir, ...GATE_ARGS]);

    assert.equal(result.status, 1);
    assert.match(result.stdout, /FAIL\s+mac-a: core-ready p50 \d+\.\ds \(target < 180\.0s\), p95 330\.0s \(target < 300\.0s\) over 35 run\(s\)/);
    assert.match(result.stdout, /PASS\s+mac-b:/);
    assert.match(result.stdout, /GATE FAIL/);
});

test('gate: p50 at or above 180 s fails', () => {
    const dir = tempDir();
    writeDataset(dir, dataset({ count: 35, profile: 'mac-a', seed: 5, minTotalMs: 181_000, maxTotalMs: 250_000 }), 'mac-a');

    const result = runCli(['gate', dir, ...GATE_ARGS, '--profiles', 'mac-a']);

    assert.equal(result.status, 1);
    assert.match(result.stdout, /FAIL\s+mac-a/);
});

test('gate: boundary — exactly 180 s p50 or exactly 300 s p95 is a failure (§12.2 says strictly less)', () => {
    const exact = dataset({ count: 31, profile: 'mac-a', seed: 6, minTotalMs: 180_000, maxTotalMs: 180_000 });
    const outcome = evaluateGate(exact, { profiles: ['mac-a'] });

    assert.equal(outcome.passed, false);
    assert.equal(outcome.results[0].p50_ms, 180_000);
    assert.equal(outcome.results[0].status, 'FAIL');

    const okay = dataset({ count: 31, profile: 'mac-a', seed: 7, minTotalMs: 179_999, maxTotalMs: 179_999 });

    assert.equal(evaluateGate(okay, { profiles: ['mac-a'] }).passed, true);
});

test('gate: insufficient runs fail without --allow-insufficient and are reported with it', () => {
    const dir = tempDir();
    writeDataset(dir, dataset({ count: 12, profile: 'mac-a', seed: 8 }), 'mac-a');

    const strict = runCli(['gate', dir, ...GATE_ARGS]);

    assert.equal(strict.status, 1);
    assert.match(strict.stdout, /INSUFFICIENT\s+mac-a: 12\/30 runs/);
    assert.match(strict.stdout, /NO_DATA\s+mac-b: no eligible runs/);
    assert.match(strict.stdout, /GATE FAIL: insufficient runs/);

    const interim = runCli(['gate', dir, ...GATE_ARGS, '--allow-insufficient']);

    assert.equal(interim.status, 0, interim.stdout);
    assert.match(interim.stdout, /GATE NOT PROVEN: 2 profile\(s\) below 30 runs/);
    assert.doesNotMatch(interim.stdout, /GATE PASS/);

    const interimOneProfile = runCli(['gate', dir, ...GATE_ARGS, '--allow-insufficient', '--profiles', 'mac-a']);

    assert.equal(interimOneProfile.status, 0);
});

test('gate: --allow-insufficient never hides a threshold breach', () => {
    const dir = tempDir();
    writeDataset(dir, dataset({ count: 10, profile: 'mac-a', seed: 9, slowRuns: 2, slowTotalMs: 400_000 }), 'mac-a');

    const result = runCli(['gate', dir, ...GATE_ARGS, '--allow-insufficient', '--profiles', 'mac-a']);

    assert.equal(result.status, 1);
    assert.match(result.stdout, /FAIL\s+mac-a: .* — and only 10\/30 runs/);
});

test('gate: synthetic, non-clean, non-fresh and uncached runs are excluded from the gate', () => {
    const dir = tempDir();
    writeDataset(dir, dataset({ count: 35, profile: 'mac-a', seed: 10, synthetic: true }), 'synthetic');
    writeDataset(dir, dataset({ count: 35, profile: 'mac-a', seed: 11, cleanMachine: false }), 'dirty');
    writeDataset(dir, dataset({ count: 35, profile: 'mac-a', seed: 12, scenario: 'upgrade' }), 'upgrade');
    writeDataset(dir, dataset({ count: 35, profile: 'mac-a', seed: 13, runtimeCached: false }), 'uncached');

    const result = runCli(['gate', dir, ...GATE_ARGS, '--profiles', 'mac-a']);

    assert.equal(result.status, 1);
    assert.match(result.stdout, /records: 140 loaded, 0 eligible \(excluded: 35 synthetic, 35 not clean machine, 35 not fresh_install, 35 other runtime_cached\)/);
    assert.match(result.stdout, /NO_DATA\s+mac-a/);

    const uncachedGate = runCli(['gate', dir, ...GATE_ARGS, '--profiles', 'mac-a', '--runtime-cached', 'no']);

    assert.equal(uncachedGate.status, 0, uncachedGate.stdout);
    assert.match(uncachedGate.stdout, /PASS\s+mac-a: .* over 35 runs/);
});

test('gate: async post-core time never affects the gate, however large', () => {
    const fast = dataset({ count: 35, profile: 'mac-a', seed: 14, withAsync: false });
    const slowDownloads = dataset({ count: 35, profile: 'mac-a', seed: 14, withAsync: true });

    const a = evaluateGate(fast, { profiles: ['mac-a'] });
    const b = evaluateGate(slowDownloads, { profiles: ['mac-a'] });

    assert.equal(a.passed, true);
    assert.equal(b.passed, true);
    assert.equal(a.results[0].p50_ms, b.results[0].p50_ms);
    assert.equal(a.results[0].p95_ms, b.results[0].p95_ms);

    const group = aggregateRecords(slowDownloads)[0];

    assert.ok(group.async_post_core.steps.MODEL_DOWNLOAD.completed_duration_ms.p50 > group.core_ready_ms.max, 'downloads in this dataset are longer than any core-ready time');
    assert.equal(group.async_post_core.counted_in_core_ready_ms, false);
    assert.equal(group.async_post_core.network_conditional, true);
});

test('gate and aggregate: an invalid record fails loudly', () => {
    const dir = tempDir();
    writeDataset(dir, dataset({ count: 31, profile: 'mac-a', seed: 15 }), 'mac-a');

    const tampered = JSON.parse(readFileSync(join(dir, readFileSyncList(dir)[0]), 'utf8'));
    tampered.core_ready_ms = 1;
    writeFileSync(join(dir, 'tampered.json'), JSON.stringify(tampered));

    const gate = runCli(['gate', dir, ...GATE_ARGS, '--profiles', 'mac-a']);

    assert.equal(gate.status, 1);
    assert.match(gate.stderr, /INVALID .*tampered\.json/);
    assert.match(gate.stderr, /core_ready_ms \(1\) must equal/);
    assert.match(gate.stderr, /GATE FAIL: 1 record\(s\) do not validate/);

    const aggregate = runCli(['aggregate', dir, '--summary', join(dir, 'summary.json')]);

    assert.equal(aggregate.status, 1);
    assert.match(aggregate.stderr, /INVALID/);
    assert.ok(existsSync(join(dir, 'summary.json')), 'the valid records are still summarised');
});

test('aggregate: groups per profile and runtime_cached, writes summary.json with separate async stats', () => {
    const dir = tempDir();
    writeDataset(dir, dataset({ count: 5, profile: 'mac-a', seed: 16 }), 'a-cached');
    writeDataset(dir, dataset({ count: 4, profile: 'mac-a', seed: 17, runtimeCached: false }), 'a-uncached');
    writeDataset(dir, dataset({ count: 3, profile: 'other', seed: 18, cleanMachine: false }), 'dev');
    mkdirSync(join(dir, 'example'));
    writeDataset(join(dir, 'example'), dataset({ count: 2, profile: 'mac-b', seed: 19, synthetic: true }), 'synthetic');

    const summaryPath = join(dir, 'out', 'summary.json');
    const result = runCli(['aggregate', dir, '--summary', summaryPath]);

    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /== profile mac-a · runtime cached · 5 run\(s\) \(5 on a clean machine\) ==/);
    assert.match(result.stdout, /== profile mac-a · runtime not cached · 4 run\(s\)/);
    assert.match(result.stdout, /== profile other · runtime cached · 3 run\(s\) \(0 on a clean machine\)/);
    assert.doesNotMatch(result.stdout, /profile mac-b/);
    assert.match(result.stdout, /excluded 2 synthetic record\(s\)/);
    assert.match(result.stdout, /core_ready_ms\s+5\s+\d+\.\ds/);
    assert.match(result.stdout, /human_interaction_ms/);

    const summary = JSON.parse(readFileSync(summaryPath, 'utf8'));

    assert.equal(summary.percentile_method, 'nearest-rank');
    assert.equal(summary.inputs.records_used, 12);
    assert.equal(summary.inputs.excluded_synthetic, 2);
    assert.equal(summary.groups.length, 3);

    const cached = summary.groups.find((g) => g.profile === 'mac-a' && g.runtime_cached === true);

    assert.equal(cached.n, 5);
    assert.ok(['p50', 'p95', 'min', 'max', 'mean', 'n'].every((k) => k in cached.core_ready_ms));
    assert.ok(cached.phases.INSTALL_CORE.duration_ms.p95 > 0);
    assert.equal(cached.async_post_core.counted_in_core_ready_ms, false);
    assert.equal(cached.async_post_core.steps.MODEL_DOWNLOAD.runs_with_step, 5);
    assert.equal(cached.run_ids.length, 5);

    const included = runCli(['aggregate', dir, '--include-synthetic', '--summary', join(dir, 'out', 'all.json')]);

    assert.equal(included.status, 0);
    assert.match(included.stdout, /profile mac-b/);
});

test('cli: usage and unknown command', () => {
    assert.equal(runCli([]).status, 2);
    assert.equal(runCli(['--help']).status, 0);
    assert.match(runCli(['--help']).stdout, /install-timer record/);

    const unknown = runCli(['bogus']);

    assert.equal(unknown.status, 2);
    assert.match(unknown.stderr, /unknown command "bogus"/);

    const badFlag = runCli(['gate', '.', '--p50', 'three']);

    assert.equal(badFlag.status, 2);
    assert.match(badFlag.stderr, /--p50 must be a non-negative integer/);
});

function readFileSyncList(dir) {
    return readdirSync(dir).filter((f) => f.endsWith('.json')).sort();
}
