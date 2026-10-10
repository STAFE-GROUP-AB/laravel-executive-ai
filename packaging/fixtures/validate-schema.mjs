#!/usr/bin/env node
// Validates the frozen contracts (S0-13 brief schema, S0-14 health contract, S0-15 IPC contract,
// S0-18 CRM fixture pack, S0-20 install-timer record) and every committed fixture against them.
// CI step: `node packaging/fixtures/validate-schema.mjs` (see S0-05).
//
// Exit code 0 when everything passes, 1 on any failure. No network access.

import { readdirSync, readFileSync } from 'node:fs';
import { basename, dirname, join, relative } from 'node:path';
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
import { briefContentHash } from './lib/jcs.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const rel = (path) => relative(repoRoot, path);

// mode "each": every fixture file is one document validated against the schema.
// mode "pack": all fixture files together form one document keyed by file basename (without
// .json), so cross-file references can be checked. Schema files inside the fixture directory are
// skipped by name.
const suites = [
    {
        schema: join(here, 'brief.schema.json'),
        fixturesDir: join(here, 'briefs'),
        mode: 'each',
        extraChecks: [checkBriefContentHash, checkBriefReferentialIntegrity],
    },
    {
        schema: join(here, 'health.schema.json'),
        fixturesDir: join(here, 'health'),
        mode: 'each',
        extraChecks: [],
    },
    {
        // S0-20 install-timer record (the tool also validates with its own dependency-free
        // validator; this ajv pass catches drift between the two).
        schema: join(here, '..', 'manifest', 'install-timer', 'record.schema.json'),
        fixturesDir: join(here, '..', 'manifest', 'install-timer', 'results', 'example'),
        mode: 'each',
        extraChecks: [checkInstallTimerRecordIsSynthetic],
    },
    {
        schema: join(here, 'ipc.schema.json'),
        fixturesDir: join(here, 'ipc'),
        // ipc.schema.json $refs the brief and health schemas by $id; they are registered when the
        // suites above compile, so this suite must come after them.
        mode: 'each',
        extraChecks: [checkIpcSignatures, checkIpcEmbeddedBrief],
    },
    {
        // Last: commitments[].brief_item_id must resolve to an item of a brief validated above
        // (briefs/ and the brief embedded in the ipc briefs.latest exchange both count).
        schema: join(here, 'crm', 'crm.schema.json'),
        fixturesDir: join(here, 'crm'),
        mode: 'pack',
        extraChecks: [checkCrmReferentialIntegrity],
    },
];

const ajv = new Ajv2020({ strict: true, allErrors: true, allowUnionTypes: true });
addFormats(ajv);

let failures = 0;
let validated = 0;

// Item ids seen in validated briefs; CRM commitments may point at them (brief_item_id).
const knownBriefItemIds = new Set();

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

function checkBriefContentHash(subject, brief) {
    const digest = briefContentHash(brief);

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
    const dedupeKeys = new Set();
    const problems = [];

    if (brief.sources.length !== sourceIds.size) {
        problems.push('duplicate source ids');
    }

    for (const item of brief.items) {
        if (itemIds.has(item.id)) {
            problems.push(`duplicate item id ${item.id}`);
        }

        itemIds.add(item.id);
        knownBriefItemIds.add(item.id);

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

        if (item.dedupe_key !== undefined) {
            if (dedupeKeys.has(item.dedupe_key)) {
                problems.push(`two items share dedupe_key ${item.dedupe_key} (§4.2: one matter, one item)`);
            }

            dedupeKeys.add(item.dedupe_key);
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

// Referential integrity and pack invariants for packaging/fixtures/crm (blueprint §3.1, §3.2,
// §5.6; ADR-002 D9). The schema checks shapes; this checks that the files describe one world.
function checkCrmReferentialIntegrity(subject, pack) {
    const problems = [];
    const byId = new Map();
    const index = (name) => new Map(pack[name].map((r) => [r.id, r]));

    for (const [file, records] of Object.entries(pack)) {
        for (const record of records) {
            if (byId.has(record.id)) {
                problems.push(`${file}: id ${record.id} is also used in ${byId.get(record.id)}`);
            }

            byId.set(record.id, file);

            if (Date.parse(record.updated_at) < Date.parse(record.created_at)) {
                problems.push(`${file} ${record.id}: updated_at is before created_at`);
            }

            if (record.deleted_at !== null && Date.parse(record.deleted_at) < Date.parse(record.created_at)) {
                problems.push(`${file} ${record.id}: deleted_at is before created_at`);
            }
        }
    }

    const organizationIds = new Set(Object.values(pack).flat().map((r) => r.organization_id));

    if (organizationIds.size !== 1) {
        problems.push(`the pack must describe exactly one organisation, found ${[...organizationIds].join(', ')}`);
    }

    const customers = index('customers');
    const contacts = index('contacts');
    const projects = index('projects');
    const tasks = index('tasks');
    const communications = index('communications');

    const requireRef = (file, record, field, target, targetName) => {
        const value = record[field];

        if (value === null || value === undefined) {
            return;
        }

        if (! target.has(value)) {
            problems.push(`${file} ${record.id}: ${field} ${value} does not exist in ${targetName}`);
        }
    };

    const requireRefs = (file, record, field, target, targetName) => {
        for (const value of record[field] ?? []) {
            if (! target.has(value)) {
                problems.push(`${file} ${record.id}: ${field} entry ${value} does not exist in ${targetName}`);
            }
        }
    };

    const contactBelongsTo = (contactId, customerId) => contacts.get(contactId)?.customer_ids.includes(customerId) ?? false;

    const seenAddresses = new Map();

    for (const contact of pack.contacts) {
        requireRefs('contacts', contact, 'customer_ids', customers, 'customers');

        const primaries = contact.emails.filter((e) => e.primary).length;

        if (primaries !== 1) {
            problems.push(`contacts ${contact.id}: exactly one primary email required, found ${primaries}`);
        }

        for (const { address } of contact.emails) {
            if (seenAddresses.has(address)) {
                problems.push(`contacts ${contact.id}: address ${address} is also on contact ${seenAddresses.get(address)}`);
            }

            seenAddresses.set(address, contact.id);
        }
    }

    for (const project of pack.projects) {
        requireRef('projects', project, 'customer_id', customers, 'customers');
        requireRefs('projects', project, 'contact_ids', contacts, 'contacts');

        for (const contactId of project.contact_ids) {
            if (contacts.has(contactId) && ! contactBelongsTo(contactId, project.customer_id)) {
                problems.push(`projects ${project.id}: contact ${contactId} does not belong to customer ${project.customer_id}`);
            }
        }

        if (project.starts_on !== null && project.due_on !== null && project.due_on < project.starts_on) {
            problems.push(`projects ${project.id}: due_on is before starts_on`);
        }
    }

    for (const task of pack.tasks) {
        requireRef('tasks', task, 'customer_id', customers, 'customers');
        requireRef('tasks', task, 'project_id', projects, 'projects');
        requireRef('tasks', task, 'contact_id', contacts, 'contacts');

        const project = task.project_id === null ? null : projects.get(task.project_id);

        if (project && task.customer_id !== null && project.customer_id !== task.customer_id) {
            problems.push(`tasks ${task.id}: project ${task.project_id} belongs to another customer than customer_id`);
        }

        if (task.contact_id !== null && task.customer_id !== null && contacts.has(task.contact_id) && ! contactBelongsTo(task.contact_id, task.customer_id)) {
            problems.push(`tasks ${task.id}: contact ${task.contact_id} does not belong to customer ${task.customer_id}`);
        }
    }

    for (const communication of pack.communications) {
        requireRef('communications', communication, 'customer_id', customers, 'customers');
        requireRef('communications', communication, 'contact_id', contacts, 'contacts');

        if (communication.customer_id === null && communication.contact_id === null) {
            problems.push(`communications ${communication.id}: a timeline row links to a customer or a contact (ADR-002 D9)`);
        }

        if (communication.customer_id !== null && communication.contact_id !== null && contacts.has(communication.contact_id) && ! contactBelongsTo(communication.contact_id, communication.customer_id)) {
            problems.push(`communications ${communication.id}: contact ${communication.contact_id} does not belong to customer ${communication.customer_id}`);
        }

        if (communication.account_id !== null && communication.account_label === null) {
            problems.push(`communications ${communication.id}: account_label is required when account_id is set (§5.6)`);
        }

        if (communication.correction_of !== null) {
            if (communication.matched_by !== 'user') {
                problems.push(`communications ${communication.id}: correction_of implies matched_by user`);
            }

            requireRef('communications', communication.correction_of, 'customer_id', customers, 'customers');
        }
    }

    for (const commitment of pack.commitments) {
        requireRef('commitments', commitment, 'communication_id', communications, 'communications');
        requireRef('commitments', commitment, 'customer_id', customers, 'customers');
        requireRef('commitments', commitment, 'contact_id', contacts, 'contacts');
        requireRef('commitments', commitment, 'follow_up_task_id', tasks, 'tasks');

        const communication = communications.get(commitment.communication_id);

        if (communication && communication.customer_id !== null && communication.customer_id !== commitment.customer_id) {
            problems.push(`commitments ${commitment.id}: communication ${commitment.communication_id} is linked to another customer`);
        }

        if (commitment.brief_item_id !== null && ! knownBriefItemIds.has(commitment.brief_item_id)) {
            problems.push(`commitments ${commitment.id}: brief_item_id ${commitment.brief_item_id} is not an item of any brief fixture`);
        }

        if (commitment.due_at !== null && Date.parse(commitment.due_at) < Date.parse(commitment.promised_at)) {
            problems.push(`commitments ${commitment.id}: due_at is before promised_at`);
        }
    }

    // §5.6: the pack must keep at least one email domain that belongs to more than one customer,
    // so explainable-matching tests always have something to fail against. Do not "fix" this.
    const customersPerDomain = new Map();

    for (const customer of pack.customers) {
        for (const domain of customer.email_domains) {
            customersPerDomain.set(domain, (customersPerDomain.get(domain) ?? 0) + 1);
        }
    }

    if (! [...customersPerDomain.values()].some((count) => count >= 2)) {
        problems.push('no email domain is shared by two customers; §5.6 fixtures require one');
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
        .filter((e) => e.isFile() && e.name.endsWith('.json') && ! e.name.endsWith('.schema.json'))
        .map((e) => join(dir, e.name))
        .sort();
}

function validateDocument(subject, document, validate, extraChecks) {
    if (! validate(document)) {
        fail(subject, formatErrors(validate.errors));

        return;
    }

    let extraOk = true;

    for (const check of extraChecks) {
        extraOk = check(subject, document) && extraOk;
    }

    if (extraOk) {
        pass(subject);
    }
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

    if (suite.mode === 'pack') {
        const subject = `${rel(suite.fixturesDir)}/*.json`;
        const pack = {};
        let readable = true;

        for (const fixture of fixtures) {
            try {
                pack[basename(fixture, '.json')] = readJson(fixture);
            } catch (error) {
                fail(rel(fixture), `unreadable JSON: ${error.message}`);
                readable = false;
            }
        }

        if (readable) {
            validateDocument(subject, pack, validate, suite.extraChecks);
        }

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

        validateDocument(subject, document, validate, suite.extraChecks);
    }
}

console.log('');
console.log(`${validated} passed, ${failures} failed`);

process.exit(failures === 0 ? 0 : 1);
