#!/usr/bin/env node
// Prints, checks or writes the schema_hash of every tools/list exchange in a hostile-MCP fixture
// (S0-19).
//
//   node packaging/fixtures/tools/hash-mcp-tools.mjs <server.json> [...]            print
//   node packaging/fixtures/tools/hash-mcp-tools.mjs --check <server.json> [...]    exit 1 on mismatch
//   node packaging/fixtures/tools/hash-mcp-tools.mjs --write <server.json> [...]    rewrite in place
//
// The hash is ADR-005 D4.6's `mcp_tools.schema_hash`: SHA-256 over the RFC 8785 canonical JSON of
// `response.result.tools`, which is also what validate-schema.mjs recomputes. --write replaces only
// the value of each "schema_hash" member, textually and in document order, so every other byte of
// the fixture is left exactly as it was. No dependencies beyond Node.

import { readFileSync, writeFileSync } from 'node:fs';

import { hashedExchanges, toolsSchemaHash } from '../lib/mcp-tools.mjs';

const args = process.argv.slice(2);
const mode = args[0] === '--write' || args[0] === '--check' ? args.shift().slice(2) : 'print';

if (args.length === 0) {
    console.error('usage: hash-mcp-tools.mjs [--check|--write] <server.json> [...]');
    process.exit(2);
}

let mismatches = 0;

for (const path of args) {
    const raw = readFileSync(path, 'utf8');
    const fixture = JSON.parse(raw);
    const exchanges = hashedExchanges(fixture);

    if (exchanges.length === 0) {
        console.log(`skip  ${path}  (no tools/list exchange with a result)`);
        continue;
    }

    const computed = exchanges.map((exchange) => toolsSchemaHash(exchange.response.result.tools));

    if (mode === 'print') {
        exchanges.forEach((exchange, i) => console.log(`${computed[i]}  ${path} step ${exchange.step}`));
        continue;
    }

    if (mode === 'check') {
        exchanges.forEach((exchange, i) => {
            if (exchange.schema_hash === computed[i]) {
                console.log(`ok    ${path} step ${exchange.step}`);

                return;
            }

            mismatches += 1;
            console.error(`FAIL  ${path} step ${exchange.step}\n      stored ${exchange.schema_hash}\n      actual ${computed[i]}`);
        });
        continue;
    }

    const member = /("schema_hash"\s*:\s*)"[^"]*"/g;
    const occurrences = raw.match(member) ?? [];

    if (occurrences.length !== exchanges.length) {
        console.error(`FAIL  ${path}: ${exchanges.length} tools/list exchanges but ${occurrences.length} "schema_hash" members`);
        process.exit(1);
    }

    let i = 0;
    const updated = raw.replace(member, (_match, prefix) => `${prefix}"${computed[i++]}"`);

    if (updated === raw) {
        console.log(`unchanged  ${path}`);
        continue;
    }

    writeFileSync(path, updated, 'utf8');
    console.log(`updated    ${path}  (${exchanges.length} hash${exchanges.length === 1 ? '' : 'es'})`);
}

process.exit(mismatches === 0 ? 0 : 1);
