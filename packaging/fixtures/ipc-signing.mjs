// Reference implementation of the IPC contract's token derivation and request/response signing
// (docs/architecture/ipc-contract.md §3.3, §4.2, §4.5, §7.3). Pure functions, no I/O.
//
// Used by validate-schema.mjs (recomputes every fixture signature) and by sign-ipc-fixtures.mjs
// (re-signs fixtures after an edit). A Swift or PHP implementation must agree with this file
// byte for byte on the fixtures in packaging/fixtures/ipc/.

import { createHash, createHmac } from 'node:crypto';

import { canonicalize } from './lib/jcs.mjs';

// The documented test key: 32 zero bytes. Never a valid production key (§4.6).
export const TEST_KEY = Buffer.alloc(32, 0);

export const LF = '\n';

export function sha256Hex(bytes) {
    return createHash('sha256').update(bytes).digest('hex');
}

export function hmacSha256(key, message) {
    return createHmac('sha256', key).update(message, 'utf8').digest();
}

// §3.3 token = "lxai1." client "." session_id "." expires_at "." mac
export function tokenMac(key, client, sessionId, expiresAt) {
    const message = ['lxai-ipc/v1/token', client, sessionId, String(expiresAt)].join(LF);

    return hmacSha256(key, message).toString('hex').slice(0, 32);
}

export function makeToken(key, client, sessionId, expiresAt) {
    return `lxai1.${client}.${sessionId}.${expiresAt}.${tokenMac(key, client, sessionId, expiresAt)}`;
}

export function parseToken(token) {
    const match = /^lxai1\.(menubar|supervisor)\.([0-7][0-9A-HJKMNP-TV-Z]{25})\.([0-9]{1,12})\.([0-9a-f]{32})$/.exec(token);

    if (! match) {
        return null;
    }

    return { client: match[1], sessionId: match[2], expiresAt: match[3], mac: match[4] };
}

// §3.3 signing_key = HMAC-SHA256(K, "lxai-ipc/v1/signing-key\n" client "\n" session_id)
export function signingKey(key, client, sessionId) {
    return hmacSha256(key, ['lxai-ipc/v1/signing-key', client, sessionId].join(LF));
}

// §4.2 canonical request string. bodyBytes is a Buffer (empty when there is no body).
export function canonicalRequest(method, pathAndQuery, timestamp, nonce, bodyBytes) {
    return ['LXAI-IPC-REQ/1', method, pathAndQuery, String(timestamp), nonce, sha256Hex(bodyBytes)].join(LF);
}

export function signRequest(signingKeyBytes, method, pathAndQuery, timestamp, nonce, bodyBytes) {
    return `v1=${hmacSha256(signingKeyBytes, canonicalRequest(method, pathAndQuery, timestamp, nonce, bodyBytes)).toString('hex')}`;
}

// §4.5 canonical response string.
export function canonicalResponse(status, requestNonce, bodyBytes) {
    return ['LXAI-IPC-RES/1', String(status), requestNonce, sha256Hex(bodyBytes)].join(LF);
}

export function signResponse(signingKeyBytes, status, requestNonce, bodyBytes) {
    return `v1=${hmacSha256(signingKeyBytes, canonicalResponse(status, requestNonce, bodyBytes)).toString('hex')}`;
}

// §7.3 elevation = "lxai1e." operation "." nonce "." expires_at "." mac
export function elevationMac(key, sessionId, operation, nonce, expiresAt) {
    const message = ['lxai-ipc/v1/elevation', sessionId, operation, nonce, String(expiresAt)].join(LF);

    return hmacSha256(key, message).toString('hex').slice(0, 32);
}

export function makeElevation(key, sessionId, operation, nonce, expiresAt) {
    return `lxai1e.${operation}.${nonce}.${expiresAt}.${elevationMac(key, sessionId, operation, nonce, expiresAt)}`;
}

export function parseElevation(elevation) {
    const match = /^lxai1e\.([a-z][a-z0-9-]*)\.([A-Za-z0-9_-]{22})\.([0-9]{1,12})\.([0-9a-f]{32})$/.exec(elevation);

    if (! match) {
        return null;
    }

    return { operation: match[1], nonce: match[2], expiresAt: match[3], mac: match[4] };
}

// RFC 8785 (JCS) canonical form. Fixture bodies are JSON values; the bytes that are hashed on the
// wire are whatever the client sent, and conformance tests send exactly this form (§4.6).
// The one definition of canonical bytes in the repo lives in lib/jcs.mjs (shared with the brief
// content_hash); this is the same function under the name the IPC contract uses.
export const canonicalJson = canonicalize;

export function bodyBytes(body) {
    if (body === null || body === undefined) {
        return Buffer.alloc(0);
    }

    return Buffer.from(canonicalJson(body), 'utf8');
}

// Error codes a server emits before it has derived a signing key (§4.5): those responses are unsigned.
export const UNSIGNED_ERROR_CODES = new Set(['origin_rejected', 'version_unsupported', 'session_invalid']);
