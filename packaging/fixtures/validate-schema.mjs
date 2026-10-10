#!/usr/bin/env node
// Validates the frozen contracts (S0-13 brief schema, S0-14 health contract, S0-15 IPC contract,
// S0-18 CRM fixture pack, S0-19 security corpora, S0-20 install-timer record, S1-06 runtime
// manifest) and every committed fixture against them. CI step:
// `node packaging/fixtures/validate-schema.mjs` (see S0-05).
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
import { briefContentHash, canonicalize } from './lib/jcs.mjs';
import { toolsSchemaHash } from './lib/mcp-tools.mjs';
import { validateManifest as validateRuntimeDirectoryManifest } from '../runtime/lib/manifest.mjs';

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
        // S1-06 runtime release manifest (ADR-001 D4). The only JSON directly under
        // packaging/manifest/ is runtime.json; its `package` member is the per-directory
        // manifest the Mac app's PackageVerifier parses.
        schema: join(here, '..', 'manifest', 'runtime.schema.json'),
        fixturesDir: join(here, '..', 'manifest'),
        mode: 'each',
        extraChecks: [checkRuntimeManifest],
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
        // commitments[].brief_item_id must resolve to an item of a brief validated above
        // (briefs/ and the brief embedded in the ipc briefs.latest exchange both count).
        schema: join(here, 'crm', 'crm.schema.json'),
        fixturesDir: join(here, 'crm'),
        mode: 'pack',
        extraChecks: [checkCrmReferentialIntegrity],
    },
    {
        // S0-19 prompt-injection corpus. After the CRM pack, because every case's `context.ref`
        // must resolve into the S0-18 world.
        schema: join(here, 'security', 'prompt-injection', 'injection.schema.json'),
        fixturesDir: join(here, 'security', 'prompt-injection'),
        mode: 'pack',
        extraChecks: [checkInjectionCorpus],
    },
    {
        // Last: hostile-mcp.schema.json $refs the injection schema's effect vocabulary by $id, and
        // checkHostileMcpCorpus resolves the tool names the injection corpus referenced.
        schema: join(here, 'security', 'hostile-mcp', 'hostile-mcp.schema.json'),
        fixturesDir: join(here, 'security', 'hostile-mcp'),
        mode: 'pack',
        extraChecks: [checkHostileMcpCorpus],
    },
];

// The frozen fixture clock (packaging/fixtures/README.md "Fixture clock"). Security payloads must
// arrive at or before it, so a test can place them on the timeline without reading the wall clock.
const FIXTURE_NOW = Date.parse('2026-10-09T12:00:00+02:00');

// ADR-005 D4.10: the only tools we expose ourselves. A T0 name may be referenced by the injection
// corpus without a hostile-MCP fixture defining it.
const BUILTIN_TOOLS = ['brief.read', 'crm.search', 'tasks.list'];

const ajv = new Ajv2020({ strict: true, allErrors: true, allowUnionTypes: true });
addFormats(ajv);

let failures = 0;
let validated = 0;

// Item ids seen in validated briefs; CRM commitments may point at them (brief_item_id).
const knownBriefItemIds = new Set();

// Collected while the earlier suites run, consumed by the S0-19 security suites.
const knownBriefIds = new Set();
const knownCrmIds = new Map();
const knownAccountIds = new Set();

// Every id in the security corpora must be unique across both of them: id -> where it was seen.
const securityCaseIds = new Map();

// MCP tool names the injection corpus referenced, resolved once the hostile-MCP pack is read.
const referencedMcpTools = new Map();

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
    knownBriefIds.add(brief.id);

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

// S1-06 / ADR-001 D1, D4, ADR-007 D2: the pinned component set stays inside the curated list, and
// `package` (when built) is a directory manifest the Mac app's PackageVerifier would accept —
// re-checked with the same rules the assembler used to write it.
const FORBIDDEN_PHP_EXTENSIONS = ['gmp', 'gettext', 'readline', 'imagick', 'ldap', 'pgsql', 'pdo_pgsql', 'mysqli', 'pdo_mysql', 'memcached', 'amqp', 'ssh2', 'parallel', 'xlswriter', 'imap', 'ffi'];

function checkRuntimeManifest(subject, document) {
    const problems = [];
    const extensions = document.components.php_extensions;

    if (extensions.join(',') !== [...extensions].sort().join(',')) {
        problems.push('components.php_extensions must be sorted');
    }

    for (const extension of extensions) {
        if (FORBIDDEN_PHP_EXTENSIONS.includes(extension)) {
            problems.push(`components.php_extensions contains ${extension}, which is outside ADR-001 D1 / ADR-007 D2 (gmp is LGPL, gettext/GNU readline are GPL/LGPL, the rest need a legal review)`);
        }
    }

    for (const module of document.components.caddy_modules) {
        if (/mercure|vulcain/.test(module)) {
            problems.push(`components.caddy_modules links ${module} (AGPL-3.0)`);
        }
    }

    for (const required of ['github.com/dunglas/mercure/caddy', 'github.com/dunglas/vulcain/caddy']) {
        if (! document.components.caddy_modules_excluded.includes(required)) {
            problems.push(`components.caddy_modules_excluded must list ${required}`);
        }
    }

    const { preferred_port: preferred, fallback_ports: fallback } = document.http;

    if (fallback.from > fallback.to) {
        problems.push('http.fallback_ports.from must not exceed .to');
    }

    if (preferred >= fallback.from && preferred <= fallback.to) {
        problems.push('http.preferred_port must lie outside the fallback range');
    }

    if (document.package !== null) {
        try {
            validateRuntimeDirectoryManifest(document.package);

            if (document.package.runtime_version !== document.runtime_version) {
                problems.push(`package.runtime_version ${document.package.runtime_version} differs from runtime_version ${document.runtime_version}`);
            }

            if (document.package.executable !== 'frankenphp') {
                problems.push('package.executable must be frankenphp (ADR-001 D4)');
            }

            const paths = new Set(document.package.files.map((entry) => entry.path));

            for (const required of ['frankenphp', 'Caddyfile', 'php.ini', 'app/public/index.php', 'app/artisan']) {
                if (! paths.has(required)) {
                    problems.push(`package.files must list ${required}`);
                }
            }

            if ([...paths].some((path) => /(^|\/)\.env(\..*)?$|^app\/bootstrap\/cache\/(config|routes-v7|events)\.php$|\.sqlite/.test(path))) {
                problems.push('package.files lists a .env, an environment-derived cache or a database file');
            }
        } catch (error) {
            problems.push(`package is not a valid directory manifest: ${error.message}`);
        }
    }

    if (problems.length > 0) {
        fail(subject, problems.join('\n      '));

        return false;
    }

    const state = document.package === null ? 'unbuilt, no package yet' : `${document.package.files.length} files pinned`;
    console.log(`      runtime ${document.runtime_version}: FrankenPHP ${document.components.frankenphp.version}, PHP ${document.components.php.version}, Caddy ${document.components.caddy.version}, ${extensions.length} extensions, ${state}`);

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
            knownCrmIds.set(record.id, file);

            if (record.account_id) {
                knownAccountIds.add(record.account_id);
            }

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

// ---------------------------------------------------------------------------------------------
// S0-19 security corpora
// ---------------------------------------------------------------------------------------------

// No real hostnames, no real addresses, no real paths (S0-19 rule, README "No real data"). Only
// example.* / *.example domains, the RFC 5737 documentation IP ranges, loopback and the
// link-local metadata address the SSRF fixtures have to name.
const SAFE_DOMAIN = /(^|\.)example(\.[a-z]{2,3})?$/i;
const SAFE_LITERAL_HOSTS = new Set(['localhost', '::1']);
const SAFE_IP_PREFIXES = ['127.', '169.254.', '192.0.2.', '198.51.100.', '203.0.113.'];
const FORBIDDEN_SUBSTRINGS = ['/Users/', '/home/', '.ts.net'];

function hostIsFixtureSafe(host) {
    const bare = host.replace(/^\[|\]$/g, '').replace(/:\d+$/, '').toLowerCase();

    if (SAFE_LITERAL_HOSTS.has(bare)) {
        return true;
    }

    if (SAFE_IP_PREFIXES.some((prefix) => bare.startsWith(prefix))) {
        return true;
    }

    return SAFE_DOMAIN.test(bare);
}

// `hosts: false` skips the host checks for a homoglyph payload: its lookalike domain is deliberately
// not ASCII, so an ASCII host pattern truncates it at the confusable character and reports a
// non-existent domain. The forbidden-substring checks still run.
function checkFixtureSafety(problems, where, text, { decodeBase64 = false, hosts = true } = {}) {
    for (const forbidden of FORBIDDEN_SUBSTRINGS) {
        if (text.includes(forbidden)) {
            problems.push(`${where}: contains "${forbidden}"; fixtures carry no real paths or tailnet names`);
        }
    }

    if (! hosts) {
        return;
    }

    for (const [, domain] of text.matchAll(/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/g)) {
        if (! hostIsFixtureSafe(domain)) {
            problems.push(`${where}: email domain ${domain} is not a fixture domain (example.* only)`);
        }
    }

    for (const [, host] of text.matchAll(/\bhttps?:\/\/([^\s/"'<>)\]\\]+)/gi)) {
        if (! hostIsFixtureSafe(host)) {
            problems.push(`${where}: URL host ${host} is not a fixture host (example.* only)`);
        }
    }

    for (const [ip] of text.matchAll(/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/g)) {
        if (! hostIsFixtureSafe(ip)) {
            problems.push(`${where}: bare IP ${ip} is outside loopback, link-local and the RFC 5737 documentation ranges`);
        }
    }

    if (! decodeBase64) {
        return;
    }

    // A payload may hide its instruction in base64; what it decodes to must be fixture-safe too.
    for (const [token] of text.matchAll(/[A-Za-z0-9+/]{32,}={0,2}/g)) {
        const decoded = Buffer.from(token, 'base64').toString('utf8');

        if (! /^[\t\n\r\u0020-\u007e\u00a0-\uffff]*$/.test(decoded)) {
            continue;
        }

        checkFixtureSafety(problems, `${where} (base64 decoded)`, decoded);
    }
}

function registerSecurityId(problems, id, where) {
    if (securityCaseIds.has(id)) {
        problems.push(`${where}: id ${id} is also used by ${securityCaseIds.get(id)}; security corpus ids are unique across both corpora`);

        return;
    }

    securityCaseIds.set(id, where);
}

// Blueprint §4.2 requires sv, en, it and de. These carrier/attack-class combinations are the ones
// issue #19 names explicitly, so each must exist in all four languages; every corpus file must
// additionally contain all four languages somewhere.
const REQUIRED_LANGUAGE_COVERAGE = [
    ['email_body', 'instruction_override'],
    ['email_body', 'exfiltration'],
    ['email_body', 'identity_spoof'],
    ['email_body', 'privilege_escalation'],
    ['mcp_tool_description', 'tool_poisoning'],
    ['web_content', 'instruction_override'],
];

const CARRIERS_BY_FILE = {
    'email-body': ['email_body', 'email_subject', 'email_attachment_name'],
    'mcp-tool-description': ['mcp_tool_description', 'mcp_tool_result'],
    'web-content': ['web_content'],
    'calendar-and-crm-notes': ['calendar_event', 'crm_note', 'crm_task_title'],
    'screen-text': ['screen_text'],
};

function checkInjectionCorpus(subject, pack) {
    const problems = [];
    const schema = readJson(join(here, 'security', 'prompt-injection', 'injection.schema.json'));
    const caseSchema = schema.$defs.case.properties;
    const allLanguages = caseSchema.language.enum.filter((l) => l !== 'n/a');
    const seen = { attack_class: new Set(), encoding: new Set(), language: new Set() };
    const coverage = new Map();

    const refResolves = {
        crm_communication: (ref) => knownCrmIds.get(ref) === 'communications',
        crm_task: (ref) => knownCrmIds.get(ref) === 'tasks',
        crm_contact: (ref) => knownCrmIds.get(ref) === 'contacts',
        crm_customer: (ref) => knownCrmIds.get(ref) === 'customers',
        brief: (ref) => knownBriefIds.has(ref),
        brief_item: (ref) => knownBriefItemIds.has(ref),
        mcp_tool: () => true,
        web_page: (ref) => /^https:\/\/\S+$/.test(ref),
        calendar_event: (ref) => /^cal-[a-z0-9-]+$/.test(ref),
        screen_region: (ref) => /^screen-[a-z0-9-]+$/.test(ref),
    };

    for (const [file, cases] of Object.entries(pack)) {
        const languagesInFile = new Set();

        for (const testCase of cases) {
            const where = `${file} ${testCase.id}`;

            registerSecurityId(problems, testCase.id, where);

            if (! (CARRIERS_BY_FILE[file] ?? []).includes(testCase.carrier)) {
                problems.push(`${where}: carrier ${testCase.carrier} does not belong in ${file}.json (allowed: ${(CARRIERS_BY_FILE[file] ?? []).join(', ')})`);
            }

            seen.attack_class.add(testCase.attack_class);
            seen.language.add(testCase.language);
            testCase.encoding.forEach((e) => seen.encoding.add(e));
            languagesInFile.add(testCase.language);

            const key = `${testCase.carrier}|${testCase.attack_class}`;
            coverage.set(key, (coverage.get(key) ?? new Set()).add(testCase.language));

            if (testCase.encoding.length > 1 && testCase.encoding.includes('plain')) {
                problems.push(`${where}: "plain" cannot be combined with an obfuscation`);
            }

            if (testCase.expected.must_not.length === 0) {
                problems.push(`${where}: expected.must_not is empty; a case without a forbidden effect is not an attack`);
            }

            if (Date.parse(testCase.context.arrives_at) > FIXTURE_NOW) {
                problems.push(`${where}: context.arrives_at is later than FIXTURE_NOW (2026-10-09T12:00:00+02:00)`);
            }

            const { rides_on: ridesOn, ref } = testCase.context;

            if (! refResolves[ridesOn](ref)) {
                problems.push(`${where}: context.ref ${ref} does not resolve as ${ridesOn} in the S0-18 fixture pack`);
            }

            if (ridesOn === 'mcp_tool') {
                referencedMcpTools.set(ref, where);
            }

            if (testCase.context.account_id !== null && ! knownAccountIds.has(testCase.context.account_id)) {
                problems.push(`${where}: context.account_id ${testCase.context.account_id} is not an account used by the S0-18 CRM pack`);
            }

            checkFixtureSafety(problems, `${where} payload`, testCase.payload, {
                decodeBase64: testCase.encoding.includes('base64'),
                hosts: ! testCase.encoding.includes('homoglyph'),
            });
            checkFixtureSafety(problems, `${where} notes`, `${testCase.title}\n${testCase.notes}\n${ref}`, {
                hosts: ! testCase.encoding.includes('homoglyph'),
            });
        }

        for (const language of allLanguages) {
            if (! languagesInFile.has(language)) {
                problems.push(`${file}.json: no case in ${language}; §4.2 requires sv, en, it and de in every carrier file`);
            }
        }
    }

    for (const [carrier, attackClass] of REQUIRED_LANGUAGE_COVERAGE) {
        const present = coverage.get(`${carrier}|${attackClass}`) ?? new Set();

        for (const language of allLanguages) {
            if (! present.has(language)) {
                problems.push(`${carrier} / ${attackClass}: missing a ${language} case (issue #19: in all four languages where language matters)`);
            }
        }
    }

    for (const [field, values] of Object.entries(seen)) {
        for (const value of caseSchema[field].enum ?? caseSchema[field].items.enum) {
            if (! values.has(value)) {
                problems.push(`no case exercises ${field} "${value}"; every value in the schema enum must be covered or removed`);
            }
        }
    }

    if (problems.length > 0) {
        fail(subject, problems.join('\n      '));

        return false;
    }

    console.log(`      ${Object.values(pack).flat().length} injection cases: carriers, languages, encodings, context refs and expected refusals checked`);

    return true;
}

// Hostile MCP servers (§7, §11, §14; ADR-005 D4). The schema checks the JSON-RPC and expectation
// shapes; this checks that each recording really contains the attack it claims, that the required
// three families are present, and that every tools/list snapshot hashes to its recorded
// schema_hash.
function checkHostileMcpCorpus(subject, pack) {
    const problems = [];
    const families = new Set();
    const toolNames = new Set(BUILTIN_TOOLS);

    for (const [file, fixture] of Object.entries(pack)) {
        const { server, exchanges } = fixture;
        const where = `${file} ${server.name}`;

        registerSecurityId(problems, server.id, `${where} (server)`);
        families.add(server.attack_family);
        checkFixtureSafety(problems, where, JSON.stringify(fixture));

        if (server.transport === 'streamable-http' && ! server.endpoint.startsWith('https://')) {
            problems.push(`${where}: a streamable-http fixture endpoint must be https (ADR-005 D4.2a)`);
        }

        if (server.transport === 'stdio' && ! server.endpoint.startsWith('bundle:')) {
            problems.push(`${where}: a stdio fixture endpoint must be a bundle: path, never a real filesystem path`);
        }

        const actions = new Set();
        const toolsListSnapshots = [];
        let previousStep = 0;

        for (const exchange of exchanges) {
            const at = `${where} step ${exchange.step}`;

            registerSecurityId(problems, exchange.id, at);

            if (exchange.step <= previousStep) {
                problems.push(`${at}: step numbers must increase strictly within a file`);
            }

            previousStep = exchange.step;
            actions.add(exchange.expected.registry_action);

            if (exchange.request === null && exchange.response === null) {
                problems.push(`${at}: an exchange with neither a request nor a response records nothing`);
            }

            if (exchange.request?.id !== undefined && exchange.response !== null && exchange.response.id !== exchange.request.id) {
                problems.push(`${at}: JSON-RPC response id ${exchange.response.id} does not match request id ${exchange.request.id}`);
            }

            if (exchange.expected.registry_action === 'allow_and_record' && exchange.observed.mutations.length > 0) {
                problems.push(`${at}: an exchange with observed mutations cannot be allow_and_record`);
            }

            const status = exchange.transport.http_status;

            if (status >= 300 && status < 400) {
                if (exchange.expected.registry_action !== 'block') {
                    problems.push(`${at}: a ${status} must be blocked, not ${exchange.expected.registry_action} (ADR-005 D4.2c: any 3xx is an error)`);
                }

                if (! exchange.expected.must_not.includes('redirect_followed')) {
                    problems.push(`${at}: a ${status} exchange must forbid redirect_followed`);
                }

                if (exchange.transport.headers.Location === undefined) {
                    problems.push(`${at}: a ${status} exchange must record the Location header the client refused`);
                }
            }

            if (server.transport === 'stdio' && status !== 0) {
                problems.push(`${at}: a stdio exchange has no HTTP status; use 0`);
            }

            const { resolved_ip: checked, resolved_ip_at_use: atUse } = exchange.transport;

            if (checked && atUse && checked !== atUse) {
                if (! exchange.expected.must.includes('resolved_ip_pinned')) {
                    problems.push(`${at}: the resolved IP changed between check and use, so expected.must needs resolved_ip_pinned`);
                }

                if (! exchange.expected.must_not.includes('dns_rebind_accepted')) {
                    problems.push(`${at}: the resolved IP changed between check and use, so expected.must_not needs dns_rebind_accepted`);
                }
            }

            if (exchange.transport.body_elided && exchange.transport.declared_bytes === null && exchange.transport.nesting_depth === null && exchange.transport.elapsed_ms === null) {
                problems.push(`${at}: body_elided needs declared_bytes, nesting_depth or elapsed_ms so a test can synthesise the body`);
            }

            for (const tool of exchange.response?.result?.tools ?? []) {
                toolNames.add(tool.name);
            }

            if (exchange.request?.method === 'tools/list' && Array.isArray(exchange.response?.result?.tools)) {
                const expectedHash = toolsSchemaHash(exchange.response.result.tools);

                if (exchange.schema_hash !== expectedHash) {
                    problems.push(`${at}: schema_hash mismatch: fixture ${exchange.schema_hash}, tools hash to ${expectedHash} (run tools/hash-mcp-tools.mjs --write)`);
                }

                if (! exchange.expected.must.includes('schema_hash_recorded')) {
                    problems.push(`${at}: a tools/list exchange must require schema_hash_recorded (ADR-005 D4.6)`);
                }

                toolsListSnapshots.push(canonicalize(exchange.response.result.tools));
            } else if (exchange.schema_hash !== undefined) {
                problems.push(`${at}: schema_hash belongs only on a tools/list exchange that returned tools`);
            }
        }

        if (! actions.has('quarantine') && ! actions.has('block') && ! actions.has('require_review')) {
            problems.push(`${where}: no exchange reaches quarantine, block or require_review, so the fixture records no refusal`);
        }

        if (server.attack_family === 'schema_mutation' && new Set(toolsListSnapshots).size < 2) {
            problems.push(`${where}: a schema_mutation fixture needs at least two tools/list snapshots whose canonical tool JSON differs, found ${new Set(toolsListSnapshots).size}`);
        }
    }

    for (const required of ['capability_lie', 'schema_mutation', 'unexpected_redirect']) {
        if (! families.has(required)) {
            problems.push(`issue #19 requires a ${required} server fixture; none found`);
        }
    }

    for (const [tool, origin] of referencedMcpTools) {
        if (! toolNames.has(tool)) {
            problems.push(`${origin}: context.ref ${tool} is neither a built-in T0 tool nor advertised by any hostile-MCP fixture`);
        }
    }

    if (problems.length > 0) {
        fail(subject, problems.join('\n      '));

        return false;
    }

    const exchangeCount = Object.values(pack).reduce((total, fixture) => total + fixture.exchanges.length, 0);

    console.log(`      ${Object.keys(pack).length} hostile servers in ${families.size} attack families, ${exchangeCount} exchanges: JSON-RPC pairing, redirects, schema hashes and expected refusals checked`);

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
