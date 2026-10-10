Contracts and schemas: brief schema, health contract, IPC contract, support matrix, design system.

| Document | Issue | Schema / fixtures |
|---|---|---|
| [brief-schema.md](brief-schema.md) — Executive Brief JSON schema 1.0.0, compatibility and freeze rules, renderer parity contract | S0-13 | `packaging/fixtures/brief.schema.json`, `packaging/fixtures/briefs/` |
| [health-contract.md](health-contract.md) — `/health/live`, `/health/ready`, `ready` vs `ai_ready`, menu bar state mapping | S0-14 | `packaging/fixtures/health.schema.json`, `packaging/fixtures/health/` |
| [ipc-contract.md](ipc-contract.md) — Swift ↔ Laravel IPC 1.0.0: loopback HTTP `/ipc/v1/…`, session token + HMAC request/response signing, versioning, origin check, the v1 operations, GUI ↔ Supervisor XPC for pairing/elevation/service control | S0-15 | `packaging/fixtures/ipc.schema.json`, `packaging/fixtures/ipc/` (signed with the test key; `ipc-signing.mjs` is the reference implementation) |
| [support-matrix.md](support-matrix.md) — macOS 15–26, Apple Silicon only (Intel unsupported), measured vs PROVISIONAL RAM/disk, the ten §12.1 preflight checks with EN/SV blocker text, Sprint 1 measurement checklist | S0-22 | `packaging/fixtures/prototypes/adr-001/` (measurements) |

Validate schemas and fixtures: `node packaging/fixtures/validate-schema.mjs` (after `npm ci --prefix packaging/fixtures`).
