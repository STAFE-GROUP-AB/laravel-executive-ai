#!/usr/bin/env node
// Validates the frozen contracts (S0-13 brief schema, S0-14 health contract, S0-15 IPC contract,
// S0-20 install-timer record) and every committed fixture against them. CI step:
// `node packaging/fixtures/validate-schema.mjs` (see S0-05).
//
// Exit code 0 when everything passes, 1 on any failure. No network access.

import { createHash } from 'node:crypto';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

import {
    TEST_KEY,
    UNSIGNED_ERROR_CODES,
    bodyBytes,
    elevationMac,
    parseElevation,
    parseToken,
    signRequest,
    signResponse,
    signingKey,
    tokenMac,
} from './ipc-signing.mjs';

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
    {
        // S0-20 install-timer record (the tool also validates with its own dependency-free
        // validator; this ajv pass catches drift between the two).
        schema: join(here, '..', 'manifest', 'install-timer', 'record.schema.json'),
        fixturesDir: join(here, '..', 'manifest', 'install-timer', 'results', 'example'),
        extraChecks: [checkInstallTimerRecordIsSynthetic],
    },
    {
        schema: join(here, 'ipc.schema.json'),
        fixturesDir: join(here, 'ipc'),
        // ipc.schema.json $refs the brief and health schemas by $id; they are registered when the
        // suites above compile, so this suite must come after them.
        extraChecks: [checkIpcSignatures, checkIpcEmbeddedBrief],
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

function checkInstallTimerRecordIsSynthetic(subject, record) {
    if (record.synthetic !== true) {
        fail(subject, 'records under results/example/ must be marked "synthetic": true; real runs belong in a campaign directory');

        return false;
    }

    if (record.async_post_core.counted_in_core_ready_ms !== false) {
        fail(subject, 'async_post_core.counted_in_core_ready_ms must be false');

        return false;
    }

    return true;
}

// IPC contract §3.3, §4.2, §4.5, §7.3: every fixture is signed with the all-zero test key (§4.6).
// Recomputing here makes this file the reference the Swift and PHP implementations must match.
function checkIpcSignatures(subject, exchange) {
    const { request, response } = exchange;
    const problems = [];
    const token = parseToken(request.headers.Authorization.replace(/^Bearer /, ''));

    if (! token) {
        fail(subject, 'Authorization is not a parseable lxai1 token');

        return false;
    }

    if (token.mac !== tokenMac(TEST_KEY, token.client, token.sessionId, token.expiresAt)) {
        problems.push('token mac does not match the test key (run sign-ipc-fixtures.mjs)');
    }

    const errorCode = response.body?.ok === false ? response.body.error.code : null;
    const key = signingKey(TEST_KEY, token.client, token.sessionId);
    const expectedRequest = signRequest(
        key,
        request.method,
        request.path,
        request.headers['X-Lxai-Timestamp'],
        request.headers['X-Lxai-Nonce'],
        bodyBytes(request.body),
    );

    if (errorCode === 'signature_invalid') {
        if (request.headers['X-Lxai-Signature'] === expectedRequest) {
            problems.push('a signature_invalid fixture must carry a request signature that does not verify');
        }
    } else if (request.headers['X-Lxai-Signature'] !== expectedRequest) {
        problems.push(`request signature mismatch: fixture ${request.headers['X-Lxai-Signature']}, expected ${expectedRequest}`);
    }

    const elevation = request.headers['X-Lxai-Elevation'];

    if (elevation) {
        const parsed = parseElevation(elevation);

        if (parsed.nonce !== request.headers['X-Lxai-Nonce']) {
            problems.push('elevation nonce must equal X-Lxai-Nonce (§7.3)');
        }

        if (parsed.mac !== elevationMac(TEST_KEY, token.sessionId, parsed.operation, parsed.nonce, parsed.expiresAt)) {
            problems.push('elevation mac does not match the test key');
        }
    }

    if (exchange.operation === 'remote-access.enable' && ! elevation && response.status === 200) {
        problems.push('remote-access.enable succeeded without X-Lxai-Elevation (§7.3)');
    }

    const responseSignature = response.headers['X-Lxai-Signature'];

    if (errorCode && UNSIGNED_ERROR_CODES.has(errorCode)) {
        if (responseSignature !== undefined) {
            problems.push(`a ${errorCode} response is emitted before a signing key exists and must not be signed (§4.5)`);
        }
    } else {
        const expectedResponse = signResponse(key, response.status, request.headers['X-Lxai-Nonce'], bodyBytes(response.body));

        if (responseSignature !== expectedResponse) {
            problems.push(`response signature mismatch: fixture ${responseSignature}, expected ${expectedResponse}`);
        }
    }

    if (response.status === 304 && request.headers['If-None-Match'] === undefined) {
        problems.push('304 without If-None-Match on the request');
    }

    if (response.headers.ETag !== undefined && exchange.operation !== 'briefs.latest') {
        problems.push('ETag is only defined for briefs.latest (§6.2)');
    }

    if (response.body?.ok === true && response.body.request_id !== response.headers['X-Lxai-Request-Id']) {
        problems.push('request_id in the body must equal X-Lxai-Request-Id');
    }

    if (problems.length > 0) {
        fail(subject, problems.join('\n      '));

        return false;
    }

    return true;
}

// briefs.latest carries a full brief: run the brief-specific checks on it as well.
function checkIpcEmbeddedBrief(subject, exchange) {
    if (exchange.operation !== 'briefs.latest' || exchange.response.body?.ok !== true) {
        return true;
    }

    const brief = exchange.response.body.data;
    const etag = exchange.response.headers.ETag;
    let ok = checkBriefContentHash(subject, brief) && checkBriefReferentialIntegrity(subject, brief);

    if (etag !== `"${brief.id}:${brief.version}"`) {
        fail(subject, `ETag must be "<id>:<version>" of the returned brief, got ${etag}`);
        ok = false;
    }

    if (brief.delivery_state === 'draft') {
        fail(subject, 'briefs.latest never returns a draft (§6.2)');
        ok = false;
    }

    return ok;
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
