// ADR-005 D4.6 `mcp_tools.schema_hash`: SHA-256 over the RFC 8785 canonical JSON of a server's
// advertised `tools` array. One definition, shared by validate-schema.mjs (CI) and
// tools/hash-mcp-tools.mjs, so a client implementation has exactly one hash to match.

import { createHash } from 'node:crypto';

import { canonicalize } from './jcs.mjs';

export function toolsSchemaHash(tools) {
    return `sha256:${createHash('sha256').update(canonicalize(tools), 'utf8').digest('hex')}`;
}

// Every exchange in a hostile-MCP fixture that must carry a schema_hash, in document order:
// a tools/list request whose response carries a tools array.
export function hashedExchanges(fixture) {
    return (fixture.exchanges ?? []).filter(
        (exchange) => exchange.request?.method === 'tools/list' && Array.isArray(exchange.response?.result?.tools),
    );
}
