// RFC 8785 (JSON Canonicalization Scheme) for the value types the fixture schemas allow, plus the
// Executive Brief content_hash rule (docs/architecture/brief-schema.md §2 "Freeze and delivery").
//
// Shared by validate-schema.mjs (CI) and tools/hash-brief.mjs so there is exactly one definition
// of "the canonical bytes of a brief". A renderer that implements the §6 parity export (S1-10, #38)
// must agree with this function byte for byte.
//
// JSON.stringify already matches RFC 8785 for strings (short escapes, lowercase \u00xx for other
// control characters, no escaping of non-ASCII) and for numbers (ES6 Number::toString); the only
// thing JCS adds is member ordering by UTF-16 code units, which is what Array.prototype.sort does.

import { createHash } from 'node:crypto';

export function canonicalize(value) {
    if (Array.isArray(value)) {
        return `[${value.map(canonicalize).join(',')}]`;
    }

    if (value !== null && typeof value === 'object') {
        const keys = Object.keys(value).sort();

        return `{${keys.map((k) => `${JSON.stringify(k)}:${canonicalize(value[k])}`).join(',')}}`;
    }

    return JSON.stringify(value);
}

// Top-level brief fields that are delivery bookkeeping, not content (brief-schema.md §3).
export const BRIEF_NON_CONTENT_FIELDS = ['delivery_state', 'deliveries', 'content_hash'];

export function briefContentEnvelope(brief) {
    const envelope = { ...brief };

    for (const field of BRIEF_NON_CONTENT_FIELDS) {
        delete envelope[field];
    }

    return envelope;
}

export function briefContentHash(brief) {
    return `sha256:${createHash('sha256').update(canonicalize(briefContentEnvelope(brief)), 'utf8').digest('hex')}`;
}
