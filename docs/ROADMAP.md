# Laravel Executive AI — Roadmap

> **Source of truth:** `Mission/Laravel_Executive_AI_Engineering_Blueprint.md` (the "blueprint").
> This roadmap is a 1:1 phasing of blueprint §13.1 (sprint sequence) and §16 (first 48 hours).
> **Created:** 2026-10-10 · **Owner:** Program owner / Staff Architect (§13)
> **North star (§0):** Local First · Europe First · Open Source First.

## How to read this document

- Sprints are **gated**, not time-boxed. A sprint ends when its exit criteria are met, because
  every exit criterion in §13.1 is an evidence statement ("Exit: first clean install measured"),
  not a date.
- Workstreams are the ten DRIs from §13: **A** macOS/Swift · **B** Laravel platform ·
  **C** TALL/PWA design · **D** AI/Agents · **E** CRM · **F** Integrations · **G** MCP/Automation ·
  **H** Security/privacy · **I** QA/release · **J** Docs/community.
- Every workstream needs a DRI **and at least one reviewer from another team** (§13).
- §13.2 is binding throughout: monorepo, protected `main`, required CI, 2 reviewers for
  security-critical modules, CODEOWNERS, no giant "AI agent builds everything" PR.

## Decisions already closed (do not re-open)

Public repo · MIT license · plan-first-then-build sequencing · Laravel 13 on the official
Laravel AI SDK (https://laravel.com/docs/13.x/ai-sdk) as the model-neutral provider layer ·
SQLite WAL + Laravel database queue for core-ready (§2) · out of scope per §0: Basecamp,
GoZero Core, Filament, OpenClaw, mandatory Playwright, Linux, Windows.

Note on MIT: §15 item 10 is *partly* closed. The project license is MIT (Andreas' decision), but
§15.10's second half — **legal review of bundled executables and third-party model licenses** —
is still open and is ADR-007's real subject. See `OPEN-QUESTIONS.md`.

---

## Sprint 0 — Architecture freeze

**Goal:** Close every architectural degree of freedom before any feature code exists. §15 is
explicit: *"Unresolved is not permission to guess. Each needs an ADR, measured prototype or
vendor-doc citation."* Sprint 0 produces those artifacts.

**Blueprint exit criterion (§13.1):** *"all DRIs approve contracts."*

**Active workstreams:** all ten (A–J). This is the only sprint where J (docs/community) and
H (security) lead rather than support.

### Deliverables

| # | Deliverable | Workstream | Blueprint ref |
|---|---|---|---|
| 0.1 | ADR-001 … ADR-007 written, reviewed and **approved** | A,B,H,D,G,F,J | §16.2, §15 |
| 0.2 | Repo hygiene: CI, CODE_OF_CONDUCT.md, issue/PR templates, branch protection, CODEOWNERS verified | I,J | §16.1, §2.1, §13.2 |
| 0.3 | Monorepo skeleton exactly as §2.1 — `apps/macos/`, `app/AI`,`app/Browser`,`app/CRM`,`app/MCP`,`app/Integrations`,`app/Briefs`,`app/Approvals`,`app/Security`,`app/Support`, `packaging/`, `tests/{Feature,Unit,Integration,Security,Performance,E2E}`, `docs/{architecture,adrs,operations,security,compliance,contributing}` | B,A | §2.1 |
| 0.4 | JSON/API contracts: Executive Brief schema (`id`,`version`,`generated_at`,`period_start/end`,`subject`,`summary`,`items[]`,`sources[]`,`action_ids[]`,`model_provenance`,`delivery_state`,`stale_after`), health contract, Swift↔Laravel IPC contract | B,A,C | §1.3, §2, §6 |
| 0.5 | PHP interface stubs for the §3 contracts: `AiProviderAdapter`, `WorkspaceRepository`, `ComputerUseProvider`, `TunnelProvider`, plus §5.6 `EmailConnector` | B,D,F,G | §3, §5.6 |
| 0.6 | **Frozen fixture pack** — one JSON brief source, synthetic mail corpus seed, prompt-injection red-team seed corpus, hostile-MCP-server fixture | I,D,H | §13.1, §14, §16.7 |
| 0.7 | Baseline threat model (indirect prompt injection, exfiltration, SSRF, cross-account, tool poisoning, Mac helper privilege) | H | §11, §6, §7 |
| 0.8 | Design tokens + design system foundation (sober premium identity, explicitly *not* a CleanMyMac clone) | C | §1.1 |
| 0.9 | Working **empty shell** of Swift app + Laravel app that both build in CI | A,B | §13.1 |
| 0.10 | Support-matrix decision recorded with measurements, not opinion: macOS floor, Apple Silicon only vs Intel, minimum RAM/disk | A,I | §15.1, §2 |

### §16 "first 48 hours" steps folded in here

- **§16.1** repo + license/README/security/CI/CODEOWNERS + labels → Sprint 0 (§0.2). *Partly done:*
  repo, README, LICENSE, SECURITY.md, CONTRIBUTING.md, CODE_OF_CONDUCT.md, CODEOWNERS, issue/PR
  templates and the twelve labels exist; CI and branch protection do not.
- **§16.2** the seven ADRs → Sprint 0 (§0.1). Hard gate on everything downstream.
- **§16.5** signed internal Mac build from CI, with launchd process management, kill/restart and
  uninstall verified on a clean Mac → **starts** in Sprint 0 (CI plumbing + signing identity),
  **proven** in Sprint 1.
- **§16.6** install-timer runner exporting per-phase JSON → built in Sprint 0 so Sprint 1 has
  something to measure with. §12.2 needs ≥30 measured installs per Mac profile; the runner must
  exist before the first install is worth measuring.
- **§16.7** negative security test (a foreign email asks the agent to send customer data → the
  system refuses) → authored in Sprint 0 as a **failing** test against the §3/§5.6 contracts;
  it must stay red until the PolicyGate exists, then go green and never regress.

### Dependency gate into Sprint 1

Sprint 1 must not start until: ADR-001 (runtime packaging), ADR-002 (local DB/queue) and
ADR-003 (Keychain/secrets) are approved; the brief JSON schema is frozen; the fixture pack is
frozen; CI builds both shells. ADR-004 through ADR-007 may land in parallel with Sprint 1 —
they gate Sprints 2–4, not the slice.

---

## Sprint 1 — Vertical slice

**Goal:** One end-to-end path through the whole product with zero external integrations, proving
the architecture physically works on a real Mac.

**Blueprint exit criterion (§13.1):** *"signed debug Mac app installs Laravel bundle, runs SQLite
migration, creates owner, launches PWA and menu bar, renders one sample brief from a single JSON
source. No external integration. Exit: first clean install measured."*

**Active workstreams:** A (lead), B (lead), C, I. D contributes only the brief-render path, not
model calls. H reviews. J documents. E/F/G idle by design — §16.9: *"Först när denna slice är
stabil får teamet parallellt aktivera externa integrationer, Peekaboo och MCP."*

### Deliverables

| # | Deliverable | Workstream | Blueprint ref |
|---|---|---|---|
| 1.1 | Minimal Laravel 13 app: SQLite WAL, auth, `/health/live`, `/health/ready`, `briefs` migrations, database queue + scheduler, install-state marker | B | §16.4, §2 |
| 1.2 | Installer state machine `WELCOME → PREFLIGHT → PACKAGE_VERIFY → INSTALL_CORE → CREATE_OWNER → SELECT_AI → BOOT_SERVICES → HEALTH_CHECK → READY` | A | §12.1 |
| 1.3 | Six first-run screens, ≤6 mandatory interaction steps (more needs an ADR + measurement) | A,C | §12.3 |
| 1.4 | App-owned immutable versioned runtime in Application Support + separate mutable user-data dir | A | §12.1 |
| 1.5 | `launchd`/`SMAppService` supervision: auto-launch, crash restart, bounded retries, health probes | A,B | §2, §9 |
| 1.6 | Authenticated loopback IPC, versioned, origin-checked, session token in Keychain | A,B,H | §2, §6 |
| 1.7 | **Tri-surface brief parity**: same frozen fixture rendered in SwiftUI menu bar, Blade HTML mail and PWA, proven by snapshot tests | A,B,C | §16.3, §1.3 |
| 1.8 | PWA app shell with manifest/icons/service worker, `/home`, `/briefs`, installable | C | §1.2 |
| 1.9 | Signed **debug** DMG from CI; Gatekeeper-clean on a machine that never saw the source | A,I | §16.5, §12.1 |
| 1.10 | First clean-install measurement run with the §16.6 timer: per-phase JSON, median + p95 | I | §13.1, §12.2 |
| 1.11 | Uninstall + data-preserving reinstall paths | A,B | §12.2 |
| 1.12 | "AI setup required" state renders correctly with **no** provider configured — never a false "AI ready" | A,C | §0, §12.2 |

### §16 steps completed here

**§16.3** (tri-surface fixture parity) → 1.7. **§16.4** (minimal Laravel app) → 1.1.
**§16.5** (signed CI build, launchd lifecycle, uninstall on clean Mac) → 1.5 + 1.9 + 1.11.
**§16.8** (*"Install → Create owner → Open PWA → Generate seeded brief → Show same brief in menu
bar → Preview exact email"*) → **the whole of Sprint 1**; this is the slice.
**§16.9** is the gate out of this sprint, not a deliverable.

### Dependency gate into Sprint 2

The §16.8 slice must be **stable** — repeatable on a clean machine, measured, green in CI — before
E/F/G start. A first install measurement that misses p50<180s / p95<300s does not block Sprint 2;
it opens a performance issue with a named owner. Measuring is the gate; the number is a release
gate (§12.2), not a Sprint 1 gate.

---

## Sprint 2 — Real core

**Goal:** Turn the slice into a product someone could actually use for a day, with real data they
typed themselves and a real model answering them.

**Blueprint exit criterion (§13.1):** *"Local CRM CRUD, auth/MFA, assistant chat with local Ollama
or user-selected provider, database queue/scheduler, real brief generation with fixtures, mail
delivery preview. Exit: security checks and brief identity."*

**Active workstreams:** E (lead), D (lead), B, C, H. I measures. A keeps the installer current.

### Key deliverables

- **Local CRM as system of record (§3.2):** customers with legal identity, contacts with multiple
  addresses, projects, tasks, communications, activity timeline with visible sources and remembered
  promised follow-ups. CSV/JSON import/export. GDPR export + erasure. SQLite FTS5 full-text first;
  vector search stays opt-in and post-benchmark (§3.2 — no external vector DB as a requirement).
- **§3.1 database minimum** migrated: all listed tables, each business object carrying ULID/UUID,
  `organization_id`, timestamps, retention policy, `source_id`/`source_uri`, sensitivity class,
  provenance, soft-delete where needed; unique indexes for provider/account/external_id and
  idempotency keys.
- **Auth + authorization:** passkeys/WebAuthn or MFA, secure sessions, session revocation, device
  management, re-auth for critical actions (§11). Policies/gates on **every** HTTP action, Livewire
  action, queued job, MCP tool, AI tool and integration call, with IDOR and multi-tenant
  cross-access negative tests (§11).
- **Provider-neutral AI:** thin adapters over the Laravel AI SDK at a pinned version with contract
  tests (§4). Neutral onboarding, no preselected brand (§4, §12.3.5). Capability-aware routing that
  **fails closed** when the chosen model lacks the needed capability (§4).
- **`LOCAL_ONLY` enforced for real:** no model traffic, embeddings, screenshots, tool transcripts or
  prompts to cloud; no silent fallbacks (§4, §10.1).
- **`PolicyGate` server-side** validating tool name, input schema, org/account/scope, data class,
  destination, budget and approval *before* execution — the LLM proposes, never authorizes (§4).
  This is where the §16.7 negative test turns green.
- **Agent control plane:** maxsteps, maxcost, maxtime, dedupe, cancellation, circuit breakers,
  redaction, trace-id, output provenance, replay against synthetic data (§4).
- **Real brief generation** from fixtures: transactional single freeze, then render; content
  divergence between surfaces forbidden (§1.3). Idempotent delivery; no double send on retry.
  Local preview mode with no outgoing mail (§1.3, §5.5).
- **Own TALL admin** — users, orgs, security rules, logs, retention, updates, backup. No Filament
  (§1.2 `/admin`, §0).
- **`AI unavailable` degrades gracefully:** CRM, past briefs and admin keep working (§4.2).

### Dependency gate into Sprint 3

§3.1 schema stable, PolicyGate enforcing, brief identity proven across all three surfaces, and
authorization negative tests green. Sprint 3's integrations write into the Sprint 2 data model —
schema churn after Sprint 3 starts is expensive across eight provider adapters.

---

## Sprint 3 — Sources and automation

**Goal:** Real external data, under real policy. This is the largest and highest-risk sprint; §5.6
alone is a binding v1 requirement with ten acceptance criteria.

**Blueprint exit criterion (§13.1):** *"multi-Google, Microsoft Graph, iCloud, HEY, generic
IMAP/SMTP, GitHub App, unified inbox och source normalization; MCP discovery, read-only tools;
Peekaboo sandbox/approval/session controls. Exit: no cross-account leakage; stop button works."*

**Active workstreams:** F (lead), G (lead), H, B, C, D, I.

### Key deliverables

- **Multi-account email platform (§5.6)** — unlimited accounts, mixed providers, unified inbox.
  Google/Workspace (OAuth+PKCE, Gmail API), Microsoft 365/Outlook/Hotmail (**mandatory for v1, not
  a later option** — §5.6 delivery responsibility), iCloud (app-specific password IMAP/SMTP unless
  real third-party authorization exists), HEY (official CLI, explicit argv, never shell
  interpolation), generic IMAP TLS/SMTP STARTTLS with autodiscovery + manual override, optional
  local Proton Bridge.
- **Sync + normalization (§5.6):** `email_connections`, `email_folders`, `email_messages`,
  `email_threads`, `email_attachments`, `email_sync_cursors`, `email_delivery_attempts`,
  `email_account_permissions`, `email_identity_mappings`; Gmail history IDs, Graph delta queries,
  IMAP UID+UIDVALIDITY+modseq; polling default, push opt-in with signed ingress; backpressure,
  exponential jitter, idempotency, quota feedback, tombstones, dedupe, conflict resolution.
- **GitHub App (§5.4)** per-organization installs, org/repo allowlist, read-only default,
  short-lived installation tokens, signature-verified webhooks with replay/idempotency guards —
  or polling for strictly private installs. No merges/commits/issue edits/workflow triggers.
- **Explainable CRM matching (§5.6):** never assume a domain maps to one customer; manual
  merge/split; original message always reachable.
- **MCP Manager (§7):** registry discovery, then
  *Discover → Assess → Explain → Approve → Install/Connect → Grant tools → Audit → Revoke*.
  Read-only tools first. Local stdio servers limited to an allowlisted, manually reviewed runtime;
  general third-party installs feature-gated until a real process sandbox is verified. Tool
  descriptions and MCP responses are untrusted input — policy decisions live in code, not prompts.
  Expose own narrow tools (`crm.search`, `tasks.list`, `brief.read`); mutating tools need approvals.
- **Peekaboo (§6)** as optional `ComputerUseProvider`: app/window allowlist, user-visible session,
  per-action authorization, screenshot redaction, timeouts, panic stop, verified version only. No
  attempt to work around TCC, lock screen or 2FA. Menu bar shows active GUI automation.
- **SSRF-hardened `Browser/Fetch` (§11):** DNS/IP checks, loopback + RFC1918 + metadata + unix
  socket blocked unless explicitly needed, redirects/ports/protocols revalidated.
- **Outbound action approvals (§11):** frozen immutable proposal with input/recipient/hash,
  approver identity, expiry, nonce, idempotency, fail closed.

### Dependency gate into Sprint 4

All ten §5.6 acceptance criteria pass — in particular #3 (100 000 synthetic messages across eight
accounts, resumable after interruption, no duplicates or loss), #4 (account A's mail unreachable
from account B via API, Livewire action, agent, queue or MCP) and #10 (packet-capture locality
test). Plus: emergency stop demonstrably stops Peekaboo and MCP mid-action.

---

## Sprint 4 — Production candidate

**Goal:** Everything that makes it shippable rather than demonstrable.

**Blueprint exit criterion (§13.1):** *"Tailscale, native upgrades, backups, recovery, performance
tuning, EU data controls, onboarding polish, accessibility, complete docs, notarization, automated
install matrix, third-party review. Exit: verified release gates."*

**Active workstreams:** I (lead), H (lead), A, C, J, B.

### Key deliverables

- **Private remote access (§8):** Tailscale Serve on a private tailnet — **not** Funnel. Local HTTP
  on `127.0.0.1`, single tunnelled origin with valid HTTPS, correct `APP_URL`/`TrustProxies`/secure
  cookies/CSRF/session config. iPhone home-screen PWA tested from a mobile network. Tailnet ACLs
  confirmed; the whole machine must not be exposed. Automatic tunnel setup may never silently
  enable public exposure. Opt-in push with minimal, time-limited payloads and no customer text in
  cleartext.
- **Updates (§9):** signed update feed, deltas where sensible, migration dry-run + backup, binary
  rollback, and no automatic rollback of incompatible schema changes without a plan.
- **Backup/restore (§9):** local encrypted backup plus a recommended external encrypted
  destination, retention, **restore drills**. SQLite snapshots via the consistent checkpoint/backup
  API — never a blind live file copy.
- **Power and recovery (§9):** screen-off/locked-session operation, the macOS sleep setting surfaced
  and guided, `caffeinate` only for temporary jobs, documented FileVault-unlock and GUI-session
  manual steps after a power cut. No false unattended-recovery promises.
- **Compliance (§10):** legally reviewed per-role, per-use-case compliance mapping with
  release-valid dates; AI model/provider/region/subprocessor register with per-run provenance;
  full GDPR support surface; AI labelling; use-case risk classification with a specific guardrail
  for recruitment/employment decisions; DPA/transfer-impact/retention reviews; SBOM; telemetry OFF
  by default. **No "EU AI Act certified" claim anywhere** (§0).
- **Release engineering (§14):** notarization + staple, clean-machine Gatekeeper test, SBOM +
  license audit, version manifest, migration & rollback drill, published test matrix, README
  install video, security contact, changelog, published checksums, signed tag.
- **Install matrix (§12.2):** Mac A (clean M4 Max, 36–64 GB) and Mac B (clean M3 Ultra), ≥30
  measured installs per profile, p50<180s / p95<300s core-ready, 100% coverage of fresh install,
  re-install, upgrade, failed upgrade, interrupted install, uninstall and data-preserving reinstall.
- **Third-party penetration test before v1.0** (§11), with signed risk acceptance by H (§13).
- **Accessibility + Lighthouse** on iPhone and Mac PWA (§13 workstream C DoD).
- **Outside-contributor reproducibility** (§13 workstream J DoD): a stranger can build from source
  per README and reproduce the test suite.

---

## Definition of Done for v1.0 (§17)

Verbatim checklist from blueprint §17. No item may be waived without an ADR.

- [ ] Mac-only product shipped as a signed/notarized app with premium-quality UX.
- [ ] Verified core install under 5 minutes at p95, per a transparent test protocol.
- [ ] Native installer, menu bar brief, crash recovery and auto-updater all working.
- [ ] TALL Stack PWA and own admin without Filament; local CRM usable offline from external APIs.
- [ ] Neutral AI onboarding, working local AI, and at least two optional external AI adapters.
- [ ] No GoZero Core and no Basecamp in code, UI or default configuration.
- [ ] Sync to the relevant optional mail accounts and GitHub organizations works, with
      rollback/revoke.
- [ ] One versioned brief renders identically in PWA, menu bar and email.
- [ ] Peekaboo and MCP installable as opt-in and protected by a verified permission model.
- [ ] Tailscale Serve remote PWA tested from an iPhone outside the LAN; no public exposure by
      default.
- [ ] `LOCAL_ONLY` makes no outbound LLM requests and has no silent fallbacks.
- [ ] GDPR/AI Act use-case assessment and security tests documented, with no false certification
      claims.
- [ ] Backups, restore, updates, uninstall/data retention and incident paths tested.
- [ ] An external developer can build from source per README and contribute per CONTRIBUTING.

## Related documents

- `docs/BACKLOG.md` — issue-ready work items for Sprint 0 and Sprint 1.
- `docs/OPEN-QUESTIONS.md` — the ten §15 unresolved decisions mapped to ADRs, owners and evidence.
