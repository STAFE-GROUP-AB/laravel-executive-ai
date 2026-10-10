#!/usr/bin/env node
// Validates the frozen contracts (S0-13 brief schema, S0-14 health contract) and every committed
// fixture against them. CI step: `node packaging/fixtures/validate-schema.mjs` (see S0-05).
//
// Exit code 0 when everything passes, 1 on any failure. No network access.

import { createHash } from 'node:crypto';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const rel = (path) => relative(repoRoot, path);

const suites = [
    {
        schema: join(here, 'brief.schema.json'),
        fixturesDir: join(here, 'briefs'),
        extraChecks: [checkBriefContentHash, checkBriefReferentialIntegrity],
    },
    {
        schema: join(here, 'health.schema.json'),
        fixturesDir: join(here, 'health'),
        extraChecks: [],
    },
];

const ajv = new Ajv2020({ strict: true, allErrors: true, allowUnionTypes: true });
addFormats(ajv);

let failures = 0;
let validated = 0;

function fail(subject, message) {
    failures += 1;
    console.error(`FAIL  ${subject}\n      ${message}`);
}

function pass(subject, note = '') {
    validated += 1;
    console.log(`ok    ${subject}${note ? `  (${note})` : ''}`);
}

function readJson(path) {
    return JSON.parse(readFileSync(path, 'utf8'));
}

function formatErrors(errors) {
    return (errors ?? [])
        .map((e) => `${e.instancePath || '/'} ${e.message ?? ''} ${JSON.stringify(e.params)}`)
        .join('\n      ');
}

// RFC 8785 (JCS) canonicalisation for the value types the brief schema allows: objects with sorted
// member names, arrays in order, strings, integers, bounded decimals, booleans and null.
function canonicalize(value) {
    if (Array.isArray(value)) {
        return `[${value.map(canonicalize).join(',')}]`;
    }

    if (value !== null && typeof value === 'object') {
        const keys = Object.keys(value).sort();

        return `{${keys.map((k) => `${JSON.stringify(k)}:${canonicalize(value[k])}`).join(',')}}`;
    }

    return JSON.stringify(value);
}

function checkBriefContentHash(subject, brief) {
    const envelope = { ...brief };
    delete envelope.delivery_state;
    delete envelope.deliveries;
    delete envelope.content_hash;

    const digest = `sha256:${createHash('sha256').update(canonicalize(envelope), 'utf8').digest('hex')}`;

    if (brief.content_hash !== digest) {
        fail(subject, `content_hash mismatch: document says ${brief.content_hash}, envelope hashes to ${digest}`);

        return false;
    }

    return true;
}

function checkBriefReferentialIntegrity(subject, brief) {
    const sourceIds = new Set(brief.sources.map((s) => s.id));
    const actionIds = new Set(brief.action_ids);
    const itemIds = new Set();
    const problems = [];

    if (brief.sources.length !== sourceIds.size) {
        problems.push('duplicate source ids');
    }

    for (const item of brief.items) {
        if (itemIds.has(item.id)) {
            problems.push(`duplicate item id ${item.id}`);
        }

        itemIds.add(item.id);

        for (const ref of item.source_refs ?? []) {
            if (! sourceIds.has(ref)) {
                problems.push(`item ${item.id} references unknown source ${ref}`);
            }
        }

        for (const actionId of item.action_ids ?? []) {
            if (! actionIds.has(actionId)) {
                problems.push(`item ${item.id} references action ${actionId} missing from action_ids`);
            }
        }
    }

    if (Date.parse(brief.period_end) < Date.parse(brief.period_start)) {
        problems.push('period_end is before period_start');
    }

    if (Date.parse(brief.stale_after) <= Date.parse(brief.generated_at)) {
        problems.push('stale_after must be after generated_at');
    }

    const sentPerTarget = new Map();

    for (const delivery of brief.deliveries) {
        if (delivery.status !== 'sent') {
            continue;
        }

        const key = `${delivery.channel}|${delivery.recipient}`;

        if (sentPerTarget.has(key)) {
            problems.push(`double delivery: more than one sent entry for ${key}`);
        }

        sentPerTarget.set(key, delivery);
    }

    const frozenStates = ['frozen', 'queued', 'sent', 'failed', 'skipped'];

    if (brief.delivery_state === 'draft' && brief.deliveries.length > 0) {
        problems.push('a draft brief cannot have deliveries');
    }

    if (frozenStates.includes(brief.delivery_state) && brief.model_provenance.provider === '') {
        problems.push('frozen brief without provenance');
    }

    if (problems.length > 0) {
        fail(subject, problems.join('\n      '));

        return false;
    }

    return true;
}

function listJsonFiles(dir) {
    let entries;

    try {
        entries = readdirSync(dir, { withFileTypes: true });
    } catch (error) {
        if (error.code === 'ENOENT') {
            return [];
        }

        throw error;
    }

    return entries
        .filter((e) => e.isFile() && e.name.endsWith('.json'))
        .map((e) => join(dir, e.name))
        .sort();
}

for (const suite of suites) {
    const schemaSubject = rel(suite.schema);
    let schema;

    try {
        schema = readJson(suite.schema);
    } catch (error) {
        fail(schemaSubject, `unreadable JSON: ${error.message}`);
        continue;
    }

    if (! ajv.validateSchema(schema)) {
        fail(schemaSubject, `not a valid draft 2020-12 schema:\n      ${formatErrors(ajv.errors)}`);
        continue;
    }

    let validate;

    try {
        validate = ajv.compile(schema);
    } catch (error) {
        fail(schemaSubject, `does not compile: ${error.message}`);
        continue;
    }

    pass(schemaSubject, 'valid draft 2020-12 schema, compiles in strict mode');

    const fixtures = listJsonFiles(suite.fixturesDir);

    if (fixtures.length === 0) {
        fail(rel(suite.fixturesDir), 'no fixtures found; every schema must ship at least one validated example');
        continue;
    }

    for (const fixture of fixtures) {
        const subject = rel(fixture);
        let document;

        try {
            document = readJson(fixture);
        } catch (error) {
            fail(subject, `unreadable JSON: ${error.message}`);
            continue;
        }

        if (! validate(document)) {
            fail(subject, formatErrors(validate.errors));
            continue;
        }

        let extraOk = true;

        for (const check of suite.extraChecks) {
            extraOk = check(subject, document) && extraOk;
        }

        if (extraOk) {
            pass(subject);
        }
    }
}

console.log('');
console.log(`${validated} passed, ${failures} failed`);

process.exit(failures === 0 ? 0 : 1);
