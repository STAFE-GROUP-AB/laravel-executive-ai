# Architecture Decision Records

Every architectural degree of freedom in the blueprint is closed by an ADR here, using
[`000-template.md`](000-template.md). Status moves to **Accepted** only with an Evidence section
(blueprint §15). Decisions already made by the owner are recorded, not re-argued.

| ADR | Title | Owner | Closes | Status |
|---|---|---|---|---|
| [001](001-macos-runtime-packaging.md) | macOS runtime packaging | A (macOS/Swift) + B | §15.1, §15.2 | Proposed — written 2026-10-10 with measured prototype; awaits Andreas (legal L1–L4, A12) and the S1-06 clean-Mac run |
| [002](002-local-db-and-queue.md) | Local database and queue | B (Laravel platform) | §15.3a, A3 (proposed), A4, A9 | Proposed — benchmark evidence attached, awaiting review |
| [003](003-keychain-and-secrets.md) | Keychain and secret storage (data protection keychain, Supervisor secrets broker, no App Sandbox in v1, SQLCipher deferred) | H (Security/privacy) + A, B reviews | §15.3b; feeds S0-15, S0-23 | Proposed — written 2026-10-10; Accepted blocked on R1/R2 acceptance (Andreas) and the S1 AfterFirstUnlock + access-group prototypes |
| [004](004-provider-neutral-ai.md) | Provider-neutral AI layer on `laravel/ai` 1.2.0 | D (AI/Agents), H + B review | §15.4 (SDK half), §15.6 (runtime; licenses → ADR-007), A10, **A18** (D9) | Proposed — drafted 2026-10-10; **D9 added 2026-10-13 by S2-14** (where the privacy mode lives, who may change it, what a change writes). D2's contract matrix and D4's `LocalOnlyEgressTest` are green; Accepted still awaits the D4 packet-capture lab run |
| [005](005-peekaboo-mcp-security.md) | Peekaboo and MCP security model | G (MCP/Automation) + A, H reviews | §15.4 (MCP client + transports), §15.5, A1 (pending Andreas), **A15** (D4.11) | Proposed — written 2026-10-10; **D4.11 added 2026-10-13 by S2-14** (the shared egress and SSRF guard, adopting threat-model §3.4.1 verbatim). Accepted still blocked on the A1 ruling and the two prototypes in its Evidence section |
| [006](006-private-remote-access.md) | Private remote access via Tailscale Serve (never Funnel) | A + C, H reviews | §15.8 | Proposed |
| [007](007-license-and-compliance.md) | License and compliance boundaries — MIT, notice-only binaries, no bundled model weights, claim boundaries; inventory in [`docs/compliance/third-party-licenses.md`](../compliance/third-party-licenses.md) | J (Docs/community) + H; Owner for legal items L-1…L-10 | §15.10, §15.6 (licenses) | Proposed — drafted 2026-10-10, awaits legal review before Accepted |
| 008 | Email account and OAuth strategy | F (Integrations) + H | §15.7, A2 | Planned — Sprint 2, gates Sprint 3 |
| [009](009-brief-scheduling-and-send-consent.md) | Brief scheduling and send consent — no default schedule, one brief per organization per local day by unique constraint, catch-up after login, test-message-backed consent for one recipient and one content type, durable send receipt (answers ADR-002 D7) | D + F + H; B, A, C reviews | §15.9 (decided 2026-10-11), A2 (ADR-009 half), ADR-002 D7 | Proposed — written 2026-10-13 (S2-18, #100) on the decided §15.9 ruling (no default schedule; a suggested 07:00 in the Mac's zone, pre-filled only); Accepted on the four S2-18 tests and the health 1.1.0 fixtures |

Sprint 1 may not start until ADR-001, ADR-002 and ADR-003 are Accepted (`docs/ROADMAP.md`).

**Residual risks are consolidated outside the ADRs.** Each ADR keeps its own "Residual risks"
section, and [`docs/security/threat-model.md`](../security/threat-model.md) (S0-23) collects them
into one register with a named accepting role, compensating controls and a reopen condition per row,
plus the trust boundaries and the §11 gate mapping those decisions are supposed to satisfy. An ADR
that changes a mitigation, or whose reopen condition fires, updates that register in the same PR; the
threat model also records contradictions **between** ADRs rather than resolving them unilaterally (its
§6.2).

## Conventions

- Filename: `NNN-kebab-case-title.md`. Numbers are never reused; a superseding decision gets a new
  number and the old one is marked `Superseded by ADR-NNN`.
- Deciders: the owning workstream plus at least one reviewer from another workstream (§13). Items
  marked `ANDREAS` in `docs/OPEN-QUESTIONS.md` also need the owner's explicit sign-off.
- An ADR proposal is opened as a GitHub issue with the `adr` label and the ADR issue form.
