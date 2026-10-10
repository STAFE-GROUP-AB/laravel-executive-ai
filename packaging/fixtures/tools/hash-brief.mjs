#!/usr/bin/env node
// Prints, checks or writes the content_hash of one or more brief fixtures (S0-18).
//
//   node packaging/fixtures/tools/hash-brief.mjs <brief.json> [...]            print computed hash
//   node packaging/fixtures/tools/hash-brief.mjs --check <brief.json> [...]    exit 1 on mismatch
//   node packaging/fixtures/tools/hash-brief.mjs --write <brief.json> [...]    rewrite content_hash in place
//
// --write replaces only the value of the "content_hash" member, textually, so every other byte of
// the fixture (formatting, key order, trailing newline) is left exactly as it was. The hash is the
// same one validate-schema.mjs recomputes: SHA-256 over the RFC 8785 canonical JSON of the content
// envelope, see lib/jcs.mjs and docs/architecture/brief-schema.md. No dependencies beyond Node.

import { readFileSync, writeFileSync } from 'node:fs';

import { briefContentHash } from '../lib/jcs.mjs';

const args = process.argv.slice(2);
const mode = args[0] === '--write' || args[0] === '--check' ? args.shift().slice(2) : 'print';

if (args.length === 0) {
    console.error('usage: hash-brief.mjs [--check|--write] <brief.json> [...]');
    process.exit(2);
}

let mismatches = 0;

for (const path of args) {
    const raw = readFileSync(path, 'utf8');
    const brief = JSON.parse(raw);
    const computed = briefContentHash(brief);
    const stored = brief.content_hash;

    if (mode === 'print') {
        console.log(`${computed}  ${path}`);
        continue;
    }

    if (mode === 'check') {
        if (stored === computed) {
            console.log(`ok    ${path}`);
            continue;
        }

        mismatches += 1;
        console.error(`FAIL  ${path}\n      stored ${stored}\n      actual ${computed}`);
        continue;
    }

    if (stored === computed) {
        console.log(`unchanged  ${path}`);
        continue;
    }

    const member = /("content_hash"\s*:\s*)"[^"]*"/g;
    const occurrences = raw.match(member) ?? [];

    if (occurrences.length !== 1) {
        console.error(`FAIL  ${path}: expected exactly one "content_hash" member, found ${occurrences.length}`);
        process.exit(1);
    }

    writeFileSync(path, raw.replace(member, `$1"${computed}"`), 'utf8');
    console.log(`updated    ${path}\n      ${stored ?? '(none)'} -> ${computed}`);
}

process.exit(mismatches === 0 ? 0 : 1);
