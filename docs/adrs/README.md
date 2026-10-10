# Architecture Decision Records

Every architectural degree of freedom in the blueprint is closed by an ADR here, using
[`000-template.md`](000-template.md). Status moves to **Accepted** only with an Evidence section
(blueprint §15). Decisions already made by the owner are recorded, not re-argued.

| ADR | Title | Owner | Closes | Status |
|---|---|---|---|---|
| [001](001-macos-runtime-packaging.md) | macOS runtime packaging | A (macOS/Swift) + B | §15.1, §15.2 | Proposed — written 2026-10-10 with measured prototype; awaits Andreas (legal L1–L4, A12) and the S1-06 clean-Mac run |
| [002](002-local-db-and-queue.md) | Local database and queue | B (Laravel platform) | §15.3a, A3 (proposed), A4, A9 | Proposed — benchmark evidence attached, awaiting review |
| [003](003-keychain-and-secrets.md) | Keychain and secret storage | H (Security/privacy) + A | §15.3b | Proposed |
| [004](004-provider-neutral-ai.md) | Provider-neutral AI layer on `laravel/ai` 1.2.0 | D (AI/Agents), H + B review | §15.4 (SDK half), §15.6 (runtime; licenses → ADR-007), A10 | Proposed — drafted 2026-10-10, awaits D4 egress test and D2 matrix before Accepted |
| [005](005-peekaboo-mcp-security.md) | Peekaboo and MCP security model | G (MCP/Automation) + A, H reviews | §15.4 (MCP client + transports), §15.5, A1 (pending Andreas) | Proposed — written 2026-10-10; Accepted blocked on A1 ruling and two prototypes listed in its Evidence section |
| [006](006-private-remote-access.md) | Private remote access via Tailscale Serve (never Funnel) | A + C, H reviews | §15.8 | Proposed |
| [007](007-license-and-compliance.md) | License and compliance boundaries | J (Docs/community) + H | §15.10, §15.6 (licenses) | Proposed |
| 008 | Email account and OAuth strategy | F (Integrations) + H | §15.7, A2 | Planned — Sprint 2, gates Sprint 3 |
| 009 | Brief scheduling and send consent | D + F + H | §15.9, A2 | Planned — Sprint 2, gates Sprint 3 |

Sprint 1 may not start until ADR-001, ADR-002 and ADR-003 are Accepted (`docs/ROADMAP.md`).

## Conventions

- Filename: `NNN-kebab-case-title.md`. Numbers are never reused; a superseding decision gets a new
  number and the old one is marked `Superseded by ADR-NNN`.
- Deciders: the owning workstream plus at least one reviewer from another workstream (§13). Items
  marked `ANDREAS` in `docs/OPEN-QUESTIONS.md` also need the owner's explicit sign-off.
- An ADR proposal is opened as a GitHub issue with the `adr` label and the ADR issue form.
