#!/usr/bin/env node
// install-timer — the §16 step 6 runner. Records one JSON record per measured install, aggregates
// records into p50/p95 per phase and gates on the §12.2 targets (p50 < 180 s, p95 < 300 s
// core-ready, ≥ 30 runs per machine profile). No runtime dependencies; Node 24+.
//
//   node install-timer.mjs record    --profile mac-a --runtime-cached yes --clean yes --events run.json --out results/
//   node install-timer.mjs aggregate results/ [--summary summary.json]
//   node install-timer.mjs gate      results/ --p50 180000 --p95 300000 --min-runs 30
//
// Core-ready (`core_ready_ms`) is WELCOME → READY wall-clock time, human prompts included (§12.2).
// Asynchronous post-core steps (model downloads, OAuth, permissions, Tailscale, …) live in
// `async_post_core` and are never part of `core_ready_ms` (§0). See README.md and
// docs/operations/install-measurement.md.

import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { basename, dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parseArgs } from 'node:util';

export const SCHEMA_VERSION = '1.0.0';
export const SUMMARY_SCHEMA_VERSION = '1.0.0';

// §12.1 mandatory states, in order. READY is the terminal marker and has no duration of its own.
export const CORE_STATES = [
    'WELCOME',
    'PREFLIGHT',
    'PACKAGE_VERIFY',
    'INSTALL_CORE',
    'CREATE_OWNER',
    'SELECT_AI',
    'BOOT_SERVICES',
    'HEALTH_CHECK',
    'READY',
];
export const TIMED_STATES = CORE_STATES.slice(0, -1);

// §12.1 optional path + the §0 list of things that happen after core-ready. Any other
// `[A-Z][A-Z0-9_]*` name is accepted too, so a new async step never needs a tool release.
export const KNOWN_ASYNC_STEPS = [
    'CONNECT_EMAIL',
    'CONNECT_GITHUB',
    'PEEKABOO_PERMISSIONS',
    'REMOTE_ACCESS',
    'SCHEDULE_BRIEF',
    'MODEL_DOWNLOAD',
    'OAUTH_GOOGLE',
    'OAUTH_MICROSOFT',
    'OAUTH_GITHUB',
    'ICLOUD_MAIL',
    'HEY',
    'TAILSCALE_LOGIN',
    'IOS_PUSH',
    'EMAIL_VERIFICATION',
];

export const PROFILES = ['mac-a', 'mac-b', 'other'];
export const SCENARIOS = ['fresh_install', 'reinstall', 'upgrade', 'data_preserving_reinstall'];
export const ASYNC_OUTCOMES = ['completed', 'skipped', 'failed', 'cancelled', 'incomplete'];

const HUMAN_START = 'human_interaction_start';
const HUMAN_END = 'human_interaction_end';
const DMG_OPEN = 'DMG_OPEN';

const DEFAULT_GATE = { p50: 180_000, p95: 300_000, minRuns: 30, profiles: ['mac-a', 'mac-b'] };

const here = dirname(fileURLToPath(import.meta.url));

export class InstallTimerError extends Error {}

function fail(message) {
    throw new InstallTimerError(message);
}

// ---------------------------------------------------------------------------------------------
// ULID (Crockford base32, 48-bit time + 80-bit randomness)
// ---------------------------------------------------------------------------------------------

const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

export function ulid(now = Date.now()) {
    let time = now;
    let timePart = '';

    for (let i = 0; i < 10; i += 1) {
        timePart = CROCKFORD[time % 32] + timePart;
        time = Math.floor(time / 32);
    }

    const bytes = randomBytes(16);
    let randomPart = '';

    for (let i = 0; i < 16; i += 1) {
        randomPart += CROCKFORD[bytes[i] & 31];
    }

    return timePart + randomPart;
}

// ---------------------------------------------------------------------------------------------
// Machine profile detection (macOS only; everything is overridable by flags)
// ---------------------------------------------------------------------------------------------

function readCommand(command, args) {
    try {
        return execFileSync(command, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
    } catch {
        return null;
    }
}

export function detectMachine() {
    if (process.platform !== 'darwin') {
        return {};
    }

    const chip = readCommand('sysctl', ['-n', 'machdep.cpu.brand_string']);
    const memsize = readCommand('sysctl', ['-n', 'hw.memsize']);
    const macosVersion = readCommand('sw_vers', ['-productVersion']);
    const macosBuild = readCommand('sw_vers', ['-buildVersion']);
    const machine = {};

    if (chip) {
        machine.chip = chip;
    }

    if (memsize && /^\d+$/.test(memsize)) {
        machine.memory_gb = Math.round(Number(memsize) / 2 ** 30);
    }

    if (macosVersion) {
        machine.macos_version = macosVersion;
    }

    if (macosBuild) {
        machine.macos_build = macosBuild;
    }

    return machine;
}

// ---------------------------------------------------------------------------------------------
// Event parsing
// ---------------------------------------------------------------------------------------------

const RFC3339 = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/;

function parseTimestamp(value, context) {
    if (typeof value !== 'string' || ! RFC3339.test(value)) {
        fail(`${context}: "at" must be an RFC 3339 timestamp, got ${JSON.stringify(value)}`);
    }

    const ms = Date.parse(value);

    if (Number.isNaN(ms)) {
        fail(`${context}: unparseable timestamp ${JSON.stringify(value)}`);
    }

    return ms;
}

export function parseEventsText(text) {
    const trimmed = text.trim();

    if (trimmed === '') {
        fail('no events: pass --events <file> or pipe a JSON events document on stdin');
    }

    let parsed;

    try {
        parsed = JSON.parse(trimmed);
    } catch {
        parsed = trimmed.split(/\r?\n/).filter((line) => line.trim() !== '').map((line, index) => {
            try {
                return JSON.parse(line);
            } catch {
                return fail(`events line ${index + 1} is neither part of a JSON document nor a JSON object`);
            }
        });
    }

    if (Array.isArray(parsed)) {
        return parsed;
    }

    if (parsed && Array.isArray(parsed.events)) {
        return parsed.events;
    }

    return fail('events must be a JSON array, an object with an "events" array, or NDJSON');
}

function normaliseEvents(rawEvents) {
    if (! Array.isArray(rawEvents) || rawEvents.length === 0) {
        fail('events must be a non-empty array');
    }

    return rawEvents.map((raw, index) => {
        const context = `event ${index + 1}`;

        if (! raw || typeof raw !== 'object' || typeof raw.state !== 'string') {
            fail(`${context}: each event needs a string "state" and an "at" timestamp`);
        }

        const at = parseTimestamp(raw.at, `${context} (${raw.state})`);
        const event = { state: raw.state, at, atIso: new Date(at).toISOString() };

        if (raw.marker !== undefined) {
            event.marker = raw.marker;
        }

        if (raw.outcome !== undefined) {
            event.outcome = raw.outcome;
        }

        return event;
    });
}

// ---------------------------------------------------------------------------------------------
// Record construction
// ---------------------------------------------------------------------------------------------

function msBetween(startMs, endMs) {
    return Math.round(endMs - startMs);
}

function stateActiveAt(timeline, ms) {
    let active = null;

    for (const entry of timeline) {
        if (entry.at <= ms) {
            active = entry.state;
        }
    }

    return active;
}

function buildCoreTimeline(events) {
    const timeline = [];
    const seen = new Set();

    for (const event of events) {
        if (! CORE_STATES.includes(event.state)) {
            continue;
        }

        if (seen.has(event.state)) {
            fail(`state ${event.state} appears more than once; the timer records fresh, uninterrupted installs. Record a cancel/resume run as its own scenario and open an issue if the installer re-entered a state.`);
        }

        seen.add(event.state);
        timeline.push(event);
    }

    const missing = CORE_STATES.filter((state) => ! seen.has(state));

    if (missing.length > 0) {
        fail(`missing mandatory state event(s): ${missing.join(', ')} (§12.1 requires every state from WELCOME to READY)`);
    }

    for (let i = 0; i < timeline.length; i += 1) {
        if (timeline[i].state !== CORE_STATES[i]) {
            fail(`states out of order: expected ${CORE_STATES[i]} at position ${i + 1}, got ${timeline[i].state}`);
        }

        if (i > 0 && timeline[i].at < timeline[i - 1].at) {
            fail(`timestamps go backwards: ${timeline[i].state} (${timeline[i].atIso}) is before ${timeline[i - 1].state} (${timeline[i - 1].atIso})`);
        }
    }

    return timeline;
}

function buildHumanInteractions(events, timeline) {
    const welcomeAt = timeline[0].at;
    const readyAt = timeline[timeline.length - 1].at;
    const intervals = [];
    let open = null;

    for (const event of events) {
        if (event.state === HUMAN_START) {
            if (open) {
                fail(`${HUMAN_START} at ${event.atIso} while a human interaction started at ${open.atIso} is still open; intervals cannot nest`);
            }

            open = event;
            continue;
        }

        if (event.state !== HUMAN_END) {
            continue;
        }

        if (! open) {
            fail(`${HUMAN_END} at ${event.atIso} without a preceding ${HUMAN_START}`);
        }

        if (event.at < open.at) {
            fail(`${HUMAN_END} at ${event.atIso} is before its ${HUMAN_START} at ${open.atIso}`);
        }

        if (open.at < welcomeAt || event.at > readyAt) {
            fail(`human interaction ${open.atIso}–${event.atIso} lies outside the core window (WELCOME ${timeline[0].atIso} → READY ${timeline[timeline.length - 1].atIso}). Human time spent in an asynchronous post-core step belongs inside that step's start/end markers, not in human_interaction_ms.`);
        }

        intervals.push({
            started_at: open.atIso,
            ended_at: event.atIso,
            duration_ms: msBetween(open.at, event.at),
            during_state: stateActiveAt(timeline, open.at),
            startMs: open.at,
            endMs: event.at,
        });
        open = null;
    }

    if (open) {
        fail(`${HUMAN_START} at ${open.atIso} was never closed with ${HUMAN_END}`);
    }

    return intervals;
}

function humanMsWithin(intervals, startMs, endMs) {
    let total = 0;

    for (const interval of intervals) {
        const overlapStart = Math.max(interval.startMs, startMs);
        const overlapEnd = Math.min(interval.endMs, endMs);

        if (overlapEnd > overlapStart) {
            total += overlapEnd - overlapStart;
        }
    }

    return Math.round(total);
}

const ASYNC_NAME = /^[A-Z][A-Z0-9_]*$/;

function buildAsyncSteps(events, readyAt) {
    const steps = {};
    const open = new Map();

    for (const event of events) {
        const { state } = event;

        if (CORE_STATES.includes(state) || state === HUMAN_START || state === HUMAN_END || state === DMG_OPEN) {
            continue;
        }

        if (! ASYNC_NAME.test(state)) {
            fail(`unknown event "${state}": core states are ${CORE_STATES.join(', ')}; async post-core steps are UPPER_SNAKE_CASE names with marker start|end (known: ${KNOWN_ASYNC_STEPS.join(', ')})`);
        }

        if (event.marker !== 'start' && event.marker !== 'end') {
            fail(`async step ${state} at ${event.atIso} needs "marker": "start" or "end"`);
        }

        if (event.marker === 'start') {
            if (open.has(state) || steps[state]) {
                fail(`async step ${state} started twice; one interval per step per run`);
            }

            open.set(state, event);
            continue;
        }

        const start = open.get(state);

        if (! start) {
            fail(`async step ${state} ended at ${event.atIso} without a start marker`);
        }

        if (event.at < start.at) {
            fail(`async step ${state} ends before it starts`);
        }

        const outcome = event.outcome ?? 'completed';

        if (! ASYNC_OUTCOMES.includes(outcome) || outcome === 'incomplete') {
            fail(`async step ${state}: outcome must be one of completed, skipped, failed, cancelled (got ${JSON.stringify(outcome)})`);
        }

        steps[state] = {
            started_at: start.atIso,
            ended_at: event.atIso,
            duration_ms: msBetween(start.at, event.at),
            outcome,
            overlaps_core_window: start.at < readyAt,
        };
        open.delete(state);
    }

    for (const [state, start] of open) {
        steps[state] = {
            started_at: start.atIso,
            ended_at: null,
            duration_ms: null,
            outcome: 'incomplete',
            overlaps_core_window: start.at < readyAt,
        };
    }

    return Object.fromEntries(Object.entries(steps).sort(([a], [b]) => a.localeCompare(b)));
}

/**
 * Builds one install-timer record from raw events plus run metadata. Pure: no I/O, no clock
 * unless `now`/`runId` are omitted.
 */
export function buildRecord(options) {
    const {
        events: rawEvents,
        profile,
        runtimeCached,
        cleanMachine = false,
        scenario = 'fresh_install',
        synthetic = false,
        machine = {},
        build,
        notes,
        now = new Date(),
        runId = ulid(now.getTime()),
    } = options;

    if (! PROFILES.includes(profile)) {
        fail(`profile must be one of ${PROFILES.join(', ')} (got ${JSON.stringify(profile)})`);
    }

    if (typeof runtimeCached !== 'boolean') {
        fail('runtimeCached must be a boolean');
    }

    if (! SCENARIOS.includes(scenario)) {
        fail(`scenario must be one of ${SCENARIOS.join(', ')}`);
    }

    for (const key of ['chip', 'macos_version']) {
        if (typeof machine[key] !== 'string' || machine[key] === '') {
            fail(`machine.${key} is required; pass --${key.replace('_', '-')} when it cannot be detected`);
        }
    }

    if (! Number.isInteger(machine.memory_gb) || machine.memory_gb <= 0) {
        fail('machine.memory_gb must be a positive integer; pass --memory-gb when it cannot be detected');
    }

    const events = normaliseEvents(rawEvents).sort((a, b) => a.at - b.at);
    const timeline = buildCoreTimeline(events);
    const welcome = timeline[0];
    const ready = timeline[timeline.length - 1];
    const humanIntervals = buildHumanInteractions(events, timeline);
    const phases = {};

    for (let i = 0; i < TIMED_STATES.length; i += 1) {
        const start = timeline[i];
        const end = timeline[i + 1];

        phases[start.state] = {
            started_at: start.atIso,
            duration_ms: msBetween(start.at, end.at),
            human_ms: humanMsWithin(humanIntervals, start.at, end.at),
        };
    }

    const coreReadyMs = msBetween(welcome.at, ready.at);
    const humanInteractionMs = humanIntervals.reduce((sum, i) => sum + i.duration_ms, 0);
    const dmgOpen = events.filter((e) => e.state === DMG_OPEN);

    if (dmgOpen.length > 1) {
        fail(`${DMG_OPEN} may appear at most once`);
    }

    if (dmgOpen.length === 1 && dmgOpen[0].at > welcome.at) {
        fail(`${DMG_OPEN} must precede WELCOME`);
    }

    const record = {
        schema_version: SCHEMA_VERSION,
        run_id: runId,
        timestamp: now.toISOString(),
        synthetic: Boolean(synthetic),
        profile,
        machine: {
            chip: machine.chip,
            memory_gb: machine.memory_gb,
            macos_version: machine.macos_version,
            ...(machine.macos_build ? { macos_build: machine.macos_build } : {}),
        },
        runtime_cached: runtimeCached,
        clean_machine: Boolean(cleanMachine),
        scenario,
        ...(build && Object.keys(build).length > 0 ? { build } : {}),
        started_at: welcome.atIso,
        ready_at: ready.atIso,
        core_ready_ms: coreReadyMs,
        human_interaction_ms: humanInteractionMs,
        automated_ms: coreReadyMs - humanInteractionMs,
        dmg_open_to_welcome_ms: dmgOpen.length === 1 ? msBetween(dmgOpen[0].at, welcome.at) : null,
        phases,
        human_interactions: humanIntervals.map(({ startMs, endMs, ...rest }) => rest),
        async_post_core: {
            counted_in_core_ready_ms: false,
            steps: buildAsyncSteps(events, ready.at),
        },
        ...(notes ? { notes } : {}),
    };

    const errors = validateRecord(record);

    if (errors.length > 0) {
        fail(`internal error: built record does not validate against record.schema.json:\n  ${errors.join('\n  ')}`);
    }

    return record;
}

// ---------------------------------------------------------------------------------------------
// Dependency-free validation of the subset of JSON Schema draft 2020-12 that record.schema.json
// uses. CI additionally validates the schema and the example record with ajv
// (packaging/fixtures/validate-schema.mjs), so a drift between the two validators is caught.
// ---------------------------------------------------------------------------------------------

let cachedSchema = null;

export function loadSchema() {
    if (! cachedSchema) {
        cachedSchema = JSON.parse(readFileSync(join(here, 'record.schema.json'), 'utf8'));
    }

    return cachedSchema;
}

function typeOf(value) {
    if (value === null) {
        return 'null';
    }

    if (Array.isArray(value)) {
        return 'array';
    }

    if (typeof value === 'number') {
        return Number.isInteger(value) ? 'integer' : 'number';
    }

    return typeof value;
}

function matchesType(expected, value) {
    const actual = typeOf(value);

    if (expected === 'number') {
        return actual === 'number' || actual === 'integer';
    }

    return actual === expected;
}

function resolveRef(root, ref) {
    if (! ref.startsWith('#/')) {
        fail(`unsupported $ref ${ref}`);
    }

    return ref.slice(2).split('/').reduce((node, key) => node?.[key.replace(/~1/g, '/').replace(/~0/g, '~')], root);
}

function validateNode(root, schema, value, path, errors) {
    if (schema.$ref) {
        validateNode(root, resolveRef(root, schema.$ref), value, path, errors);

        return;
    }

    const location = path || '/';

    if (schema.type !== undefined) {
        const types = Array.isArray(schema.type) ? schema.type : [schema.type];

        if (! types.some((t) => matchesType(t, value))) {
            errors.push(`${location} must be of type ${types.join('|')}, got ${typeOf(value)}`);

            return;
        }
    }

    if (schema.const !== undefined && value !== schema.const) {
        errors.push(`${location} must equal ${JSON.stringify(schema.const)}`);
    }

    if (schema.enum !== undefined && ! schema.enum.includes(value)) {
        errors.push(`${location} must be one of ${schema.enum.map((v) => JSON.stringify(v)).join(', ')}`);
    }

    if (typeof value === 'string') {
        if (schema.pattern !== undefined && ! new RegExp(schema.pattern).test(value)) {
            errors.push(`${location} must match pattern ${schema.pattern}`);
        }

        if (schema.minLength !== undefined && value.length < schema.minLength) {
            errors.push(`${location} must be at least ${schema.minLength} characters`);
        }

        if (schema.maxLength !== undefined && value.length > schema.maxLength) {
            errors.push(`${location} must be at most ${schema.maxLength} characters`);
        }

        if (schema.format === 'date-time' && (! RFC3339.test(value) || Number.isNaN(Date.parse(value)))) {
            errors.push(`${location} must be an RFC 3339 date-time`);
        }
    }

    if (typeof value === 'number') {
        if (schema.minimum !== undefined && value < schema.minimum) {
            errors.push(`${location} must be >= ${schema.minimum}`);
        }

        if (schema.maximum !== undefined && value > schema.maximum) {
            errors.push(`${location} must be <= ${schema.maximum}`);
        }
    }

    if (Array.isArray(value)) {
        if (schema.minItems !== undefined && value.length < schema.minItems) {
            errors.push(`${location} must have at least ${schema.minItems} items`);
        }

        if (schema.items !== undefined) {
            value.forEach((item, index) => validateNode(root, schema.items, item, `${path}/${index}`, errors));
        }
    }

    if (value !== null && typeof value === 'object' && ! Array.isArray(value)) {
        for (const key of schema.required ?? []) {
            if (! (key in value)) {
                errors.push(`${location} is missing required property "${key}"`);
            }
        }

        const properties = schema.properties ?? {};
        const patterns = Object.entries(schema.patternProperties ?? {}).map(([p, s]) => [new RegExp(p), s]);

        for (const [key, child] of Object.entries(value)) {
            const childPath = `${path}/${key}`;
            let matched = false;

            if (key in properties) {
                matched = true;
                validateNode(root, properties[key], child, childPath, errors);
            }

            for (const [regex, patternSchema] of patterns) {
                if (regex.test(key)) {
                    matched = true;
                    validateNode(root, patternSchema, child, childPath, errors);
                }
            }

            if (matched) {
                continue;
            }

            if (schema.additionalProperties === false) {
                errors.push(`${location} has unexpected property "${key}"`);
                continue;
            }

            if (schema.additionalProperties && typeof schema.additionalProperties === 'object') {
                validateNode(root, schema.additionalProperties, child, childPath, errors);
            }
        }
    }
}

/** @returns {string[]} validation errors; empty when the record is valid */
export function validateRecord(record, schema = loadSchema()) {
    const errors = [];

    validateNode(schema, schema, record, '', errors);

    if (errors.length > 0) {
        return errors;
    }

    // Cross-field rules the schema cannot express.
    const coreStart = Date.parse(record.started_at);
    const coreEnd = Date.parse(record.ready_at);

    if (coreEnd - coreStart !== record.core_ready_ms) {
        errors.push(`core_ready_ms (${record.core_ready_ms}) must equal ready_at − started_at (${coreEnd - coreStart})`);
    }

    const phaseSum = TIMED_STATES.reduce((sum, state) => sum + record.phases[state].duration_ms, 0);

    if (phaseSum !== record.core_ready_ms) {
        errors.push(`phase durations sum to ${phaseSum}, core_ready_ms is ${record.core_ready_ms}`);
    }

    const humanSum = record.human_interactions.reduce((sum, i) => sum + i.duration_ms, 0);

    if (humanSum !== record.human_interaction_ms) {
        errors.push(`human_interactions sum to ${humanSum}, human_interaction_ms is ${record.human_interaction_ms}`);
    }

    if (record.human_interaction_ms > record.core_ready_ms) {
        errors.push('human_interaction_ms cannot exceed core_ready_ms');
    }

    if (record.automated_ms !== record.core_ready_ms - record.human_interaction_ms) {
        errors.push('automated_ms must equal core_ready_ms − human_interaction_ms');
    }

    return errors;
}

// ---------------------------------------------------------------------------------------------
// Loading and aggregation
// ---------------------------------------------------------------------------------------------

function listRecordFiles(paths) {
    const files = [];

    const walk = (path) => {
        const stat = statSync(path);

        if (stat.isDirectory()) {
            for (const entry of readdirSync(path, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
                walk(join(path, entry.name));
            }

            return;
        }

        if (! path.endsWith('.json') || basename(path) === 'summary.json' || basename(path) === 'gate.json') {
            return;
        }

        files.push(path);
    };

    for (const path of paths) {
        walk(resolve(path));
    }

    return files;
}

/**
 * Loads every `*.json` record under the given files/directories, validating each one.
 *
 * @returns {{records: Array<{file: string, record: object}>, invalid: Array<{file: string, errors: string[]}>}}
 */
export function loadRecords(paths) {
    const records = [];
    const invalid = [];

    for (const file of listRecordFiles(paths)) {
        let record;

        try {
            record = JSON.parse(readFileSync(file, 'utf8'));
        } catch (error) {
            invalid.push({ file, errors: [`unreadable JSON: ${error.message}`] });
            continue;
        }

        const errors = validateRecord(record);

        if (errors.length > 0) {
            invalid.push({ file, errors });
            continue;
        }

        records.push({ file, record });
    }

    return { records, invalid };
}

// Nearest-rank percentile: the value at ceil(p/100 · n) in the sorted sample. Never interpolates,
// so a reported p95 is always a value that actually happened in a run.
export function percentile(sortedValues, p) {
    if (sortedValues.length === 0) {
        return null;
    }

    const rank = Math.max(1, Math.ceil((p / 100) * sortedValues.length));

    return sortedValues[rank - 1];
}

export function stats(values) {
    const sorted = values.filter((v) => typeof v === 'number').sort((a, b) => a - b);

    if (sorted.length === 0) {
        return { n: 0, p50: null, p95: null, min: null, max: null, mean: null };
    }

    const sum = sorted.reduce((a, b) => a + b, 0);

    return {
        n: sorted.length,
        p50: percentile(sorted, 50),
        p95: percentile(sorted, 95),
        min: sorted[0],
        max: sorted[sorted.length - 1],
        mean: Math.round(sum / sorted.length),
    };
}

function groupKey(record, byRuntimeCached) {
    return byRuntimeCached ? `${record.profile}|${record.runtime_cached ? 'cached' : 'uncached'}` : `${record.profile}|any`;
}

/**
 * Aggregates validated records into per-group statistics. Core-ready and async post-core steps
 * are reported in separate sub-objects; nothing here adds the two together.
 */
export function aggregateRecords(records, { byRuntimeCached = true } = {}) {
    const groups = new Map();

    for (const record of records) {
        const key = groupKey(record, byRuntimeCached);

        if (! groups.has(key)) {
            groups.set(key, []);
        }

        groups.get(key).push(record);
    }

    const result = [];

    for (const [key, members] of [...groups.entries()].sort(([a], [b]) => a.localeCompare(b))) {
        const [profile, cachedLabel] = key.split('|');
        const asyncSteps = {};

        for (const record of members) {
            for (const [name, step] of Object.entries(record.async_post_core.steps)) {
                if (! asyncSteps[name]) {
                    asyncSteps[name] = { durations: [], outcomes: {}, overlapping: 0 };
                }

                asyncSteps[name].outcomes[step.outcome] = (asyncSteps[name].outcomes[step.outcome] ?? 0) + 1;

                if (step.outcome === 'completed') {
                    asyncSteps[name].durations.push(step.duration_ms);
                }

                if (step.overlaps_core_window) {
                    asyncSteps[name].overlapping += 1;
                }
            }
        }

        result.push({
            profile,
            runtime_cached: cachedLabel === 'any' ? 'any' : cachedLabel === 'cached',
            n: members.length,
            clean_machine_runs: members.filter((r) => r.clean_machine).length,
            scenarios: countBy(members, (r) => r.scenario),
            macos_versions: countBy(members, (r) => r.machine.macos_version),
            core_ready_ms: stats(members.map((r) => r.core_ready_ms)),
            human_interaction_ms: stats(members.map((r) => r.human_interaction_ms)),
            automated_ms: stats(members.map((r) => r.automated_ms)),
            dmg_open_to_welcome_ms: stats(members.map((r) => r.dmg_open_to_welcome_ms)),
            phases: Object.fromEntries(TIMED_STATES.map((state) => [
                state,
                {
                    duration_ms: stats(members.map((r) => r.phases[state].duration_ms)),
                    human_ms: stats(members.map((r) => r.phases[state].human_ms)),
                },
            ])),
            async_post_core: {
                counted_in_core_ready_ms: false,
                network_conditional: true,
                steps: Object.fromEntries(Object.entries(asyncSteps).sort(([a], [b]) => a.localeCompare(b)).map(([name, data]) => [
                    name,
                    {
                        runs_with_step: Object.values(data.outcomes).reduce((a, b) => a + b, 0),
                        outcomes: data.outcomes,
                        completed_duration_ms: stats(data.durations),
                        runs_overlapping_core_window: data.overlapping,
                    },
                ])),
            },
            run_ids: members.map((r) => r.run_id),
        });
    }

    return result;
}

function countBy(items, keyOf) {
    const counts = {};

    for (const item of items) {
        const key = keyOf(item);
        counts[key] = (counts[key] ?? 0) + 1;
    }

    return counts;
}

// ---------------------------------------------------------------------------------------------
// Gate
// ---------------------------------------------------------------------------------------------

/**
 * Evaluates the §12.2 gate on already-filtered, already-validated records.
 *
 * @returns {{passed: boolean, results: object[], excluded: object}}
 */
export function evaluateGate(records, options = {}) {
    const p50Target = options.p50 ?? DEFAULT_GATE.p50;
    const p95Target = options.p95 ?? DEFAULT_GATE.p95;
    const minRuns = options.minRuns ?? DEFAULT_GATE.minRuns;
    const profiles = options.profiles ?? DEFAULT_GATE.profiles;
    const runtimeCached = options.runtimeCached ?? 'yes';
    const allowInsufficient = Boolean(options.allowInsufficient);

    const excluded = { synthetic: 0, not_clean_machine: 0, scenario: 0, runtime_cached: 0 };
    const eligible = records.filter((record) => {
        if (record.synthetic) {
            excluded.synthetic += 1;

            return false;
        }

        if (! record.clean_machine) {
            excluded.not_clean_machine += 1;

            return false;
        }

        if (record.scenario !== 'fresh_install') {
            excluded.scenario += 1;

            return false;
        }

        if (runtimeCached !== 'any' && record.runtime_cached !== (runtimeCached === 'yes')) {
            excluded.runtime_cached += 1;

            return false;
        }

        return true;
    });

    const groups = aggregateRecords(eligible, { byRuntimeCached: runtimeCached !== 'any' });
    const results = [];
    let passed = true;

    for (const profile of profiles) {
        const group = groups.find((g) => g.profile === profile);
        const n = group?.n ?? 0;
        const result = {
            profile,
            runtime_cached: runtimeCached,
            n,
            min_runs: minRuns,
            p50_ms: group?.core_ready_ms.p50 ?? null,
            p95_ms: group?.core_ready_ms.p95 ?? null,
            p50_target_ms: p50Target,
            p95_target_ms: p95Target,
            sufficient: n >= minRuns,
            p50_ok: group ? group.core_ready_ms.p50 < p50Target : null,
            p95_ok: group ? group.core_ready_ms.p95 < p95Target : null,
        };

        if (n === 0) {
            result.status = 'NO_DATA';
            result.message = `${profile}: no eligible runs (need ${minRuns}; eligible = not synthetic, clean machine, fresh_install, runtime_cached=${runtimeCached})`;
            passed = passed && allowInsufficient;
        } else if (! result.p50_ok || ! result.p95_ok) {
            result.status = 'FAIL';
            result.message = `${profile}: core-ready p50 ${fmtSeconds(result.p50_ms)} (target < ${fmtSeconds(p50Target)}), p95 ${fmtSeconds(result.p95_ms)} (target < ${fmtSeconds(p95Target)}) over ${n} run(s)${n < minRuns ? ` — and only ${n}/${minRuns} runs` : ''}`;
            passed = false;
        } else if (! result.sufficient) {
            result.status = 'INSUFFICIENT';
            result.message = `${profile}: ${n}/${minRuns} runs — thresholds met so far (p50 ${fmtSeconds(result.p50_ms)}, p95 ${fmtSeconds(result.p95_ms)}) but §12.2 requires at least ${minRuns} measured installs per profile`;
            passed = passed && allowInsufficient;
        } else {
            result.status = 'PASS';
            result.message = `${profile}: core-ready p50 ${fmtSeconds(result.p50_ms)} < ${fmtSeconds(p50Target)}, p95 ${fmtSeconds(result.p95_ms)} < ${fmtSeconds(p95Target)} over ${n} runs`;
        }

        results.push(result);
    }

    return { passed, results, excluded, groups, eligible_runs: eligible.length };
}

// ---------------------------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------------------------

export function fmtSeconds(ms) {
    if (ms === null || ms === undefined) {
        return '—';
    }

    return `${(ms / 1000).toFixed(1)}s`;
}

function pad(value, width, align = 'left') {
    const text = String(value);

    if (text.length >= width) {
        return text;
    }

    return align === 'right' ? ' '.repeat(width - text.length) + text : text + ' '.repeat(width - text.length);
}

function renderTable(headers, rows) {
    const widths = headers.map((h, i) => Math.max(h.length, ...rows.map((r) => String(r[i]).length)));
    const line = (cells, aligns) => cells.map((c, i) => pad(c, widths[i], aligns[i])).join('  ');
    const aligns = headers.map((_, i) => (i === 0 ? 'left' : 'right'));

    return [line(headers, aligns), widths.map((w) => '-'.repeat(w)).join('  '), ...rows.map((r) => line(r, aligns))].join('\n');
}

function statsRow(label, s) {
    return [label, s.n, fmtSeconds(s.p50), fmtSeconds(s.p95), fmtSeconds(s.min), fmtSeconds(s.max)];
}

export function renderSummary(groups) {
    const out = [];

    for (const group of groups) {
        const cached = group.runtime_cached === 'any' ? 'any' : group.runtime_cached ? 'cached' : 'not cached';

        out.push(`== profile ${group.profile} · runtime ${cached} · ${group.n} run(s) (${group.clean_machine_runs} on a clean machine) ==`);
        out.push('');
        out.push('Core-ready (WELCOME → READY, human prompts included):');
        out.push(renderTable(
            ['phase', 'n', 'p50', 'p95', 'min', 'max'],
            [
                statsRow('core_ready_ms', group.core_ready_ms),
                statsRow('  human_interaction_ms', group.human_interaction_ms),
                statsRow('  automated_ms', group.automated_ms),
                ...TIMED_STATES.map((state) => statsRow(`  ${state}`, group.phases[state].duration_ms)),
                ...(group.dmg_open_to_welcome_ms.n > 0 ? [statsRow('dmg_open_to_welcome_ms (info)', group.dmg_open_to_welcome_ms)] : []),
            ],
        ));
        out.push('');

        const steps = Object.entries(group.async_post_core.steps);

        if (steps.length === 0) {
            out.push('Async post-core steps (network-conditional, NOT part of core-ready): none recorded');
        } else {
            out.push('Async post-core steps (network-conditional, NOT part of core-ready):');
            out.push(renderTable(
                ['step', 'runs', 'completed', 'p50', 'p95', 'max', 'overlapped core'],
                steps.map(([name, s]) => [
                    name,
                    s.runs_with_step,
                    s.outcomes.completed ?? 0,
                    fmtSeconds(s.completed_duration_ms.p50),
                    fmtSeconds(s.completed_duration_ms.p95),
                    fmtSeconds(s.completed_duration_ms.max),
                    s.runs_overlapping_core_window,
                ]),
            ));
        }

        out.push('');
    }

    return out.join('\n');
}

// ---------------------------------------------------------------------------------------------
// CLI
// ---------------------------------------------------------------------------------------------

const USAGE = `install-timer — §16 step 6 install measurement runner

Usage:
  install-timer record    --profile <mac-a|mac-b|other> --runtime-cached <yes|no> [--clean <yes|no>]
                          [--events <file>] [--out <dir>|-] [--chip ..] [--memory-gb N] [--macos-version ..]
                          [--macos-build ..] [--scenario fresh_install] [--product-version ..] [--commit ..]
                          [--note ..] [--synthetic]
  install-timer aggregate <results...> [--summary <file>] [--include-synthetic] [--pool-runtime-cached]
  install-timer gate      <results...> [--p50 180000] [--p95 300000] [--min-runs 30]
                          [--profiles mac-a,mac-b] [--runtime-cached yes|no|any] [--allow-insufficient] [--out <file>]

Events for \`record\` come from --events <file> or stdin: a JSON array, {"events": [...]}, or NDJSON.
Each event is {"state": "<name>", "at": "<RFC 3339>"} for the §12.1 states WELCOME..READY, the
markers human_interaction_start/human_interaction_end, an optional DMG_OPEN before WELCOME, and
async post-core steps as {"state": "MODEL_DOWNLOAD", "marker": "start"|"end", "outcome": "completed"}.
`;

function yesNo(value, flag) {
    if (value === 'yes' || value === 'true') {
        return true;
    }

    if (value === 'no' || value === 'false') {
        return false;
    }

    return fail(`--${flag} must be yes or no`);
}

function readStdin() {
    try {
        return readFileSync(0, 'utf8');
    } catch (error) {
        if (error.code === 'EAGAIN' || error.code === 'EOF') {
            return '';
        }

        throw error;
    }
}

function commandRecord(argv) {
    const { values } = parseArgs({
        args: argv,
        allowPositionals: false,
        options: {
            profile: { type: 'string' },
            'runtime-cached': { type: 'string' },
            clean: { type: 'string', default: 'no' },
            events: { type: 'string' },
            out: { type: 'string', default: 'results' },
            chip: { type: 'string' },
            'memory-gb': { type: 'string' },
            'macos-version': { type: 'string' },
            'macos-build': { type: 'string' },
            scenario: { type: 'string', default: 'fresh_install' },
            'product-version': { type: 'string' },
            commit: { type: 'string' },
            note: { type: 'string' },
            synthetic: { type: 'boolean', default: false },
        },
    });

    if (! values.profile || ! values['runtime-cached']) {
        fail('record requires --profile and --runtime-cached');
    }

    const text = values.events ? readFileSync(values.events, 'utf8') : readStdin();
    const machine = { ...detectMachine() };

    if (values.chip) {
        machine.chip = values.chip;
    }

    if (values['memory-gb']) {
        machine.memory_gb = Number(values['memory-gb']);
    }

    if (values['macos-version']) {
        machine.macos_version = values['macos-version'];
    }

    if (values['macos-build']) {
        machine.macos_build = values['macos-build'];
    }

    const build = {};

    if (values['product-version']) {
        build.product_version = values['product-version'];
    }

    if (values.commit) {
        build.commit = values.commit;
    }

    const cleanMachine = yesNo(values.clean, 'clean');
    const record = buildRecord({
        events: parseEventsText(text),
        profile: values.profile,
        runtimeCached: yesNo(values['runtime-cached'], 'runtime-cached'),
        cleanMachine,
        scenario: values.scenario,
        synthetic: values.synthetic,
        machine,
        build,
        notes: values.note,
    });

    const json = `${JSON.stringify(record, null, 2)}\n`;

    if (values.out === '-') {
        process.stdout.write(json);

        return 0;
    }

    const day = record.timestamp.slice(0, 10);
    const file = join(values.out, `${day}-${record.profile}-${record.runtime_cached ? 'cached' : 'uncached'}-${record.run_id}.json`);

    mkdirSync(dirname(file), { recursive: true });
    writeFileSync(file, json);

    console.log(`wrote ${relative(process.cwd(), file)}`);
    console.log(`core_ready_ms=${record.core_ready_ms} (${fmtSeconds(record.core_ready_ms)}) human_interaction_ms=${record.human_interaction_ms} async steps=${Object.keys(record.async_post_core.steps).length}`);

    if (! cleanMachine) {
        console.log('note: recorded with --clean no; this run is reported but never counts toward the §12.2 gate');
    }

    for (const [name, step] of Object.entries(record.async_post_core.steps)) {
        if (step.overlaps_core_window) {
            console.log(`warning: async step ${name} started before READY; it overlaps the core window and is flagged in the record`);
        }
    }

    return 0;
}

function reportInvalid(invalid) {
    for (const { file, errors } of invalid) {
        console.error(`INVALID ${relative(process.cwd(), file)}`);

        for (const error of errors) {
            console.error(`        ${error}`);
        }
    }
}

function commandAggregate(argv) {
    const { values, positionals } = parseArgs({
        args: argv,
        allowPositionals: true,
        options: {
            summary: { type: 'string', default: 'summary.json' },
            'include-synthetic': { type: 'boolean', default: false },
            'pool-runtime-cached': { type: 'boolean', default: false },
        },
    });

    if (positionals.length === 0) {
        fail('aggregate needs at least one results file or directory');
    }

    const { records, invalid } = loadRecords(positionals);

    reportInvalid(invalid);

    const synthetic = records.filter((r) => r.record.synthetic).length;
    const used = records.filter((r) => values['include-synthetic'] || ! r.record.synthetic).map((r) => r.record);

    if (used.length === 0) {
        console.error(`no usable records (${records.length} loaded, ${synthetic} synthetic${invalid.length ? `, ${invalid.length} invalid` : ''})`);

        return 1;
    }

    const groups = aggregateRecords(used, { byRuntimeCached: ! values['pool-runtime-cached'] });
    const summary = {
        schema_version: SUMMARY_SCHEMA_VERSION,
        generated_at: new Date().toISOString(),
        percentile_method: 'nearest-rank',
        inputs: {
            files: records.length + invalid.length,
            records_used: used.length,
            excluded_synthetic: values['include-synthetic'] ? 0 : synthetic,
            invalid: invalid.length,
        },
        groups,
    };

    console.log(renderSummary(groups));

    if (synthetic > 0 && ! values['include-synthetic']) {
        console.log(`excluded ${synthetic} synthetic record(s); pass --include-synthetic to see them`);
    }

    mkdirSync(dirname(resolve(values.summary)), { recursive: true });
    writeFileSync(values.summary, `${JSON.stringify(summary, null, 2)}\n`);
    console.log(`wrote ${values.summary}`);

    return invalid.length > 0 ? 1 : 0;
}

function commandGate(argv) {
    const { values, positionals } = parseArgs({
        args: argv,
        allowPositionals: true,
        options: {
            p50: { type: 'string', default: String(DEFAULT_GATE.p50) },
            p95: { type: 'string', default: String(DEFAULT_GATE.p95) },
            'min-runs': { type: 'string', default: String(DEFAULT_GATE.minRuns) },
            profiles: { type: 'string', default: DEFAULT_GATE.profiles.join(',') },
            'runtime-cached': { type: 'string', default: 'yes' },
            'allow-insufficient': { type: 'boolean', default: false },
            out: { type: 'string' },
        },
    });

    if (positionals.length === 0) {
        fail('gate needs at least one results file or directory');
    }

    for (const flag of ['p50', 'p95', 'min-runs']) {
        if (! /^\d+$/.test(values[flag])) {
            fail(`--${flag} must be a non-negative integer (milliseconds for p50/p95)`);
        }
    }

    if (! ['yes', 'no', 'any'].includes(values['runtime-cached'])) {
        fail('--runtime-cached must be yes, no or any');
    }

    const profiles = values.profiles.split(',').map((p) => p.trim()).filter(Boolean);

    for (const profile of profiles) {
        if (! PROFILES.includes(profile)) {
            fail(`unknown profile ${profile}; valid: ${PROFILES.join(', ')}`);
        }
    }

    const { records, invalid } = loadRecords(positionals);

    reportInvalid(invalid);

    if (invalid.length > 0) {
        console.error(`GATE FAIL: ${invalid.length} record(s) do not validate against record.schema.json`);

        return 1;
    }

    const outcome = evaluateGate(records.map((r) => r.record), {
        p50: Number(values.p50),
        p95: Number(values.p95),
        minRuns: Number(values['min-runs']),
        profiles,
        runtimeCached: values['runtime-cached'],
        allowInsufficient: values['allow-insufficient'],
    });

    console.log(`install-timer gate — core-ready p50 < ${fmtSeconds(Number(values.p50))}, p95 < ${fmtSeconds(Number(values.p95))}, ≥ ${values['min-runs']} runs per profile, runtime_cached=${values['runtime-cached']}`);
    console.log(`records: ${records.length} loaded, ${outcome.eligible_runs} eligible (excluded: ${outcome.excluded.synthetic} synthetic, ${outcome.excluded.not_clean_machine} not clean machine, ${outcome.excluded.scenario} not fresh_install, ${outcome.excluded.runtime_cached} other runtime_cached)`);
    console.log('');

    for (const result of outcome.results) {
        console.log(`${pad(result.status, 12)} ${result.message}`);
    }

    console.log('');

    if (outcome.groups.length > 0) {
        console.log(renderSummary(outcome.groups));
    }

    const insufficient = outcome.results.filter((r) => r.status === 'INSUFFICIENT' || r.status === 'NO_DATA');

    if (outcome.passed && insufficient.length > 0) {
        console.log(`GATE NOT PROVEN: ${insufficient.length} profile(s) below ${values['min-runs']} runs; reported only because --allow-insufficient was given. This is not a passing §12.2 gate.`);
    } else if (outcome.passed) {
        console.log('GATE PASS');
    } else {
        console.log(insufficient.length > 0 && outcome.results.every((r) => r.status !== 'FAIL')
            ? 'GATE FAIL: insufficient runs (pass --allow-insufficient for interim reporting; the §12.2 gate still requires the full run count)'
            : 'GATE FAIL');
    }

    if (values.out) {
        mkdirSync(dirname(resolve(values.out)), { recursive: true });
        writeFileSync(values.out, `${JSON.stringify({ generated_at: new Date().toISOString(), ...outcome }, null, 2)}\n`);
        console.log(`wrote ${values.out}`);
    }

    return outcome.passed ? 0 : 1;
}

export function main(argv = process.argv.slice(2)) {
    const [command, ...rest] = argv;

    try {
        switch (command) {
            case 'record':
                return commandRecord(rest);
            case 'aggregate':
                return commandAggregate(rest);
            case 'gate':
                return commandGate(rest);
            case undefined:
            case '-h':
            case '--help':
            case 'help':
                process.stdout.write(USAGE);

                return command === undefined ? 2 : 0;
            default:
                fail(`unknown command "${command}"\n\n${USAGE}`);
        }
    } catch (error) {
        if (error instanceof InstallTimerError || error.code === 'ERR_PARSE_ARGS_UNKNOWN_OPTION' || error.code === 'ERR_PARSE_ARGS_INVALID_OPTION_VALUE') {
            console.error(`error: ${error.message}`);

            return 2;
        }

        throw error;
    }

    return 0;
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    process.exit(main());
}
