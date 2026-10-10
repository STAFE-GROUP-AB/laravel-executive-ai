// Shared helpers for the install-timer tests: deterministic synthetic event streams and datasets.
// Everything here is synthetic by construction; the records it writes are only ever used in a
// temporary directory and are never committed.

import { spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { TIMED_STATES, buildRecord } from '../install-timer.mjs';

export const CLI = join(dirname(fileURLToPath(import.meta.url)), '..', 'install-timer.mjs');

export const MACHINE = { chip: 'Apple M4 Max', memory_gb: 36, macos_version: '26.6.2' };

const BASE = Date.parse('2026-10-10T09:00:00.000Z');

const iso = (ms) => new Date(ms).toISOString();

/**
 * Builds an event stream from per-state durations (ms, in §12.1 order), human prompt intervals
 * (`{state, offsetMs, durationMs}` relative to the state's start) and async steps
 * (`{name, offsetMs, durationMs, outcome}` relative to READY; negative offset = before READY).
 */
export function eventsFor({ durations, human = [], async = [], dmgOpenMs = null, base = BASE }) {
    if (durations.length !== TIMED_STATES.length) {
        throw new Error(`durations needs ${TIMED_STATES.length} entries`);
    }

    const events = [];
    const startOf = {};
    let cursor = base;

    if (dmgOpenMs !== null) {
        events.push({ state: 'DMG_OPEN', at: iso(base - dmgOpenMs) });
    }

    TIMED_STATES.forEach((state, index) => {
        startOf[state] = cursor;
        events.push({ state, at: iso(cursor) });
        cursor += durations[index];
    });

    const readyAt = cursor;
    events.push({ state: 'READY', at: iso(readyAt) });

    for (const prompt of human) {
        const start = startOf[prompt.state] + prompt.offsetMs;
        events.push({ state: 'human_interaction_start', at: iso(start) });
        events.push({ state: 'human_interaction_end', at: iso(start + prompt.durationMs) });
    }

    for (const step of async) {
        const start = readyAt + step.offsetMs;
        events.push({ state: step.name, marker: 'start', at: iso(start) });

        if (step.durationMs !== null) {
            events.push({ state: step.name, marker: 'end', outcome: step.outcome ?? 'completed', at: iso(start + step.durationMs) });
        }
    }

    return events;
}

export const TYPICAL_DURATIONS = [4_000, 2_500, 5_500, 45_000, 30_000, 12_000, 7_000, 9_000];

export const TYPICAL_HUMAN = [
    { state: 'WELCOME', offsetMs: 0, durationMs: 4_000 },
    { state: 'CREATE_OWNER', offsetMs: 500, durationMs: 28_000 },
    { state: 'SELECT_AI', offsetMs: 300, durationMs: 10_000 },
];

// mulberry32: tiny seeded PRNG so datasets are reproducible run to run.
export function rng(seed) {
    let a = seed >>> 0;

    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * Generates `count` records for a profile with core-ready times scaled so the total lands in
 * [minTotalMs, maxTotalMs]; `slowRuns` of them are forced to `slowTotalMs`.
 */
export function dataset({ count, profile, runtimeCached = true, cleanMachine = true, minTotalMs = 110_000, maxTotalMs = 170_000, slowRuns = 0, slowTotalMs = 330_000, seed = 42, synthetic = false, scenario = 'fresh_install', withAsync = true }) {
    const random = rng(seed);
    const asyncRandom = rng(seed + 1_000);
    const records = [];
    const baseTotal = TYPICAL_DURATIONS.reduce((a, b) => a + b, 0);

    for (let i = 0; i < count; i += 1) {
        const target = i < slowRuns ? slowTotalMs : minTotalMs + Math.round(random() * (maxTotalMs - minTotalMs));
        const scale = target / baseTotal;
        const durations = TYPICAL_DURATIONS.map((d) => Math.round(d * scale));
        durations[durations.length - 1] += target - durations.reduce((a, b) => a + b, 0);
        const human = TYPICAL_HUMAN.map((h) => ({ ...h, durationMs: Math.round(h.durationMs * Math.min(scale, 1)) }));
        const async = withAsync ? [
            { name: 'MODEL_DOWNLOAD', offsetMs: 5_000, durationMs: 300_000 + Math.round(asyncRandom() * 600_000) },
            { name: 'OAUTH_GOOGLE', offsetMs: 10_000, durationMs: 20_000 + Math.round(asyncRandom() * 60_000) },
        ] : [];

        records.push(buildRecord({
            events: eventsFor({ durations, human, async, base: BASE + i * 3_600_000 }),
            profile,
            runtimeCached,
            cleanMachine,
            scenario,
            synthetic,
            machine: MACHINE,
            now: new Date(BASE + i * 3_600_000 + 1_000_000),
        }));
    }

    return records;
}

export function writeDataset(dir, records, prefix = 'run') {
    records.forEach((record, index) => {
        writeFileSync(join(dir, `${prefix}-${String(index).padStart(3, '0')}-${record.run_id}.json`), `${JSON.stringify(record, null, 2)}\n`);
    });
}

export function tempDir() {
    return mkdtempSync(join(tmpdir(), 'install-timer-test-'));
}

export function runCli(args, options = {}) {
    const result = spawnSync(process.execPath, [CLI, ...args], { encoding: 'utf8', ...options });

    return { status: result.status, stdout: result.stdout, stderr: result.stderr };
}
