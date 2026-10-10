Contracts and schemas: module contracts, brief schema, health contract, IPC contract, support matrix, design system.

| Document | Issue | Schema / fixtures |
|---|---|---|
| [module-contracts.md](module-contracts.md) — the four §3 interfaces (`AiProviderAdapter`, `WorkspaceRepository`, `ComputerUseProvider`, `TunnelProvider`) and the §5.6 `EmailConnector` as typed PHP stubs with DTOs, cancellation and failure types; consumers; deliberate differences from the sketches; the provider-neutral `SyncCursor` mapping for Gmail, Graph and IMAP | S0-16, S0-17 | `tests/Unit/Contracts/` (reflection and arch tests) |
| [brief-schema.md](brief-schema.md) — Executive Brief JSON schema 1.0.0, compatibility and freeze rules, renderer parity contract | S0-13 | `packaging/fixtures/brief.schema.json`, `packaging/fixtures/briefs/` |
| [health-contract.md](health-contract.md) — `/health/live`, `/health/ready`, `ready` vs `ai_ready`, menu bar state mapping | S0-14 | `packaging/fixtures/health.schema.json`, `packaging/fixtures/health/` |
| [ipc-contract.md](ipc-contract.md) — Swift ↔ Laravel IPC 1.0.0: loopback HTTP `/ipc/v1/…`, session token + HMAC request/response signing, versioning, origin check, the v1 operations, GUI ↔ Supervisor XPC for pairing/elevation/service control | S0-15 | `packaging/fixtures/ipc.schema.json`, `packaging/fixtures/ipc/` (signed with the test key; `ipc-signing.mjs` is the reference implementation) |
| [support-matrix.md](support-matrix.md) — macOS 15–26, Apple Silicon only (Intel unsupported), measured vs PROVISIONAL RAM/disk, the ten §12.1 preflight checks with EN/SV blocker text, Sprint 1 measurement checklist | S0-22 | `packaging/fixtures/prototypes/adr-001/` (measurements) |
| [data-model.md](data-model.md) — the Sprint 1 minimum schema: §3.1 common columns, `briefs` / `brief_items` / `brief_deliveries` with the delivery idempotency indexes, `audit_events`, the install-state marker, processes and heartbeats, backend profiles | S1-01 | `database/migrations/`, `tests/Feature/Briefs/BriefRoundTripTest.php`, `tests/Integration/BackendContract/` |
| [installer.md](installer.md) — the §12.1 state machine as built: transition table, cancel/resume/rollback, checkpoint file, install-measurement event log, preflight implementation, `PACKAGE_VERIFY` fail-closed order, and what S1-06/S1-07/S1-08/S1-02 plug in | S1-05 | `packaging/fixtures/e2e/installer-events.ndjson` (pinned by `InstallEventLogTests`, consumed by the install-timer `node:test` suite) |
| [brief-parity.md](brief-parity.md) — the semantic parity payload every brief renderer (mail, PWA, menu bar) derives its output from and exports as JCS for the tri-surface comparison; per-surface rendering obligations; skeleton of the S1-10 harness | S1-03 (skeleton), S1-10 | `app/Briefs/Render/BriefParityPayload.php`, `tests/__snapshots__/brief-canonical.parity.json`, `tests/Feature/Briefs/Mail/` |
| [auth.md](auth.md) — owner creation (`CREATE_OWNER`, one owner ever), one-time-code login with its state machine and the owner's double-send and local-autofill rules, server-side session revocation, the passkey stub (E2-3, #69), the login self-test and the A16 browser-hardening header set | S1-02 | `tests/Feature/Auth/`, `tests/Feature/Health/LoginSelfTestTest.php`, `tests/Security/OtpCredentialNeverLoggedTest.php` |

Validate schemas and fixtures: `node packaging/fixtures/validate-schema.mjs` (after `npm ci --prefix packaging/fixtures`).

The trust boundaries these contracts create, and the threats each control answers, are modelled in
[`docs/security/threat-model.md`](../security/threat-model.md) (S0-23): the brief schema feeds its
data-at-rest and redaction rows, the health contract its exposure rows, the IPC contract boundary B2
(§2.3) in full, and the support matrix the preflight assumptions in §1.2. A change to any contract
here that moves a boundary or a control must update that register in the same PR.
