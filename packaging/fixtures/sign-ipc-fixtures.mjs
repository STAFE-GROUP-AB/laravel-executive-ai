#!/usr/bin/env node
// Re-signs every packaging/fixtures/ipc/*.json exchange with the documented test key after an edit
// (docs/architecture/ipc-contract.md §4.6). Keeps client, session id and expiry of the existing
// Authorization token and X-Lxai-Elevation header; recomputes their MACs, the request signature and
// the response signature. Run: `node packaging/fixtures/sign-ipc-fixtures.mjs`, then the validator.
//
// Not a CI step. The validator recomputes the same values and fails if they drift.

import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    TEST_KEY,
    UNSIGNED_ERROR_CODES,
    bodyBytes,
    makeElevation,
    makeToken,
    parseElevation,
    parseToken,
    signRequest,
    signResponse,
    signingKey,
} from './ipc-signing.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const fixturesDir = join(here, 'ipc');
const repoRoot = join(here, '..', '..');

const files = readdirSync(fixturesDir)
    .filter((name) => name.endsWith('.json'))
    .map((name) => join(fixturesDir, name))
    .sort();

for (const file of files) {
    const exchange = JSON.parse(readFileSync(file, 'utf8'));
    const { request, response } = exchange;
    const token = parseToken(request.headers.Authorization.replace(/^Bearer /, ''));

    if (! token) {
        console.error(`skip  ${relative(repoRoot, file)}: Authorization is not a parseable lxai1 token`);
        continue;
    }

    request.headers.Authorization = `Bearer ${makeToken(TEST_KEY, token.client, token.sessionId, token.expiresAt)}`;

    const key = signingKey(TEST_KEY, token.client, token.sessionId);

    if (request.headers['X-Lxai-Elevation']) {
        const elevation = parseElevation(request.headers['X-Lxai-Elevation']);

        request.headers['X-Lxai-Elevation'] = makeElevation(
            TEST_KEY,
            token.sessionId,
            elevation.operation,
            request.headers['X-Lxai-Nonce'],
            elevation.expiresAt,
        );
    }

    const errorCode = response.body?.ok === false ? response.body.error.code : null;

    // A signature_invalid fixture must keep its deliberately wrong request signature.
    if (errorCode !== 'signature_invalid') {
        request.headers['X-Lxai-Signature'] = signRequest(
            key,
            request.method,
            request.path,
            request.headers['X-Lxai-Timestamp'],
            request.headers['X-Lxai-Nonce'],
            bodyBytes(request.body),
        );
    }

    if (errorCode && UNSIGNED_ERROR_CODES.has(errorCode)) {
        delete response.headers['X-Lxai-Signature'];
    } else {
        response.headers['X-Lxai-Signature'] = signResponse(
            key,
            response.status,
            request.headers['X-Lxai-Nonce'],
            bodyBytes(response.body),
        );
    }

    writeFileSync(file, `${JSON.stringify(exchange, null, 2)}\n`);
    console.log(`signed  ${relative(repoRoot, file)}`);
}
