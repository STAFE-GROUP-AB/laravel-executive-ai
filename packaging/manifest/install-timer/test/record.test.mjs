import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';

import {
    CORE_STATES,
    InstallTimerError,
    TIMED_STATES,
    buildRecord,
    parseEventsText,
    percentile,
    ulid,
    validateRecord,
} from '../install-timer.mjs';
import { CLI, MACHINE, TYPICAL_DURATIONS, TYPICAL_HUMAN, eventsFor, runCli, tempDir } from './helpers.mjs';

const build = (events, overrides = {}) => buildRecord({
    events,
    profile: 'mac-a',
    runtimeCached: true,
    cleanMachine: true,
    machine: MACHINE,
    now: new Date('2026-10-10T10:00:00Z'),
    ...overrides,
});

test('record: per-state durations sum to core_ready_ms and the record validates', () => {
    const record = build(eventsFor({ durations: TYPICAL_DURATIONS, human: TYPICAL_HUMAN }));
    const total = TYPICAL_DURATIONS.reduce((a, b) => a + b, 0);

    assert.equal(record.schema_version, '1.0.0');
    assert.match(record.run_id, /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/);
    assert.equal(record.core_ready_ms, total);
    assert.deepEqual(Object.keys(record.phases), TIMED_STATES);
    TIMED_STATES.forEach((state, i) => assert.equal(record.phases[state].duration_ms, TYPICAL_DURATIONS[i]));
    assert.equal(record.started_at, '2026-10-10T09:00:00.000Z');
    assert.equal(Date.parse(record.ready_at) - Date.parse(record.started_at), total);
    assert.deepEqual(validateRecord(record), []);
});

test('record: human interaction is counted as its own phase and attributed to states', () => {
    const record = build(eventsFor({ durations: TYPICAL_DURATIONS, human: TYPICAL_HUMAN }));
    const humanTotal = TYPICAL_HUMAN.reduce((a, h) => a + h.durationMs, 0);

    assert.equal(record.human_interaction_ms, humanTotal);
    assert.equal(record.automated_ms, record.core_ready_ms - humanTotal);
    assert.equal(record.human_interactions.length, 3);
    assert.deepEqual(record.human_interactions.map((h) => h.during_state), ['WELCOME', 'CREATE_OWNER', 'SELECT_AI']);
    assert.equal(record.phases.CREATE_OWNER.human_ms, 28_000);
    assert.equal(record.phases.INSTALL_CORE.human_ms, 0);

    const silent = build(eventsFor({ durations: TYPICAL_DURATIONS }));

    assert.equal(silent.human_interaction_ms, 0);
    assert.equal(silent.core_ready_ms, record.core_ready_ms, 'human time is inside core_ready_ms (§12.2), not added on top');
});

test('record: a human prompt spanning two states is split per state', () => {
    const human = [{ state: 'CREATE_OWNER', offsetMs: 20_000, durationMs: 15_000 }];
    const record = build(eventsFor({ durations: TYPICAL_DURATIONS, human }));

    assert.equal(record.phases.CREATE_OWNER.human_ms, 10_000);
    assert.equal(record.phases.SELECT_AI.human_ms, 5_000);
    assert.equal(record.human_interaction_ms, 15_000);
});

test('record: async post-core steps never change core_ready_ms', () => {
    const base = build(eventsFor({ durations: TYPICAL_DURATIONS, human: TYPICAL_HUMAN }));
    const withAsync = build(eventsFor({
        durations: TYPICAL_DURATIONS,
        human: TYPICAL_HUMAN,
        async: [
            { name: 'MODEL_DOWNLOAD', offsetMs: 2_000, durationMs: 900_000 },
            { name: 'OAUTH_GOOGLE', offsetMs: 5_000, durationMs: 40_000 },
            { name: 'PEEKABOO_PERMISSIONS', offsetMs: 50_000, durationMs: 3_000, outcome: 'skipped' },
            { name: 'TAILSCALE_LOGIN', offsetMs: 60_000, durationMs: null },
        ],
    }));

    assert.equal(withAsync.core_ready_ms, base.core_ready_ms);
    assert.equal(withAsync.human_interaction_ms, base.human_interaction_ms);
    assert.deepEqual(withAsync.phases, base.phases);
    assert.equal(withAsync.async_post_core.counted_in_core_ready_ms, false);
    assert.equal(withAsync.async_post_core.steps.MODEL_DOWNLOAD.duration_ms, 900_000);
    assert.equal(withAsync.async_post_core.steps.PEEKABOO_PERMISSIONS.outcome, 'skipped');
    assert.equal(withAsync.async_post_core.steps.TAILSCALE_LOGIN.outcome, 'incomplete');
    assert.equal(withAsync.async_post_core.steps.TAILSCALE_LOGIN.ended_at, null);
    assert.deepEqual(validateRecord(withAsync), []);

    const topLevelNumbers = Object.entries(withAsync).filter(([, v]) => typeof v === 'number').map(([k]) => k);

    assert.deepEqual(topLevelNumbers.sort(), ['automated_ms', 'core_ready_ms', 'dmg_open_to_welcome_ms', 'human_interaction_ms'].filter((k) => typeof withAsync[k] === 'number').sort(), 'no top-level async total exists to be summed with core_ready_ms');
});

test('record: an async step that starts before READY is flagged, not counted', () => {
    const record = build(eventsFor({
        durations: TYPICAL_DURATIONS,
        async: [{ name: 'MODEL_DOWNLOAD', offsetMs: -30_000, durationMs: 600_000 }],
    }));

    assert.equal(record.core_ready_ms, TYPICAL_DURATIONS.reduce((a, b) => a + b, 0));
    assert.equal(record.async_post_core.steps.MODEL_DOWNLOAD.overlaps_core_window, true);
});

test('record: DMG_OPEN is reported separately and does not enter core_ready_ms', () => {
    const record = build(eventsFor({ durations: TYPICAL_DURATIONS, dmgOpenMs: 4_200 }));

    assert.equal(record.dmg_open_to_welcome_ms, 4_200);
    assert.equal(record.core_ready_ms, TYPICAL_DURATIONS.reduce((a, b) => a + b, 0));

    const without = build(eventsFor({ durations: TYPICAL_DURATIONS }));

    assert.equal(without.dmg_open_to_welcome_ms, null);
});

test('record: rejects missing, duplicated and out-of-order states with a clear message', () => {
    const events = eventsFor({ durations: TYPICAL_DURATIONS });

    assert.throws(() => build(events.filter((e) => e.state !== 'HEALTH_CHECK')), (e) => e instanceof InstallTimerError && /missing mandatory state event\(s\): HEALTH_CHECK/.test(e.message));
    assert.throws(() => build([...events, { state: 'PREFLIGHT', at: '2026-10-10T09:30:00Z' }]), /appears more than once/);

    const swapped = events.map((e) => (e.state === 'PREFLIGHT' ? { ...e, state: 'PACKAGE_VERIFY' } : e.state === 'PACKAGE_VERIFY' ? { ...e, state: 'PREFLIGHT' } : e));

    assert.throws(() => build(swapped), /states out of order/);
    assert.throws(() => build([...events, { state: 'model-download', marker: 'start', at: '2026-10-10T09:30:00Z' }]), /unknown event/);
    assert.throws(() => build([...events, { state: 'human_interaction_start', at: '2026-10-10T09:00:01Z' }]), /never closed/);
    assert.throws(() => build([...events, { state: 'human_interaction_start', at: '2026-10-10T09:30:00Z' }, { state: 'human_interaction_end', at: '2026-10-10T09:30:05Z' }]), /outside the core window/);
    assert.throws(() => build(events, { machine: { chip: 'x' } }), /machine\.macos_version is required/);
    assert.throws(() => build(events, { profile: 'mac-c' }), /profile must be one of/);
});

test('validateRecord: schema and cross-field rules reject tampered records', () => {
    const record = build(eventsFor({ durations: TYPICAL_DURATIONS, human: TYPICAL_HUMAN, async: [{ name: 'OAUTH_GOOGLE', offsetMs: 1_000, durationMs: 30_000 }] }));

    assert.deepEqual(validateRecord(record), []);

    const summed = structuredClone(record);
    summed.core_ready_ms += summed.async_post_core.steps.OAUTH_GOOGLE.duration_ms;
    assert.match(validateRecord(summed).join('\n'), /core_ready_ms .* must equal ready_at − started_at/);

    const counted = structuredClone(record);
    counted.async_post_core.counted_in_core_ready_ms = true;
    assert.match(validateRecord(counted).join('\n'), /counted_in_core_ready_ms must equal false/);

    const extra = structuredClone(record);
    extra.total_with_downloads_ms = 1;
    assert.match(validateRecord(extra).join('\n'), /unexpected property "total_with_downloads_ms"/);

    const badHuman = structuredClone(record);
    badHuman.human_interaction_ms = 1;
    assert.match(validateRecord(badHuman).join('\n'), /human_interactions sum to/);

    const badProfile = structuredClone(record);
    badProfile.profile = 'mac-z';
    assert.match(validateRecord(badProfile).join('\n'), /\/profile must be one of/);

    const missingPhase = structuredClone(record);
    delete missingPhase.phases.PREFLIGHT;
    assert.match(validateRecord(missingPhase).join('\n'), /missing required property "PREFLIGHT"/);
});

test('example record is synthetic and validates', () => {
    const example = JSON.parse(readFileSync(join(CLI, '..', 'results', 'example', 'synthetic-mac-a-cached.json'), 'utf8'));

    assert.equal(example.synthetic, true);
    assert.equal(example.clean_machine, false);
    assert.deepEqual(validateRecord(example), []);
    assert.equal(example.async_post_core.counted_in_core_ready_ms, false);
});

test('parseEventsText accepts an array, an {events} object and NDJSON', () => {
    const events = eventsFor({ durations: TYPICAL_DURATIONS });

    assert.equal(parseEventsText(JSON.stringify(events)).length, events.length);
    assert.equal(parseEventsText(JSON.stringify({ events })).length, events.length);
    assert.equal(parseEventsText(events.map((e) => JSON.stringify(e)).join('\n')).length, events.length);
    assert.throws(() => parseEventsText('   '), /no events/);
});

test('cli record: reads events from stdin, writes one file per run, flags --clean no', () => {
    const dir = tempDir();
    const events = eventsFor({ durations: TYPICAL_DURATIONS, human: TYPICAL_HUMAN });
    const result = runCli([
        'record', '--profile', 'mac-b', '--runtime-cached', 'no', '--clean', 'no', '--out', dir,
        '--chip', 'Apple M3 Ultra', '--memory-gb', '96', '--macos-version', '26.6.2',
    ], { input: JSON.stringify({ events }) });

    assert.equal(result.status, 0, result.stderr);
    assert.match(result.stdout, /wrote .*mac-b-uncached-[0-7][0-9A-HJKMNP-TV-Z]{25}\.json/);
    assert.match(result.stdout, /never counts toward the §12\.2 gate/);

    const written = result.stdout.match(/wrote (\S+)/)[1];
    const record = JSON.parse(readFileSync(written.startsWith('/') ? written : join(process.cwd(), written), 'utf8'));

    assert.equal(record.profile, 'mac-b');
    assert.equal(record.runtime_cached, false);
    assert.equal(record.machine.memory_gb, 96);
    assert.deepEqual(validateRecord(record), []);
});

test('cli record: a bad event stream exits 2 with the reason', () => {
    const result = runCli(['record', '--profile', 'mac-a', '--runtime-cached', 'yes', '--chip', 'x', '--memory-gb', '36', '--macos-version', '26.6', '--out', '-'], {
        input: JSON.stringify([{ state: 'WELCOME', at: '2026-10-10T09:00:00Z' }]),
    });

    assert.equal(result.status, 2);
    assert.match(result.stderr, /missing mandatory state event/);
});

test('ulid and percentile helpers', () => {
    const id = ulid(Date.parse('2026-10-10T00:00:00Z'));

    assert.match(id, /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/);
    assert.equal(id.slice(0, 10), ulid(Date.parse('2026-10-10T00:00:00Z')).slice(0, 10));
    assert.equal(CORE_STATES.length, 9);
    assert.equal(percentile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 50), 5);
    assert.equal(percentile([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 95), 10);
    assert.equal(percentile([5], 95), 5);
    assert.equal(percentile([], 95), null);
});
