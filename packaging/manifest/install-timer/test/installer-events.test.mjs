// The S1-05 installer writes its event log in the §4 shape; this proves the committed fixture
// (`packaging/fixtures/e2e/installer-events.ndjson`, byte-identical to what
// `InstallEventLog` emits — pinned by `InstallEventLogTests.testMatchesTheCommittedE2EFixture`)
// is consumed by `record` without any translation.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

import { TIMED_STATES, buildRecord, parseEventsText, validateRecord } from '../install-timer.mjs';
import { CLI, MACHINE, runCli } from './helpers.mjs';

const FIXTURE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..', 'fixtures', 'e2e', 'installer-events.ndjson');

const fixtureText = () => readFileSync(FIXTURE, 'utf8');

test('installer events: the NDJSON fixture parses as one event per line with only the contract keys', () => {
    const events = parseEventsText(fixtureText());

    assert.equal(events.length, 17);

    for (const event of events) {
        const keys = Object.keys(event).sort();
        assert.ok(['at', 'state'].every((key) => keys.includes(key)), JSON.stringify(event));
        assert.ok(keys.every((key) => ['at', 'marker', 'outcome', 'state'].includes(key)), JSON.stringify(event));
        assert.match(event.at, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}(Z|[+-]\d{2}:\d{2})$/);
    }
});

test('installer events: the record has the nine states, three prompts and one skipped async step', () => {
    const record = buildRecord({
        events: parseEventsText(fixtureText()),
        profile: 'other',
        runtimeCached: false,
        cleanMachine: false,
        machine: MACHINE,
        now: new Date('2026-10-09T10:30:00Z'),
    });

    validateRecord(record);

    assert.equal(record.core_ready_ms, 21_000);
    assert.equal(record.human_interaction_ms, 4_500);
    assert.equal(record.automated_ms, 16_500);
    assert.deepEqual(Object.keys(record.phases).sort(), [...TIMED_STATES].sort());
    assert.equal(record.phases.WELCOME.duration_ms, 4_500);
    assert.equal(record.phases.WELCOME.human_ms, 1_500);
    assert.equal(record.phases.CREATE_OWNER.human_ms, 1_500);
    assert.equal(record.phases.SELECT_AI.human_ms, 1_500);
    assert.equal(record.phases.HEALTH_CHECK.duration_ms, 1_500);

    assert.equal(record.async_post_core.counted_in_core_ready_ms, false);
    assert.deepEqual(Object.keys(record.async_post_core.steps), ['CONNECT_EMAIL']);
    assert.equal(record.async_post_core.steps.CONNECT_EMAIL.outcome, 'skipped');
    assert.equal(record.async_post_core.steps.CONNECT_EMAIL.duration_ms, 1_500);
    assert.equal(record.async_post_core.steps.CONNECT_EMAIL.overlaps_core_window, false);
});

test('installer events: the CLI consumes the fixture file directly', () => {
    const result = runCli([
        'record', '--events', FIXTURE, '--out', '-', '--profile', 'other', '--runtime-cached', 'no',
        '--chip', 'x', '--memory-gb', '8', '--macos-version', '15.0',
    ]);

    assert.equal(result.status, 0, result.stderr);

    const record = JSON.parse(result.stdout);
    assert.equal(record.core_ready_ms, 21_000);
    assert.equal(record.profile, 'other');
    assert.equal(record.runtime_cached, false);
    assert.ok(CLI.endsWith('install-timer.mjs'));
});
