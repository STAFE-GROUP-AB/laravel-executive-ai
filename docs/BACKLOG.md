# Laravel Executive AI — Backlog

> **Scope:** issue-ready items for **Sprint 0**, **Sprint 1** and **Sprint 2**. Sprints 3–4 carry
> epic-level placeholders to be decomposed when their gate opens (see `docs/ROADMAP.md`).
> **Created:** 2026-10-10 · **Sprint 2 decomposed:** 2026-10-11 ·
> **Spec:** `Mission/Laravel_Executive_AI_Engineering_Blueprint.md`
> **Items are dependency-ordered.** An agent may start any item whose `Depends on` is satisfied.

**Available labels (the only twelve that exist):** `macos` `laravel` `pwa` `ai` `crm`
`integrations` `mcp` `security` `compliance` `release` `adr` `docs`

**Workstream → label mapping:** A macOS/Swift→`macos` · B Laravel platform→`laravel` ·
C TALL/PWA→`pwa` · D AI/Agents→`ai` · E CRM→`crm` · F Integrations→`integrations` ·
G MCP/Automation→`mcp` · H Security/privacy→`security` · I QA/release→`release` ·
J Docs/community→`docs`.

**GitHub milestones:** `Sprint 0 — Architecture freeze`, `Sprint 1 — Vertical slice`. Each item
below is mirrored as a GitHub issue; the issue is the working copy, this file is the frozen plan.
A `Sprint 2 — Real core` milestone does not exist yet — creating it and assigning the S2 issues is a
repository action for the owner; the issues carry their sprint in the body meanwhile.

**Standing requirements on every PR (§13.2):** references its issue; references an ADR when
architectural; includes tests; states security impact; attaches a screenshot or recording for UI
changes. Security-critical modules need 2 reviewers. No giant multi-concern PR.

---

## Sprint 0 — Architecture freeze

### S0-01 Scaffold the §2.1 monorepo directory layout

**Labels:** `laravel`
**Sprint:** 0 · **Workstream:** B (Laravel platform) · **Size:** S · **Depends on:** —

Create the exact directory tree from blueprint §2.1 so every later item has an unambiguous home and
no agent invents a parallel structure. Directories only, plus a one-line `.gitkeep` or `README.md`
per leaf explaining what belongs there. Laravel lives at the repo root; the Swift app lives under
`apps/macos/`.

Create: `apps/macos/`, `app/AI/{Agents,Providers,Memory,Tools,Routing}/`,
`app/Browser/{Search,Fetch,ComputerUse}/`, `app/CRM/{Customers,Contacts,Projects,Tasks,Communications}/`,
`app/MCP/{Registry,Assessment,Broker,Runtime}/`, `app/Integrations/{Google,ICloud,Hey,GitHub,Mail}/`,
`app/Briefs/{Generation,Render,Delivery}/`, `app/Approvals/{Policies,Actions}/`,
`app/Security/{Secrets,Audit,Retention,Authorization}/`, `app/Support/{Health,Power,Install,Updates}/`,
`tests/{Feature,Unit,Integration,Security,Performance,E2E}/`,
`packaging/{manifest,signing,notarization,fixtures}/`,
`docs/{architecture,adrs,operations,security,compliance,contributing}/`, `.github/workflows/`.

- [ ] Every directory listed in §2.1 exists at the path §2.1 specifies.
- [ ] No directory outside §2.1 is introduced at the top level.
- [ ] Each leaf directory contains a placeholder file stating its purpose in one sentence.
- [ ] `docs/ROADMAP.md`, `docs/BACKLOG.md` and `docs/OPEN-QUESTIONS.md` are left in place.

---

### S0-02 Add ADR template and ADR index

**Labels:** `adr`, `docs`
**Sprint:** 0 · **Workstream:** J (Docs/community) · **Size:** S · **Depends on:** S0-01

The seven ADRs in §16 step 2 need a single shared shape before they are written in parallel, or they
will not be comparable or reviewable. §15 demands each decision be closed by "an ADR, measured
prototype or vendor-doc citation", so the template must have explicit slots for evidence and for
residual risk.

Create `docs/adrs/000-template.md` and `docs/adrs/README.md` (the index).

- [ ] Template sections: Status, Date, Deciders, Context, Decision, **Evidence** (ADR reasoning /
      measured prototype / vendor-doc citation with URL), Alternatives considered, Consequences,
      **Residual risks**, Blueprint references, Reopen conditions.
- [ ] Status vocabulary is fixed: Proposed / Accepted / Superseded / Rejected.
- [ ] `docs/adrs/README.md` lists ADR-001..ADR-007 with owner, status and the §15 items each closes.
- [ ] Template states that an ADR without an Evidence entry cannot move to Accepted.

---

### S0-03 Add CODE_OF_CONDUCT.md

**Labels:** `docs`
**Sprint:** 0 · **Workstream:** J (Docs/community) · **Size:** S · **Depends on:** —
**Status:** done in `778f45a` (Contributor Covenant 2.1; CONTRIBUTING link added).

§2.1 lists `CODE_OF_CONDUCT.md` as a required root file and it is the only one of the five root
governance files still missing. Use Contributor Covenant 2.1 unmodified except for the enforcement
contact, which must match the contact already published in `SECURITY.md`.

Create `/CODE_OF_CONDUCT.md`. Touch `/CONTRIBUTING.md` to link it.

- [ ] Contributor Covenant 2.1 text present with an attribution line.
- [ ] Enforcement contact address is identical to the one in `SECURITY.md`.
- [ ] `CONTRIBUTING.md` links to it.
- [ ] No placeholder text such as `[INSERT EMAIL]` remains.

---

### S0-04 Add GitHub issue and pull request templates

**Labels:** `docs`
**Sprint:** 0 · **Workstream:** J (Docs/community) · **Size:** S · **Depends on:** S0-01
**Status:** done in `778f45a` + follow-up (`task.yml`, `bug.yml`, `adr.yml`, `config.yml`,
`PULL_REQUEST_TEMPLATE.md`).

§13.2 requires every PR to reference an issue and an ADR where architectural, include tests, state
security impact, and attach a screenshot or recording for UI changes. Templates are how that becomes
automatic rather than remembered. §13.2 also requires public issues to be kept separate from private
vulnerability reports, so the bug template must route security reports to `SECURITY.md` instead of
collecting them publicly.

Create `.github/ISSUE_TEMPLATE/bug_report.yml`, `.github/ISSUE_TEMPLATE/feature_request.yml`,
`.github/ISSUE_TEMPLATE/adr.yml`, `.github/ISSUE_TEMPLATE/config.yml`,
`.github/PULL_REQUEST_TEMPLATE.md`.

- [ ] PR template has required checkboxes for linked issue, ADR reference, tests, security impact
      statement, and UI screenshot/recording.
- [ ] Bug template carries a prominent "do not report vulnerabilities here" notice pointing at
      `SECURITY.md`.
- [ ] Templates offer only the eleven labels that exist; none invents a new one.
- [ ] `config.yml` disables blank issues and links to the security policy.

---

### S0-05 Add CI: Laravel test job, Swift build job, dependency pinning and SCA

**Labels:** `release`, `security`
**Sprint:** 0 · **Workstream:** I (QA/release) · **Size:** M · **Depends on:** S0-01

§13.2 makes CI a required check on protected `main`; §11 requires pinned Composer/npm/Swift
dependencies, SBOM and SCA. Build this before the shells land so both shells are born green. Runs
on macOS runners because the Swift job needs Xcode. Signing is deliberately **not** in scope here —
see S0-20.

Create `.github/workflows/ci.yml`, `.github/workflows/sca.yml`, `.github/dependabot.yml`.

- [ ] `ci.yml` runs on pull_request and on push to `main`: PHP lint + static analysis + test suite,
      and `xcodebuild` of the `apps/macos/` scheme.
- [ ] Composer and npm lockfiles are committed and CI fails on a dirty lockfile.
- [ ] `sca.yml` runs dependency vulnerability scanning and SBOM generation on a schedule, uploading
      the SBOM as a build artifact.
- [ ] Job names are stable so they can be set as required status checks.

---

### S0-06 Write and approve ADR-001: macOS runtime packaging

**Labels:** `adr`, `macos`
**Sprint:** 0 · **Workstream:** A (macOS/Swift) · **Size:** L · **Depends on:** S0-02

Closes §15 item 2 (PHP and HTTP server packaging, update and legal constraints, native service
lifecycle). §2 forbids `php artisan serve` as a production server and §12.1 forbids any hidden
Homebrew/Docker prerequisite; §12.1 further states that if signed packaging of PHP proves legally or
technically impractical, the team must **escalate at architecture review rather than silently
requiring Homebrew**. The ADR must therefore name the exact PHP build, the exact HTTP server, their
licenses, how they are versioned and updated, and where they live on disk (app-owned immutable
versioned runtime in Application Support, separate from the mutable user data directory per §12.1).

Create `docs/adrs/001-macos-runtime-packaging.md`.

- [ ] Names the chosen PHP distribution and HTTP server with exact versions and license identifiers.
- [ ] Documents redistribution rights for every bundled binary, with a citation per binary.
- [ ] Specifies the `launchd`/`SMAppService` lifecycle: auto-launch, crash restart, bounded retries,
      health probes, and whether user login is required (§9).
- [ ] Carries a measured prototype result: a signed bundle booting the Laravel core on a clean Mac
      with no Homebrew, Docker or Terminal use.
- [ ] States the escalation outcome if signed PHP packaging is impractical — never a silent Homebrew
      dependency.

---

### S0-07 Write and approve ADR-002: local database and queue

**Labels:** `adr`, `laravel`
**Sprint:** 0 · **Workstream:** B (Laravel platform) · **Size:** M · **Depends on:** S0-02

Closes §15 item 3's first half (SQLite FTS5 vs optional MySQL). §2 already decides SQLite in WAL
mode plus the Laravel database queue for core-ready, with MySQL/Redis/Horizon as a later advanced
profile — so this ADR records the decision and, critically, the part §2 demands next: **"Säkerställ
migrationsväg och kontraktstester mellan backend-profiler."** §2 permits a switch only if a
benchmark proves a simpler maintained MySQL bundle also fits inside five minutes.

Create `docs/adrs/002-local-db-and-queue.md`.

- [ ] Records SQLite WAL + database queue for core-ready, citing §2, and states the conditions under
      which the decision reopens.
- [ ] Defines the migration path to a MySQL profile and the contract tests that must pass on both.
- [ ] Decides full-text strategy: SQLite FTS5 first, vector search opt-in only after benchmark, no
      external vector DB as a requirement (§3.2).
- [ ] Specifies backup mechanics: SQLite consistent checkpoint/backup API, never a live file copy
      (§9).
- [ ] Notes WAL-specific operational consequences (concurrent readers, checkpointing, worker
      contention) with a measured figure, not an assertion.

---

### S0-08 Write and approve ADR-003: Keychain and secret storage

**Labels:** `adr`, `security`
**Sprint:** 0 · **Workstream:** H (Security/privacy) · **Size:** L · **Depends on:** S0-02

Closes §15 item 3's second half (Keychain service access and whether app sandboxing is feasible
given Peekaboo). §3.1 raises the specific risk this ADR must answer: **SQLite is not protected by
FileVault while the user is logged in**, so credentials belong in the Keychain and SQLCipher must be
evaluated separately. §11 forbids secrets in app logs, crash dumps, prompts, push payloads,
screenshots, the mail brief or the repo.

Create `docs/adrs/003-keychain-and-secrets.md`.

- [ ] Defines one Keychain item per service identity, with access control and the exact entitlements
      required, citing Apple documentation.
- [ ] Answers the sandboxing question with evidence: whether the app can be sandboxed given
      Peekaboo's Screen Recording and Accessibility needs, or why it cannot.
- [ ] Records the SQLCipher evaluation with a decision and a measured performance figure.
- [ ] Lists the redaction points that keep secrets out of logs, crash dumps, prompts, push,
      screenshots, briefs and diagnostics exports.
- [ ] States residual risks in plain language, as §3.1 requires ("dokumentera kvarstående risker").

---

### S0-09 Write and approve ADR-004: provider-neutral AI layer

**Labels:** `adr`, `ai`
**Sprint:** 0 · **Workstream:** D (AI/Agents) · **Size:** M · **Depends on:** S0-02

Closes §15 item 4's AI half and §15 item 6 (local inference choices, quantized model licenses,
download size). §4 requires the official Laravel AI SDK at a **locked version** with its
compatibility and APIs **verified**, a thin provider adapter, and contract tests. Onboarding must be
brand-neutral with no preselected model (§4, §12.3.5), only options a real adapter supports may be
shown (§1.1), and GoZero Core may never appear as a built-in option (§0, §4).

Create `docs/adrs/004-provider-neutral-ai.md`.

- [ ] Pins the Laravel AI SDK version and cites https://laravel.com/docs/13.x/ai-sdk for the APIs
      actually relied upon.
- [ ] Defines how `AiProviderAdapter` (§3) maps onto the SDK, and the contract-test matrix each
      adapter must pass.
- [ ] Specifies capability-aware routing (text, structured output, tools, vision, embedding,
      context, streaming, cost limits, concurrency) and the **fail-closed** behaviour when a chosen
      model lacks a capability (§4).
- [ ] Specifies how `LOCAL_ONLY` is enforced at the adapter boundary with no silent fallback, and
      how it is tested (§10.1, §14).
- [ ] Names the candidate local models with their licenses, quantization and download sizes, and
      flags any that cannot be redistributed (§10.9).

---

### S0-10 Write and approve ADR-005: Peekaboo and MCP security model

**Labels:** `adr`, `security`, `mcp`
**Sprint:** 0 · **Workstream:** G (MCP/Automation) · **Size:** L · **Depends on:** S0-02

Closes §15 item 5 (Peekaboo Mac permissions, supported UI-session scenarios, safe app/window
allowlist, helper signing model) and §15 item 4's MCP-transport half. §6 and §7 set hard limits:
never work around TCC or the lock screen, never read or bypass passwords or 2FA codes, never run an
arbitrary package with full rights, and treat tool descriptions and MCP responses as untrusted input
with policy decided in code rather than in a prompt. §7 feature-gates general third-party local MCP
installs until a real process sandbox is verified.

Create `docs/adrs/005-peekaboo-mcp-security.md`.

- [ ] Specifies the Peekaboo trust model: pinned verified version, release source, checksum and
      signature verification, app/window allowlist, per-action authorization, screenshot redaction,
      timeouts, panic stop.
- [ ] Documents macOS session dependence (screen off, locked screen, logout, FileVault boot) and
      the manual unlock steps automation may require (§6).
- [ ] Defines the helper signing and IPC model: separate approved `launchd` service, protected local
      IPC or loopback, session-bound token in Keychain, versioning, origin check, request signing.
- [ ] Defines MCP client transports, trust tiers, the allowlisted local runtime for v1, and what
      stays feature-gated — citing https://modelcontextprotocol.io/specification and
      https://laravel.com/ai/mcp.
- [ ] States explicitly that no TCC, lock-screen or 2FA bypass is attempted, and lists the residual
      risks that remain after all controls.

---

### S0-11 Write and approve ADR-006: private remote access

**Labels:** `adr`, `security`, `pwa`
**Sprint:** 0 · **Workstream:** A (macOS/Swift) · **Size:** M · **Depends on:** S0-02

Closes §15 item 8 (PWA origin, secure cookies, push, Tailscale Serve ACLs, tested macOS Tailscale
distribution). §8 fixes the recommended adapter as Tailscale **Serve** on a private tailnet,
explicitly **not Funnel**, with local HTTP on `127.0.0.1` and a single tunnelled origin. Automatic
tunnel setup may never silently enable public exposure, and Cloudflare is at most a future optional
adapter, never the Europe-first default.

Create `docs/adrs/006-private-remote-access.md`.

- [ ] Specifies the exact Laravel configuration for the tunnelled origin: `APP_URL`,
      `TrustProxies`, secure cookie, CSRF and session settings.
- [ ] Documents which macOS Tailscale distribution is supported and tested, citing
      https://tailscale.com/docs/features/tailscale-serve and the Serve CLI reference.
- [ ] Defines the tailnet ACL posture that confirms only the PWA origin is reachable, not the whole
      machine.
- [ ] Defines the opt-in push model: minimal, time-limited payloads, no customer text in cleartext,
      and the iOS home-screen-PWA prerequisite (§8).
- [ ] States that no configuration path can enable Funnel or any public exposure without a separate
      explicit user action.

---

### S0-12 Write and approve ADR-007: license and compliance boundaries

**Labels:** `adr`, `compliance`, `docs`
**Sprint:** 0 · **Workstream:** J (Docs/community) · **Size:** M · **Depends on:** S0-02

Closes §15 item 10. The project license is already decided as MIT, so this ADR's real subject is the
half §15.10 leaves open: **legal review of the bundled executables and third-party model licenses**.
§10 is blunt that open-source distribution is not a general exemption and that a local model does not
automatically make its training data, license or use compliance-free (§10.9). §0 forbids any claim of
formal "EU AI Act certification".

Create `docs/adrs/007-license-and-compliance.md`, `docs/compliance/third-party-licenses.md`.

- [ ] Records MIT for the project's own code and confirms MIT compatibility for every bundled
      binary and dependency, per artifact.
- [ ] Lists each bundled executable (PHP, HTTP server, any helper) with its license and
      redistribution conditions, with a citation.
- [ ] Lists candidate local models with license, redistribution and commercial-use terms, flagging
      any that cannot ship with the app.
- [ ] Defines what the product may and may not claim about GDPR and the EU AI Act, with the forbidden
      phrasings written out.
- [ ] Names the open items that require a qualified legal reviewer rather than an engineer, and marks
      them as such.

---

### S0-13 Freeze the Executive Brief JSON schema

**Labels:** `laravel`
**Sprint:** 0 · **Workstream:** B (Laravel platform) · **Size:** M · **Depends on:** S0-01

§1.3 mandates that a brief is assembled and **frozen transactionally once**, then rendered to the
same semantic data in SwiftUI and in HTML mail, with content divergence forbidden. That is only
enforceable if all three surfaces read one versioned schema. Freeze it in Sprint 0 so Swift, Blade
and the PWA can be built against it in parallel during Sprint 1.

Create `docs/architecture/brief-schema.md`, `packaging/fixtures/brief.schema.json`.

- [ ] Schema defines every field named in §1.3: `id`, `version`, `generated_at`, `period_start`,
      `period_end`, `subject`, `summary`, `items[]`, `sources[]`, `action_ids[]`,
      `model_provenance`, `delivery_state`, `stale_after`.
- [ ] Each `items[]` entry can carry either source references or an explicit uncertainty marker,
      per §4.2's rule that every material claim is sourced or marked uncertain.
- [ ] `delivery_state` and an idempotency key make double delivery on retry representable and
      detectable (§1.3).
- [ ] The schema file validates against a JSON Schema validator in CI.
- [ ] Schema is versioned, and the document states the compatibility rule for adding fields.

---

### S0-14 Define the health check contract

**Labels:** `laravel`
**Sprint:** 0 · **Workstream:** B (Laravel platform) · **Size:** S · **Depends on:** S0-01

§12.2 defines `ready` as requiring HTTP health, migrations, login, menu integration, local brief
placeholder, background worker **and** scheduler self-test — and `AI-ready` as a separate state
requiring a successful model completion. §0 is emphatic that an install without a model must show
**AI setup required**, never a false **AI ready**. The installer and the menu bar both gate on this
contract, so it must be specified before either is written.

Create `docs/architecture/health-contract.md`.

- [ ] Specifies `/health/live` and `/health/ready` response shapes, status codes and timeouts.
- [ ] `ready` enumerates each §12.2 subcheck as an individually reportable item.
- [ ] `ai_ready` is a distinct field that is false until a real model completion has succeeded.
- [ ] Defines the stale-brief, offline and error states the menu bar must be able to display (§1.1).

---

### S0-15 Define the Swift to Laravel IPC contract v1

**Labels:** `macos`, `security`
**Sprint:** 0 · **Workstream:** A (macOS/Swift) · **Size:** M · **Depends on:** S0-14, S0-08

§2 puts "localhost authenticated IPC / loopback HTTPS" between the SwiftUI app and the Laravel core,
and §6 requires all IPC to have versioning, an origin check and request signing where relevant, with
a session-bound token in the Keychain. Specify it once, up front, because both the installer (A) and
the core (B) implement opposite ends of it in Sprint 1.

Create `docs/architecture/ipc-contract.md`.

- [ ] Defines the transport (loopback port or Unix socket), the bind address, and why nothing is
      reachable off-host.
- [ ] Defines authentication: session-bound token issued at install, stored in the Keychain, scoped,
      rotatable and revocable.
- [ ] Defines the versioning and origin-check rules, and the behaviour on version mismatch.
- [ ] Enumerates the v1 operations the menu bar needs: read health, read latest brief, list pending
      approvals, pause AI actions, stop/start services.
- [ ] States that no IPC operation can mutate data without going through the same server-side policy
      checks as the PWA (§11).

---

### S0-16 Add PHP interface stubs for the §3 module contracts

**Labels:** `laravel`
**Sprint:** 0 · **Workstream:** B (Laravel platform) · **Size:** M · **Depends on:** S0-01, S0-13

§3 gives four interfaces as **contract examples, not drop-in code**, and says the implementation
requires explicit typing, transaction handling, exceptions, cancellation and policy checks. Land them
as typed stubs with their DTOs so D, E, F and G can develop against stable signatures from Sprint 2
onward without blocking on each other.

Create `app/AI/Providers/AiProviderAdapter.php`, `app/CRM/WorkspaceRepository.php`,
`app/Browser/ComputerUse/ComputerUseProvider.php`, `app/Support/Install/TunnelProvider.php`, and the
DTOs they reference (`ModelCapabilities`, `AiRequest`, `AiResponse`, the `*Query` objects,
`AutomationSession`, `Observation`, `AuthorizedUiAction`, `ActionResult`, `TunnelStatus`,
`TunnelConfig`, `TunnelResult`).

- [ ] Every method has explicit parameter and return types; no `mixed` and no untyped arrays.
- [ ] Iterable returns carry generics docblocks with imported class names, per the project PHP
      guidelines.
- [ ] Cancellation and failure are expressible in the signatures, not left to convention.
- [ ] A PHPStan/Larastan run at the project's configured level passes on the new files.
- [ ] Each interface docblock cites the §3 paragraph it implements.

---

### S0-17 Add the EmailConnector interface stub

**Labels:** `integrations`
**Sprint:** 0 · **Workstream:** F (Integrations) · **Size:** S · **Depends on:** S0-16

§5.6 gives the `EmailConnector` contract as a design proposal whose **semantics must be reviewed
against the actual SDKs**. Landing the stub in Sprint 0 lets the Sprint 3 provider adapters —
Google, Microsoft, iCloud, HEY, generic IMAP, Proton Bridge — be built in parallel by different
agents against one shape. Microsoft 365/Outlook is mandatory for v1 per §5.6, so the contract must
fit Graph delta queries as naturally as it fits Gmail history IDs.

Create `app/Integrations/Mail/EmailConnector.php` plus `EmailConnectionDraft`,
`AuthorizationFlow`, `EmailConnection`, `ConnectionHealth`, `SyncCursor`, `SyncBatch`,
`EmailMessage`, `ApprovedOutgoingEmail`, `SendReceipt`.

- [ ] All seven §5.6 methods present with explicit types.
- [ ] `SyncCursor` can represent a Gmail history ID, a Graph delta token and an IMAP
      UID+UIDVALIDITY+modseq triple without a provider-specific branch in the interface.
- [ ] `send()` accepts only an `ApprovedOutgoingEmail` whose sender, recipients and body hash are
      immutable once approved (§5.6, §11).
- [ ] A docblock notes that semantics remain provisional until verified against each vendor SDK.

---

### S0-18 Freeze the brief and CRM fixture pack

**Labels:** `release`
**Sprint:** 0 · **Workstream:** I (QA/release) · **Size:** M · **Depends on:** S0-13

§13.1 requires a fixture pack in Sprint 0 and says parallel teams ship vertical slices **against
shared fixtures**. §16.3 needs one hardcoded fixture rendered identically in three surfaces, and
§4.2 requires hallucination and language evaluation (Swedish, English, Italian, German) on **frozen**
fixtures. Frozen means byte-stable: a snapshot test that drifts because a fixture moved is worthless.

Create `packaging/fixtures/briefs/`, `packaging/fixtures/crm/`, `packaging/fixtures/README.md`.

- [ ] One canonical brief fixture validates against `brief.schema.json` and is referenced by name in
      the snapshot tests.
- [ ] Fixtures cover the awkward cases: an item with no source, conflicting sources, a stale brief,
      an empty period, and non-ASCII content in all four required languages.
- [ ] CRM fixtures include two customers sharing an email domain, so §5.6's explainable-matching rule
      has something to fail against.
- [ ] `README.md` states the freeze policy: fixtures change only via a PR that updates the affected
      snapshots deliberately.

---

### S0-19 Freeze the adversarial security fixture pack

**Labels:** `security`, `mcp`
**Sprint:** 0 · **Workstream:** H (Security/privacy) · **Size:** M · **Depends on:** S0-18

§14 requires a prompt-injection red-team corpus and hostile MCP-server fixtures as named automated
suites, and §7 names tool poisoning explicitly: model instructions inside a `tool description`, a
web page, an email or an MCP response are untrusted input. These fixtures must exist before any tool
execution code does, so the attacks are written by someone who is not also writing the defence.

Create `packaging/fixtures/security/prompt-injection/`,
`packaging/fixtures/security/hostile-mcp/`, `tests/Security/README.md`.

- [ ] Injection corpus covers at least: instructions in email body, instructions in an MCP tool
      description, instructions in fetched web content, attempted privilege escalation, attempted
      exfiltration to an attacker-controlled address, and attempted From-identity change.
- [ ] Hostile MCP fixtures cover a server that lies about its capabilities, one that mutates its tool
      schema between calls, and one that returns an unexpected redirect.
- [ ] Each fixture records the **expected refusal**, not merely the input.
- [ ] Corpus is in all four §4.2 languages where language is relevant to the attack.

---

### S0-20 Build the install-timer runner (§16 step 6)

**Labels:** `release`, `macos`
**Sprint:** 0 · **Workstream:** I (QA/release) · **Size:** M · **Depends on:** S0-14

§16 step 6 requires an install-timer runner that measures **every mandatory phase** and exports JSON
results. §12.2 then needs at least 30 measured installs per Mac profile against `p50 < 180s` /
`p95 < 300s` for core-ready, with network-conditional AI setup labelled separately. §0 is explicit
that this is a release gate to be **proven**, and that large model downloads, OAuth and permissions
must not be hidden inside the five-minute figure.

Create `packaging/manifest/install-timer/` with the runner, and
`docs/operations/install-measurement.md`.

- [ ] Emits one JSON record per run with a timestamp per installer state from §12.1
      (`WELCOME`..`READY`), plus machine profile, macOS version and whether the runtime was cached.
- [ ] Separates core-ready timing from asynchronous post-core steps in the output structure, so the
      two can never be accidentally summed.
- [ ] Aggregates N runs into p50 and p95 and fails the gate if core-ready p50 ≥ 180s or p95 ≥ 300s.
- [ ] Records human-interaction time as its own phase, since §12.2 requires user prompts to be
      included.
- [ ] `docs/operations/install-measurement.md` documents the protocol well enough for an outside
      contributor to reproduce it (§17).

---

### S0-21 Write the failing negative security test (§16 step 7)

**Labels:** `security`
**Sprint:** 0 · **Workstream:** H (Security/privacy) · **Size:** M · **Depends on:** S0-19, S0-16

§16 step 7: a foreign email asks the agent to send customer data, and the system **refuses**. Author
it in Sprint 0 against the §3/§5.6 contracts, where it will fail because no `PolicyGate` exists yet.
That is the point — it is the executable definition of the boundary, and it turns green in Sprint 2
when the gate lands. §11 requires that externally collected text is treated as data and never as
instruction, and §5.6 requires that mail-borne prompt injection can never raise tool permissions or
trigger outgoing mail.

Create `tests/Security/ForeignEmailCannotTriggerSendTest.php`.

- [ ] Test is committed, runs in CI and is explicitly marked as an expected-failing gate with a link
      to this issue, so a green CI is not faked by skipping it.
- [ ] Asserts no outbound send is attempted, not merely that the final answer was a refusal.
- [ ] Covers the From-identity variant: the injected instruction cannot change the sending account
      (§5.6).
- [ ] Asserts an audit event is recorded for the refusal (§11).
- [ ] A comment states the exact Sprint 2 condition under which the expected-failure marker is
      removed.

---

### S0-22 Record the support matrix with measurements

**Labels:** `macos`, `adr`
**Sprint:** 0 · **Workstream:** A (macOS/Swift) · **Size:** M · **Depends on:** S0-06

Closes §15 item 1: supported macOS versions, supported Mac chips, and the **actual** minimum RAM and
disk. §2 requires clear macOS 15+ and Apple Silicon compatibility and says the team must document
whether Intel is supported **or explicitly not**. §12.1 preflight checks are unimplementable until
these numbers exist, so this blocks Sprint 1's installer work.

Create `docs/architecture/support-matrix.md`. Touch `docs/adrs/001-macos-runtime-packaging.md`.

- [ ] States the minimum macOS version and the maximum tested version, with the reason for the floor.
- [ ] States Apple Silicon support and gives an explicit yes or no for Intel — no silence.
- [ ] Minimum RAM and free disk are **measured** on a real install, not estimated.
- [ ] Defines the exact preflight checks §12.1 requires: macOS version, chip, disk, RAM,
      permissions, port collisions, network state, existing install, FileVault state, developer
      signature.
- [ ] `README.md` requirements section matches this document.

---

### S0-23 Write the baseline threat model

**Labels:** `security`
**Sprint:** 0 · **Workstream:** H (Security/privacy) · **Size:** L · **Depends on:** S0-08, S0-10, S0-15

§13.1 requires a baseline threat model as a Sprint 0 artifact and §11 lists the gates it must cover.
§11 specifically demands threat modelling of indirect injection, exfiltration and browser-based
attacks; §3.1 demands documented residual risk for the unencrypted local SQLite file; §6 demands the
Mac helper privilege boundary be reasoned about rather than assumed.

Create `docs/security/threat-model.md`.

- [ ] Enumerates trust boundaries: PWA ↔ core, Swift ↔ core IPC, core ↔ model provider, core ↔ MCP
      server, core ↔ mail provider, core ↔ Peekaboo helper, local disk, Keychain.
- [ ] Each §11 gate maps to at least one concrete threat with a named mitigation and the test that
      proves it.
- [ ] Covers the SSRF surface explicitly: loopback, RFC1918, cloud metadata endpoints, Unix sockets,
      redirect revalidation, protocol and port restrictions.
- [ ] Covers data at rest: what FileVault does and does not protect while the user is logged in.
- [ ] Lists accepted residual risks, each with a named accepting role (§13, workstream H DoD
      requires "signed risk acceptance").

---

### S0-24 Establish the design system foundation and tokens

**Labels:** `pwa`
**Sprint:** 0 · **Workstream:** C (TALL/PWA design) · **Size:** M · **Depends on:** S0-01

§1.1 asks for a sober, premium, own visual identity that is explicitly **not a CleanMyMac copy**, and
§13.1 lists design tokens as a Sprint 0 artifact. Tokens must be shared source-of-truth values so
SwiftUI, Blade HTML mail and the PWA cannot drift — §1.3 forbids content divergence between surfaces,
and visual drift undermines the same promise.

Create `resources/css/tokens.css`, `docs/architecture/design-system.md`, and a token export for
Swift under `apps/macos/`.

- [ ] Token set covers color (light and dark), type scale, spacing, radius, elevation and motion.
- [ ] Tokens are exported in a form consumable by Tailwind, by Blade HTML mail (inline-safe) and by
      SwiftUI.
- [ ] HTML-mail token subset is restricted to what degrades well in Apple Mail, Gmail and HEY (§1.3).
- [ ] Contrast ratios meet WCAG AA, since §13 workstream C must pass accessibility review.
- [ ] Document states the identity direction in one paragraph and names what it deliberately avoids.

---

### S0-25 Land the empty SwiftUI app shell that builds in CI

**Labels:** `macos`
**Sprint:** 0 · **Workstream:** A (macOS/Swift) · **Size:** M · **Depends on:** S0-05, S0-22

§13.1 requires a "working empty shell of Swift + Laravel" as a Sprint 0 exit artifact. The shell
proves the build, the project layout and the CI integration before any installer logic exists. Keep
it genuinely empty: a window, a `MenuBarExtra` with placeholder content, and nothing else.

Create `apps/macos/` Xcode project with the app target and a test target.

- [ ] `xcodebuild` of the scheme succeeds in the S0-05 CI job on a macOS runner.
- [ ] Deployment target matches the S0-22 support matrix.
- [ ] A `MenuBarExtra` appears and renders placeholder text.
- [ ] One XCTest exists and runs in CI, so the test lane is proven, not just configured.
- [ ] No signing identity or provisioning profile is required for the CI build.

---

### S0-26 Land the empty Laravel 13 app that builds in CI

**Labels:** `laravel`
**Sprint:** 0 · **Workstream:** B (Laravel platform) · **Size:** M · **Depends on:** S0-05, S0-07

The Laravel half of §13.1's "working empty shell". Install Laravel 13 at the repo root per §2.1 with
the TALL stack (Livewire, Alpine, Tailwind) and **no Filament** (§0). Configure SQLite in WAL mode
and the database queue driver per §2 and ADR-002, and nothing else — features arrive in Sprint 1.

Touch repo root: `composer.json`, `package.json`, `config/`, `routes/{web,api,console}.php`,
`database/`, `resources/{views,js,css}`, `phpunit.xml` or `pest.php`.

- [ ] Laravel 13 installed at the repo root, not in a subdirectory.
- [ ] Livewire, Alpine and Tailwind present; Filament absent from `composer.json`.
- [ ] Default connection is SQLite with WAL enabled, and the queue connection is `database`.
- [ ] One test exists and the test suite runs green in CI.
- [ ] `.env.example` committed with no secrets and no MySQL/Redis requirement.

---

### S0-27 Apply branch protection, required checks and CODEOWNERS review rules

**Labels:** `release`, `security`
**Sprint:** 0 · **Workstream:** I (QA/release) · **Size:** S · **Depends on:** S0-05

§13.2 requires protected `main`, required CI, two reviewers for security-critical modules and an
enforced CODEOWNERS. **Needs repository-admin rights — Andreas must apply or delegate the settings
themselves**; the engineering deliverable is the exact configuration to apply, plus the CODEOWNERS
entries that make the two-reviewer rule bite on the right paths.

Touch `/CODEOWNERS`. Create `docs/contributing/branch-protection.md`.

- [ ] Document states the exact required status checks by the job names from S0-05.
- [ ] CODEOWNERS routes `app/Security/`, `app/Approvals/`, `app/MCP/`, `app/Integrations/` and
      `packaging/signing/` to the security owner group.
- [ ] Linear history or squash policy is stated, consistent with §13.2's conventional-commits rule.
- [ ] Document names who holds admin rights and therefore who can change these settings.

---

### S0-28 Define the release, versioning and changelog policy

**Labels:** `docs`, `release`
**Sprint:** 0 · **Workstream:** J (Docs/community) · **Size:** S · **Depends on:** S0-04

§13.2 requires conventional commits and semantic versioning for published releases, with a changelog
and a **signed tag**. §14's release checklist adds published checksums and hashes, an SBOM and a
license audit. Writing this in Sprint 0 keeps Sprint 1's signed debug builds from inventing their own
version scheme.

Create `docs/operations/release-policy.md`, `CHANGELOG.md`.

- [ ] Commit-message convention stated with examples, including the types CI will enforce.
- [ ] Version scheme covers the Mac app, the Laravel core and the bundled runtime, and says how they
      relate.
- [ ] Release checklist transcribed from §14 as an actionable list.
- [ ] Tag signing requirement and key custody are stated.

---

## Sprint 1 — Vertical slice

> Gate: Sprint 0 exit requires all DRIs to approve contracts (§13.1). ADR-001, ADR-002 and ADR-003
> must be Accepted before Sprint 1 items begin.

### S1-01 Implement the minimal Laravel core (§16 step 4)

**Labels:** `laravel`
**Sprint:** 1 · **Workstream:** B (Laravel platform) · **Size:** L
**Depends on:** S0-26, S0-13, S0-14

§16 step 4 names exactly what this is: a minimal Laravel 13 app with SQLite, auth, `/health/live`,
`/health/ready`, `briefs` migrations, a database queue and scheduler, and an install-state marker.
Implement to the S0-14 health contract and the S0-13 brief schema. Per §3.1, every business object
gets a ULID/UUID, `organization_id` where relevant, timestamps, retention policy, `source_id`/
`source_uri`, sensitivity class and provenance from the start — retrofitting those columns across
forty tables later is the expensive version of this task.

Create migrations under `database/migrations/` for `users`, `organizations`, `memberships`, `briefs`,
`brief_items`, `brief_deliveries`, `audit_events`; `app/Support/Health/`, `app/Support/Install/`,
`routes/web.php`, `routes/api.php`, `routes/console.php`.

- [ ] `/health/live` and `/health/ready` match the S0-14 contract field for field, including a
      separate `ai_ready` that is false with no provider configured.
- [ ] `briefs` tables express `version`, `delivery_state`, `stale_after` and an idempotency key that
      makes a double send detectable (§1.3).
- [ ] Database queue and scheduler run, and the scheduler self-test §12.2 requires reports through
      `/health/ready`.
- [ ] An install-state marker distinguishes not-installed, installing, core-ready and ai-ready.
- [ ] SQLite runs in WAL mode and the app boots with no MySQL, Redis, Docker or Homebrew present.

---

### S1-02 Implement owner account creation and local authentication

**Labels:** `laravel`, `security`
**Sprint:** 1 · **Workstream:** B (Laravel platform) · **Size:** M · **Depends on:** S1-01

§12.1's `CREATE_OWNER` state and §12.3 screen 4 require a local owner account with a credential or
passkey where supported. §11 requires secure sessions, session revocation and re-auth for critical
actions. Sprint 1 needs the owner account to exist and log in; full MFA/passkey hardening and device
management belong to Sprint 2, but the session and revocation design must not need replacing then.

Create `app/Security/Authorization/`, migrations for sessions, `resources/views/auth/`.

- [ ] A single owner account can be created through the installer flow with no Terminal use (§12.1).
- [ ] Login works in the PWA and sessions are revocable server-side.
- [ ] The credential is never written to logs, the brief, or any diagnostic export (§11).
- [ ] A test asserts a second owner cannot be silently created by a repeated install.
- [ ] Passkey/WebAuthn support is either implemented or has a stub with a linked Sprint 2 issue — not
      silently omitted.

---

### S1-03 Render the brief as Blade HTML mail with a local preview mode

**Labels:** `laravel`
**Sprint:** 1 · **Workstream:** B (Laravel platform) · **Size:** M · **Depends on:** S1-01, S0-18, S0-24

§16 step 3 requires the fixture rendered in Blade HTML mail, and §1.3 requires the mail to be
attractive, accessible and to degrade well in Apple Mail, Gmail and HEY — plus a local preview mode
that sends nothing. §5.5 forbids sending any mail before a recipient and sending account are
confirmed, so Sprint 1 must be preview-only by construction, not by configuration.

Create `app/Briefs/Render/`, `resources/views/mail/brief.blade.php`, a preview route under
`routes/web.php`.

- [ ] Renders the S0-18 canonical fixture with both HTML and plaintext parts (§5.5).
- [ ] Preview mode is reachable in the PWA and provably cannot dispatch outgoing mail — asserted by
      a test using a failing mail transport.
- [ ] Every item shows its source references or its explicit uncertainty marker (§4.2).
- [ ] Inline styles only, within the S0-24 mail-safe token subset.
- [ ] Renders correctly with the awkward fixtures: no sources, conflicting sources, empty period,
      non-ASCII content.

---

### S1-04 Build the PWA app shell with /home and /briefs

**Labels:** `pwa`
**Sprint:** 1 · **Workstream:** C (TALL/PWA design) · **Size:** L · **Depends on:** S1-01, S0-24

§1.2 requires a genuinely installable PWA: manifest, icons, HTTPS for remote access, service worker,
responsive desktop/tablet/iPhone layout. Sprint 1 delivers the shell plus the two routes the slice
needs. §1.2's caching rule is a hard constraint from the first commit: cache **only** the app shell,
user-approved harmless data and unsent drafts — never customer letters, tokens, credentials or
high-sensitivity content in the service worker or IndexedDB.

Create `resources/views/layouts/`, `resources/views/home/`, `resources/views/briefs/`,
`public/manifest.json`, `public/icons/`, `resources/js/service-worker.js`.

- [ ] Installable on macOS Safari/Chrome and as an iPhone home-screen app; manifest and icons
      validate.
- [ ] `/home` and `/briefs` render the fixture brief from the server.
- [ ] Service worker caches only the app shell; a test or documented audit shows no brief content,
      token or credential is cached.
- [ ] No UI copy anywhere claims AI runs on the phone (§1.2).
- [ ] Responsive at iPhone, tablet and desktop widths with no horizontal body scroll.

---

### S1-05 Implement the installer state machine

**Labels:** `macos`
**Sprint:** 1 · **Workstream:** A (macOS/Swift) · **Size:** L
**Depends on:** S0-25, S0-06, S0-22, S0-14

§12.1 fixes the sequence: `WELCOME → PREFLIGHT → PACKAGE_VERIFY → INSTALL_CORE → CREATE_OWNER →
SELECT_AI → BOOT_SERVICES → HEALTH_CHECK → READY`, with the optional path
`CONNECT_EMAIL → CONNECT_GITHUB → PEEKABOO_PERMISSIONS → REMOTE_ACCESS → SCHEDULE_BRIEF` kept
separate. §12.1 also requires that progress reflects **real** checkpoints and supports cancel, resume
and rollback with actionable errors — a fake progress bar fails this item.

Create `apps/macos/Sources/Install/` with the state machine, preflight checks and package
verification.

- [ ] All nine core states implemented, with the optional path reachable only after `READY`.
- [ ] Preflight runs every check from S0-22 and shows blockers only (§12.3 screen 2).
- [ ] `PACKAGE_VERIFY` verifies vendor checksum and signature before anything executes (§6).
- [ ] Cancel, resume and rollback are each covered by a test, including an interrupted
      `INSTALL_CORE`.
- [ ] No state ever asks the user to paste a shell command (§12.1), and no admin password is
      requested until an OS-protected operation genuinely needs one (§12.2).

---

### S1-06 Install the app-owned immutable runtime and user data directory

**Labels:** `macos`
**Sprint:** 1 · **Workstream:** A (macOS/Swift) · **Size:** M · **Depends on:** S1-05, S0-06

§12.1 requires the bootstrap helper to install an app-owned, immutable, **versioned** runtime into
Application Support — not into user-owned random directories — with a separate mutable user data
directory under managed backups. This separation is what later makes binary rollback possible without
touching user data (§9).

Create `apps/macos/Sources/Install/RuntimeInstaller.swift`, `packaging/manifest/runtime.json`.

- [ ] Runtime lands in a versioned, app-owned Application Support path and is not user-writable.
- [ ] User data lives in a separate, clearly documented path.
- [ ] The runtime manifest pins versions and checksums, and installation fails closed on a mismatch.
- [ ] Two runtime versions can coexist on disk, so rollback is physically possible (§9).
- [ ] Uninstall can remove the runtime while optionally preserving user data (§12.2).

---

### S1-07 Supervise core services with launchd / SMAppService

**Labels:** `macos`
**Sprint:** 1 · **Workstream:** A (macOS/Swift) · **Size:** M · **Depends on:** S1-06, S1-01

§2 and §9 require `launchd`/`SMAppService` with automatic launch, crash restart, bounded retries and
health probes, and demand clarity about whether user login is required. §2 forbids `php artisan serve`
as the production server. §16 step 5 requires kill/restart and uninstall to be verified on a clean
Mac, not merely on a dev machine.

Create `apps/macos/Sources/Services/`, `packaging/manifest/launchd/`.

- [ ] Web server and the worker/scheduler are separately supervised processes (§2).
- [ ] Killing either one restarts it, with bounded retries and a visible failure state after the
      bound.
- [ ] Health probes use the S0-14 contract, not a process-exists check.
- [ ] Documentation states explicitly whether user login is required for services to run (§9).
- [ ] Uninstall removes the service registrations cleanly, verified on a clean Mac.

---

### S1-08 Implement the authenticated loopback IPC on both sides

**Labels:** `macos`, `security`
**Sprint:** 1 · **Workstream:** A + B · **Size:** M · **Depends on:** S0-15, S1-01, S1-07

Implement the S0-15 contract: the Swift client and the Laravel server end. §6 requires versioning, an
origin check and request signing where relevant, with a session-bound token in the Keychain. This is
a security-critical module, so §13.2's two-reviewer rule applies.

Create `apps/macos/Sources/IPC/`, `app/Support/Health/IpcController.php`, middleware under
`app/Security/Authorization/`.

- [ ] Token is created at install, stored in the Keychain, and never written to a log or a plist.
- [ ] A request with a missing, wrong or expired token is rejected, with a test per case.
- [ ] A version mismatch fails closed with an actionable message, not a silent degradation.
- [ ] The endpoint is not reachable from another host on the network — asserted by a test.
- [ ] No IPC route bypasses the server-side policy layer (§11).

---

### S1-09 Build the MenuBarExtra panel against the fixture brief

**Labels:** `macos`
**Sprint:** 1 · **Workstream:** A (macOS/Swift) · **Size:** L · **Depends on:** S1-08, S0-18, S0-24

§1.1 specifies the panel's contents precisely: the latest brief's date and generation time, system
status, tasks awaiting approval, action shortcuts, "Open full brief", "Pause AI actions", "Open PWA",
"Settings" and "Quit UI" — which is **not** the same as stopping the server. Stale brief, offline and
error states must be clearly visible, and a notification badge may appear only for real action items.
§14 requires the menu to open instantly from the cached brief and forbids generating a brief on every
menu open.

Create `apps/macos/Sources/MenuBar/`.

- [ ] Every element §1.1 lists is present, and "Quit UI" demonstrably leaves services running.
- [ ] Stale, offline and error states each render distinctly, driven by the S0-14 health fields.
- [ ] The panel opens from a cached brief with no network or generation work on open (§14).
- [ ] A badge appears only when there is a real pending action, not for informational updates.
- [ ] No secret, token or customer text appears in any menu-bar surface (§11).

---

### S1-10 Prove tri-surface brief parity with snapshot tests (§16 step 3)

**Labels:** `release`
**Sprint:** 1 · **Workstream:** I (QA/release) · **Size:** M
**Depends on:** S1-03, S1-04, S1-09

§16 step 3 requires the same fixture rendered in the SwiftUI menu bar, in Blade HTML mail and in the
PWA, with semantic equivalence **proven by snapshot tests**. §1.3 forbids content differences
outright, and §17 makes "one versioned brief renders identically in PWA, menu bar and email" a v1
Definition-of-Done item. Compare the semantic payload — fields, values, item order, source
references — not pixels.

Create `tests/E2E/BriefParityTest.php`, `apps/macos/Tests/BriefParitySnapshotTests.swift`,
`docs/architecture/brief-parity.md`.

- [ ] A single fixture drives all three renderers in one test run.
- [ ] The comparison is on extracted semantic data, so a layout change cannot silently break the
      gate and a content change cannot silently pass it.
- [ ] Adding a field to one surface and not the others fails the test — verified by deliberately
      breaking it once.
- [ ] `version` and `generated_at` are asserted identical across all three.
- [ ] The test runs in CI on every PR touching any brief renderer.

---

### S1-11 Render the "AI setup required" state correctly

**Labels:** `macos`, `pwa`, `ai`
**Sprint:** 1 · **Workstream:** A + C · **Size:** S · **Depends on:** S1-09, S1-04

§0 is unambiguous: without an available model or API key, the app must be installed and clearly show
**AI setup required**, never a false **AI ready**. §12.2 reinforces it — an install with no external
LLM account has an operational PWA and CRM with a "Select AI" banner and **does not count as
AI-ready**. §12.3 screen 5 adds that local runtimes may be detected and suggested but no brand may be
preselected.

Touch `apps/macos/Sources/MenuBar/`, `apps/macos/Sources/Install/` (`SELECT_AI`),
`resources/views/layouts/`.

- [ ] With no provider configured, both the menu bar and the PWA show "AI setup required".
- [ ] `ai_ready` is false until a real model completion has succeeded (§12.2).
- [ ] `SELECT_AI` presents options with no preselected provider or model, and lists only options a
      real adapter supports (§1.1).
- [ ] A detected local runtime is suggested without being preselected.
- [ ] A test asserts that no copy path can display "AI ready" while `ai_ready` is false.

---

### S1-12 Produce a signed debug DMG from CI (§16 step 5)

**Labels:** `macos`, `release`
**Sprint:** 1 · **Workstream:** A + I · **Size:** L · **Depends on:** S1-07, S0-05, S0-28

§16 step 5 requires a signed internal Mac build produced **by CI**, with launchd process management,
kill/restart and uninstall verified on a clean Mac. §12.1 requires distribution as a signed,
notarized DMG; Sprint 1 may stay at signed-debug, with full notarization and stapling as a Sprint 4
release gate (§14). **Needs an Apple Developer signing identity and CI secrets — Andreas must
provide or delegate access.**

Create `packaging/signing/`, `.github/workflows/build-macos.yml`.

- [ ] CI produces a signed DMG artifact on every push to `main`.
- [ ] Signing credentials live only in CI secrets and never appear in logs or the repo (§11).
- [ ] The DMG opens and installs on a Mac that has never seen the source, with Gatekeeper enabled.
- [ ] `codesign --verify` and `spctl` assessment results are recorded as build output.
- [ ] Documentation states what is still missing before notarization (§14) and links the Sprint 4
      issue.

---

### S1-13 Implement uninstall and data-preserving reinstall

**Labels:** `macos`, `laravel`
**Sprint:** 1 · **Workstream:** A + B · **Size:** M · **Depends on:** S1-06, S1-12

§12.2 requires 100% end-to-end coverage of fresh install, re-install, upgrade, failed upgrade,
interrupted install, uninstall **and** data-preserving reinstall. §1.1 lists Uninstall as a Settings
surface. Building uninstall in Sprint 1, while there are only a handful of artifacts on disk, is far
cheaper than reverse-engineering it in Sprint 4.

Create `apps/macos/Sources/Install/Uninstaller.swift`, `docs/operations/uninstall.md`.

- [ ] Uninstall removes the runtime, launchd registrations, Keychain items and the app, with an
      explicit choice to keep or delete user data.
- [ ] Reinstall over preserved data boots to the previous owner account and the previous briefs.
- [ ] An interrupted uninstall can be resumed or safely retried.
- [ ] A test or documented clean-machine run verifies nothing is left behind when data deletion is
      chosen.
- [ ] `docs/operations/uninstall.md` tells a user exactly what is removed and what remains.

---

### S1-14 Run and publish the first clean-install measurement

**Labels:** `release`
**Sprint:** 1 · **Workstream:** I (QA/release) · **Size:** M
**Depends on:** S1-12, S0-20, S1-13

Sprint 1's exit criterion in §13.1 is literally *"first clean install measured"*. Use the S0-20
runner. §12.2 sets the eventual gate at ≥30 installs per profile on Mac A (clean M4 Max, 36–64 GB)
and Mac B (clean M3 Ultra) with `p50 < 180s` / `p95 < 300s`; Sprint 1 needs the first credible run and
an honest number, not a passing number. §0: this is a gate to be **proven**, and model downloads,
OAuth and permission grants must be reported separately.

Create `docs/operations/install-measurements/2026-sprint1.md`, JSON output under
`packaging/manifest/install-timer/results/`.

- [ ] At least 10 measured runs on at least one clean machine profile, with raw JSON committed.
- [ ] Per-phase breakdown identifies the slowest mandatory phase by name.
- [ ] Core-ready timing is reported separately from any asynchronous post-core step.
- [ ] If p50 or p95 misses the §12.2 target, a performance issue is opened with a named owner —
      the number is reported as found, never adjusted.
- [ ] The protocol is documented well enough for an outside contributor to reproduce the run (§17).

---

### S1-15 Automate the §16 step 8 vertical slice end to end

**Labels:** `release`
**Sprint:** 1 · **Workstream:** I (QA/release) · **Size:** L
**Depends on:** S1-10, S1-11, S1-13

§16 step 8 defines the slice: *Install → Create owner → Open PWA → Generate seeded brief → Show same
brief in menu bar → Preview exact email*. §16 step 9 makes this the gate on everything else: external
integrations, Peekaboo and MCP may only start in parallel **once this slice is stable**. Automate it
so "stable" means a green run on a clean machine rather than someone's recollection.

Create `tests/E2E/VerticalSliceTest.php` plus the driver under
`packaging/fixtures/e2e/`. §14 permits Playwright internally even though the product does not depend
on it.

- [ ] One automated run covers all six slice steps against a freshly installed instance.
- [ ] No external integration, network model call or third-party account is involved (§13.1).
- [ ] The run asserts the menu-bar brief and the email preview carry the same `version` as the PWA.
- [ ] Runs in CI, and is required before any Sprint 3 workstream issue may be started.
- [ ] A documented clean-machine run is recorded, since CI cannot fully simulate a fresh Mac.

---

## Sprint 2 — Real core

> Gate: §16 step 9 — the §16 step 8 vertical slice must be stable (S1-15, green in CI, recorded on a
> clean machine) before these items start. ADR-004 through ADR-007 gate this sprint, not the slice.
> **Exit (§13.1): security checks and brief identity** — automated by **S2-20**.
>
> **The epics below appear in dependency order, not numeric order**, and items are numbered
> sequentially through that order: **E2-2** (schema) first because CRM, chat and brief all write new
> tables; then **E2-3** (auth, which owns the re-auth primitive) and **E2-4** (PolicyGate and
> approvals); then **E2-1**, **E2-5** and **E2-6** in parallel; then the exit gate.
>
> **Frozen contracts.** ADR-001…ADR-007, the Executive Brief schema **1.0.0**
> (`docs/architecture/brief-schema.md`), the health contract and the Swift↔Laravel IPC contract
> **1.0.0** (`docs/architecture/ipc-contract.md`) are frozen. Every item below states what it may
> change; an item that needs a schema or wire change names the bump (brief schema: optional field →
> minor 1.1.0; anything else → major). An ADR that is wrong is amended **in the same PR** as the code,
> per the A19/A24 precedent. No item may add a mutating approval operation to `/ipc/` — that is a new
> contract major plus a security review (ipc-contract §11.2, §11.5).
>
> **Owner decisions that gate specific items:** **A25** → S2-04 (hard block). **§15.9 / A2** → S2-18
> (scope confirmation only). **A14** → S2-20's required-check entry. **A15** and **A18** are `ENG` and
> are closed *by* S2-14. See `docs/OPEN-QUESTIONS.md` "Sprint 2 gating".

### E2-2 Full §3.1 schema

### S2-01 Land the §3.1 CRM and source tables on the Sprint 1 conventions

**Labels:** `laravel`, `crm`
**Sprint:** 2 · **Workstream:** B (Laravel platform) with E (CRM) · **Size:** L
**Depends on:** S1-01 (#29)
**Issue:** #84
**Contracts:** amends **ADR-002** twice, in this PR — **D9** moves from *Proposed* to final (closing
ambiguity **A3**), and **D10**'s `migrations` group drops its `migrate:rollback` clause, which
contradicts the `up()`-only migration rule the repository already follows
(`docs/architecture/data-model.md` header; `MigrationsContractTest` already omits the rollback
assertion). No schema, health or IPC contract change.

§3.1 lists the tables and §3.2 makes the local CRM the system of record for customers, contacts,
communications, projects and todos. ADR-002 **D9** is the decision this item makes final: `email_messages`
and `email_threads` (E3-2) are the provider system of record and **are** §3.1's `messages` and `threads`
— no separate tables are created — while `communications` is the source-agnostic CRM timeline
projection, one row per link between a source item and a customer or contact, keyed by
`source_type`/`source_id`. Every row carries the §3.1 common columns through the existing
`App\Support\Database\BusinessObjectColumns` (ULID `char(26)`, `organization_id`, `source_id`/`source_uri`,
`sensitivity_class`, `provenance`, `retention_policy`/`retain_until`, timestamps, soft delete), so the
forty-table retrofit §3.1 warns about never happens. The S0-18 fixture pack is the acceptance oracle:
`packaging/fixtures/crm/` already carries two customers sharing an email domain (§5.6), the explainable
match fields and the remembered follow-ups.

Create migrations for `customers`, `contacts`, `customer_contact`, `projects`, `project_links`, `tasks`,
`task_assignees`, `communications`, `commitments`, `source_records`; `app/Models/CRM/`;
`app/CRM/Search/FullTextSearch.php` with the `contacts_fts`, `customers_fts` and `communications_fts`
external-content FTS5 tables and their triggers (ADR-002 D4);
`tests/Integration/BackendContract/CrmSchemaContractTest.php`,
`tests/Feature/CRM/CrmFixtureRoundTripTest.php`. Extend `docs/architecture/data-model.md`.

- [ ] Every §3.1 CRM and source table exists with the `BusinessObjectColumns` set, ULID `char(26)`,
      explicitly named indexes and foreign keys, `string` + PHP enum instead of `enum` columns, and UTC
      `dateTime` throughout (ADR-002 D10).
- [ ] Migrations are **`up()` only**; ADR-002 D10's `migrate:rollback` clause is amended in the same PR
      and the `migrations` contract group asserts `migrate` from zero with zero pending on both profiles.
- [ ] `communications` carries `source_type`, `source_id`, `customer_id`, `contact_id`, `match_reason`,
      `matched_by` (`rule` · `ai` · `user`), `confidence` and `correction_of`, with **no** foreign key to
      `email_messages` — that table lands in E3-2 (ADR-002 D9.2).
- [ ] Every record in `packaging/fixtures/crm/*.json` round-trips through the tables with identical field
      values, including the two customers that share an email domain (S0-18, §5.6).
- [ ] The `backend-contract` suite passes on **both** ADR-002 D1 profiles (`local` SQLite and `server`
      MySQL 8.4) inside `DatabaseTransactions` — never `RefreshDatabase`, never `migrate:fresh`.
- [ ] The FTS5 `remove_diacritics` setting is decided against a Swedish fixture corpus — ADR-002 D4
      defers it to E2-1 — and the `search` contract group asserts the same hit **set** on both profiles.

---

### S2-02 Land the AI, approvals, tool-run and memory tables

**Labels:** `laravel`, `ai`, `security`
**Sprint:** 2 · **Workstream:** B (Laravel platform) with D (AI) and H (security) · **Size:** M
**Depends on:** S2-01 (#84)
**Issue:** #85
**Contracts:** no frozen contract change. Closes a gap in **ADR-004**, which names `tool_runs` only in
prose (D7, "`trace_id` propagated to tool runs") and never specifies it — the `/assistant` tool-activity
surface (§1.2) has nowhere else to read from, so the table is specified here.

E2-4's PolicyGate and E2-5's run loop both write here and neither can start without the tables.
ADR-005 **D1.6** names the `action_proposals` columns (app, window title, element role and label, action
type, redacted value, screenshot hash, expiry, nonce) and `action_approvals` (approver identity, time);
§11 adds the content hash, idempotency and fail-closed requirements that ADR-005 does not state and the
S0-16 DTOs already assume (`AuthorizedUiAction`, `ApprovedOutgoingEmail` carry `proposalId`,
`approvalId`, `approverUserId`, a content hash, `nonce`, `approvedAt`, `expiresAt`). ADR-004 **D7** names
the thirteen `ai_runs` provenance fields and the dedupe key `sha256(job_class, normalized_input, model_id)`;
ADR-004 **D5** names the eight `ai_providers` region, DPA and retention evidence fields. ADR-003 **D7**
rows 3, 4 and 8 are binding column constraints: prompts and transcripts pass the `Redactor` on write, and
`tool_runs` and `audit_events` carry no binary column and no image path.

Create migrations for `ai_providers`, `ai_models`, `ai_runs`, `tool_runs`, `action_proposals`,
`action_approvals`, `memories`, `memory_sources`, `browser_sessions`; `app/Models/AI/`,
`app/Models/Approvals/`; `tests/Integration/BackendContract/AiAndApprovalsSchemaContractTest.php`,
`tests/Security/AuditNoBlobTest.php`. Extend `docs/architecture/data-model.md`.

- [ ] `action_proposals` is immutable after freeze: a `proposal_hash` over the canonical RFC 8785 form of
      the frozen payload (`App\Support\Json\Jcs`, the brief's rule reused), `nonce`, `expires_at`, and an
      idempotency key with a unique index.
- [ ] `action_approvals` is unique per `proposal_id` **and** per `nonce`, so a replayed approval is
      unrecordable rather than merely detectable — the technique `brief_deliveries_single_sent_unique`
      already uses (`docs/architecture/data-model.md`).
- [ ] `ai_runs` carries ADR-004 D7's provenance set, a ULID `trace_id`, an outcome vocabulary that includes
      `cancelled`, token and cost columns the €5.00-per-day cap sums, and the dedupe key under a unique index.
- [ ] `tool_runs` and `audit_events` have no binary column and no image path; `AuditNoBlobTest` asserts it
      for both (ADR-003 D7 row 8).
- [ ] `ai_providers` carries ADR-004 D5's eight evidence fields and ADR-007 D6's R1–R7, and stores only
      `secret_service` / `secret_account` / `secret_version` — never a key, token or password, encrypted or
      not (ADR-003 D1).
- [ ] The `backend-contract` suite passes on both ADR-002 D1 profiles.

---

### S2-03 Land the governance, retention and operations tables

**Labels:** `laravel`, `compliance`
**Sprint:** 2 · **Workstream:** B (Laravel platform) with H (security) · **Size:** M
**Depends on:** S2-01 (#84)
**Issue:** #86
**Contracts:** no frozen contract change. Picks up the two additions to the §3.1 minimum that live in
other ADRs: `push_subscriptions` (**ADR-002 D9.5**, from ADR-006) and the MCP registry trio
(**ADR-005 D4.4, D4.6**). Lands the storage half of **A18** for S2-14 to build the rule on.

§3.1's remaining tables, plus the ones the §3.1 list does not carry. `data_retention_rules` is what the
`retention_policy` and `retain_until` columns on **every** business object already point at
(`docs/architecture/data-model.md` §1.1) — a dangling policy name is a row that silently never expires, so
the rules table lands with the schema and not with the retention UI. `email_connections` lands empty under
exactly that name (**ADR-002 D8** resolved ambiguity **A4**; `connected_email_accounts` appears nowhere), so
E3-1's eight provider adapters write into a schema that already exists — ROADMAP's Sprint 2 gate is explicit
that churn after Sprint 3 starts is expensive across eight adapters. `mcp_servers.trust_tier`,
`mcp_servers.version` and `mcp_tools.schema_hash` land now because E3-5's rug-pull protection (ADR-005 D4.6:
a changed `tools/list` suspends every grant until re-review) needs columns to compare against.
`privacy_policies` is where §10.1's three modes live.

Create migrations for `external_accounts`, `sync_cursors`, `email_connections`, `email_sync_cursors`,
`privacy_policies`, `data_retention_rules`, `backup_runs`, `health_events`, `push_subscriptions`,
`mcp_servers`, `mcp_tools`, `mcp_grants`; `app/Security/Retention/RetentionRules.php`;
`tests/Integration/BackendContract/GovernanceSchemaContractTest.php`,
`tests/Feature/Retention/RetentionRuleResolutionTest.php`. Create `docs/architecture/retention.md`.

- [ ] `data_retention_rules` resolves a named rule for every `retention_policy` value any table writes, and
      an unknown rule name fails `RetentionRuleResolutionTest` rather than producing a row that never expires.
- [ ] `privacy_policies` stores the active mode (`LOCAL_ONLY` · `EU_RESTRICTED` · `CUSTOM`) and the
      `LOCAL_ONLY_ALLOW_LAN` toggle with the changing user and the audit event id — the storage half of
      **A18**, consumed by S2-14 (threat model T-68).
- [ ] `email_connections` and `email_sync_cursors` exist under those names and hold a Keychain **reference**
      only (ADR-002 D8, ADR-003 D1); `email_sync_cursors` is one row per `(connection_id, scope)` with
      `value` sized for `SyncCursor::encode()` at ≤ 16 KiB plus envelope (module-contracts.md §6).
- [ ] `mcp_tools` is unique per `(server_id, tool_name)` and carries `schema_hash`; `mcp_servers` carries
      `trust_tier` and `version` (ADR-005 D4.4, D4.6).
- [ ] `push_subscriptions` is unique on `endpoint` and carries `p256dh`, `auth`, `user_id`, `last_seen`,
      `revoked_at` (ADR-002 D9.5).
- [ ] The `backend-contract` suite passes on both ADR-002 D1 profiles.

---

### S2-04 Encrypt the §3.1 sensitive attributes at rest — the A25 gate

**Labels:** `security`, `laravel`, `compliance`
**Sprint:** 2 · **Workstream:** H (Security/privacy) with A (macOS/Swift) · **Size:** M
**Depends on:** S2-01 (#84), S2-02 (#85), S2-03 (#86), **and the A25 decision**
**Issue:** #87
**Contracts:** amends **ADR-003 D8** in this PR with the A25 answer, and `docs/operations/uninstall.md` §5
with what a keep-data uninstall now does. **This is the only Sprint 2 item permitted to add an `encrypted:`
cast**; an arch test enforces that for every other item.
**Blocked on: A25** (`DECISION` — H with A). **Buildable before the decision: nothing in this item.**
**Buildable without this item:** every table, index and contract test in S2-01…S2-03, and every CRM, chat
and brief item — all with plaintext columns classified by `sensitivity_class`. The cost of a late answer is
that this item grows a backfill migration re-writing existing rows through the cast; the PR must state the
row count it rewrote.

§3.1 requires sensitive attributes encrypted at rest and ADR-003 **D1** permits Laravel `encrypted:` casts
for sensitive attributes that are **not** credentials — credentials stay in the Keychain, and no column may
hold a token, password, key or verifier, encrypted or not. But ADR-003 **D8** promises that uninstall
enumerates and deletes **every** `se.stafe.executive-ai.*` Keychain item, `core.app-key` included, and S1-13
(#41) ships that literally even when the person chooses *Keep my data*. **A25** records the consequence: from
the first `encrypted:` cast, a keep-data uninstall followed by a reinstall leaves rows nobody can decrypt,
and D8's `previous[]` rotation envelope cannot recover a key that was deleted. The recommended answer is
option (a) — a keep-data uninstall keeps `core.app-key` and only it, and D8's wording becomes "every item
except the application key when data is kept" — but it is H's call with A, and it must be made **before**
this item merges rather than discovered afterwards.

Create `app/Models/Concerns/EncryptsSensitiveAttributes.php`, the casts on the named models,
`app/Security/Secrets/AppKeyRotation.php` (ADR-003 D8's queued re-encryption job),
`tests/Security/EncryptedAttributeInventoryTest.php`, `tests/Security/AppKeyRotationTest.php`. Extend
`docs/architecture/data-model.md`, `docs/operations/uninstall.md` and `docs/OPEN-QUESTIONS.md` **A25**.

- [ ] **A25 is answered** in `docs/OPEN-QUESTIONS.md`, ADR-003 D8 is amended in this PR, and
      `docs/operations/uninstall.md` §5 states exactly what a keep-data uninstall does with `core.app-key`.
- [ ] `EncryptedAttributeInventoryTest` enumerates every `encrypted:` cast under `app/Models/` and fails on
      one absent from the documented inventory, so ADR-003 D8's re-encryption job can never fall behind the
      casts; the same test fails on an `encrypted:` cast added by any item other than this one.
- [ ] `AppKeyRotationTest` proves the queued rotation re-encrypts every inventoried attribute and that a row
      written under a `previous[]` key still decrypts (ADR-003 D8, E35).
- [ ] No cast holds a token, password, key or verifier (ADR-003 D1, Forbidden 1) — asserted by an arch test
      over the inventory, not by review.
- [ ] A keep-data uninstall followed by a reinstall reads back every encrypted row, proven by extending
      `tests/Feature/Install/ReinstallPreservesDataTest.php`.

---
### E2-3 Authentication and authorization hardening

### S2-05 Passkeys, device management and re-authentication for critical actions

**Labels:** `laravel`, `security`
**Sprint:** 2 · **Workstream:** B (Laravel platform) with H (security) · **Size:** L
**Depends on:** S1-02 (#30)
**Issue:** #69 (already open; reused, not duplicated)
**Contracts:** no frozen contract change. Deletes the `NotYetAvailable` binding, `PasskeyStubTest` and the
login-page status row in the same PR, and rewrites `docs/architecture/auth.md` §7 rather than leaving it
describing a stub.

§11 requires passkeys/WebAuthn or MFA, secure sessions, session revocation, device management and re-auth
for critical actions. S1-02 shipped the owner account with a six-digit code by mail, server-side revocable
sessions, and a deliberate stub — `App\Security\Authorization\Passkeys\PasskeyAuthenticator` bound to
`NotYetAvailable`, which reports "Passkeys arrive in Sprint 2 (E2-3)" and refuses every ceremony, with this
issue linked from the login page (`docs/architecture/auth.md` §7). **A passkey is an addition, never a
replacement:** the emailed one-time code stays the fallback and the recovery path, `users.password` stays
nullable and unread, and no password route may appear (`AuthRoutesTest`). Re-authentication is the primitive
S2-08's approval UI consumes and S2-19's admin panes gate on — threat model **T-71** names three critical
paths (approvals, remote-access enable, key rotation) and `ReauthRequiredTest` per action as the proof;
device management is built on S1-02's `SessionRevoker` rather than replacing it (auth.md §5).

Create `app/Security/Authorization/Passkeys/WebAuthnAuthenticator.php`,
`app/Security/Authorization/Reauthentication/`, migrations for `passkey_credentials` and the device list,
`app/Livewire/Auth/Passkeys.php`, `app/Livewire/Auth/Devices.php`, `resources/views/livewire/auth/`;
`tests/Feature/Auth/PasskeyRegistrationTest.php`, `tests/Feature/Auth/PasskeyLoginTest.php`,
`tests/Feature/Auth/DeviceManagementTest.php`, `tests/Security/ReauthRequiredTest.php`. Extend
`docs/architecture/auth.md`.

- [ ] Registration and authentication ceremonies work on both ADR-006 D3 origins with `rp.id` per origin;
      the emailed one-time code remains available as the fallback and the recovery path; there is still no
      password route and `users.password` is still never written.
- [ ] Device management lists and revokes registered passkeys **and** active sessions over S1-02's
      `SessionRevoker` primitive (auth.md §5), and a revoked device is signed out on its next request.
- [ ] `ReauthRequiredTest` covers one case per critical action named in threat model T-71, and proves a
      merely-fresh session does not satisfy it: re-auth is bound to the action, a nonce and a time window.
- [ ] The `NotYetAvailable` stub, `PasskeyStubTest` and the login-page status row are **removed** in this PR
      and auth.md §7 is rewritten.
- [ ] Threat model **T-70** and **T-71** move from `planned-S1 → S2` to `mitigated` in the same PR.
- [ ] The credential never reaches a log, the audit trail, the brief or a diagnostics export — the
      `OtpCredentialNeverLoggedTest` family stays green and is extended to the WebAuthn payloads.

---

### S2-06 Policies on every action, job and tool, with IDOR and tenant negative tests

**Labels:** `security`, `laravel`
**Sprint:** 2 · **Workstream:** H (Security/privacy) with B (Laravel platform) · **Size:** L
**Depends on:** S2-01 (#84), S2-02 (#85), S2-03 (#86)
**Issue:** #88
**Contracts:** no frozen contract change. Generalises `tests/Security/IpcPolicyArchTest.php` (S1-08) from the
`/ipc/` surface to the whole application; the `cross-account` Pest group named by **ADR-002 D10** gets its
first real content.

§11 requires policies and gates in **every** HTTP action, Livewire action, queued job, MCP tool, AI tool and
integration call, with IDOR and multi-tenant cross-access negative tests. Sprint 1 proved the pattern on one
surface (`BriefPolicy`, plus `IpcPolicyArchTest` failing CI on an `/ipc/` route without a named ability);
Sprint 2 multiplies the surfaces by an order of magnitude, so enforcement has to be **structural** — an arch
test that fails on an unguarded surface, not a reviewer remembering. Threat model **T-73** states the
requirement as automated negative tests across all five paths (API, Livewire action, agent, queued job, MCP
tool) and names the `cross-account` group in `tests/Integration/BackendContract/`, which ADR-002 D10 runs on
**both** backend profiles. **T-46** adds `AuditCompletenessTest` per surface, because the audit row is the
only forensic artefact a refusal leaves behind and the audit table is not tamper-evident in v1 (RR-05).

Create `app/Policies/` (one per §3.1 business object), `app/Security/Authorization/Gates/`;
`tests/Security/PolicySurfaceArchTest.php`, `tests/Security/IdorTest.php`,
`tests/Integration/BackendContract/CrossAccountContractTest.php`,
`tests/Security/AuditCompletenessTest.php`. Create `docs/architecture/authorization.md`.

- [ ] `PolicySurfaceArchTest` enumerates every route, Livewire component, queued job, AI tool and MCP tool
      and fails on one that reaches a business object without a named ability — the `IpcPolicyArchTest` shape,
      applied application-wide.
- [ ] `IdorTest` proves a ULID from another organization is a 403 on read, write and delete for **every**
      §3.1 object, and that an id in a request body is never trusted for identity (ipc-contract §11.4's rule,
      applied to the PWA).
- [ ] The `cross-account` Pest group runs on both ADR-002 D1 profiles and carries the §5.6 criterion-4 shape
      for the tables that exist in Sprint 2; the provider half stays E3-1's.
- [ ] `AuditCompletenessTest` asserts an `audit_events` row for every denial and every mutating action, with
      `context` through the `Redactor` and never a blob or an image path (ADR-003 D7 row 8).
- [ ] There is **no admin bypass**: an owner or admin role widens which organizations are visible, never which
      rules apply — the `BriefCatalog::visibleTo()` precedent (`docs/architecture/pwa.md` §2).
- [ ] Threat model **T-73**'s Sprint 2 half moves to `mitigated`; the E3-1 provider half stays `planned-S3`.

---

### E2-4 PolicyGate and the approvals engine

### S2-07 Implement the server-side PolicyGate

**Labels:** `security`, `ai`
**Sprint:** 2 · **Workstream:** H (Security/privacy) with D (AI) · **Size:** L
**Depends on:** S2-02 (#85), S2-06 (#88)
**Issue:** #89
**Contracts:** no frozen contract change. `App\Approvals\Policies\PolicyDeniedException` (S0-16) stays the
base of every server-side refusal and every subclass keeps its place in the one catch at the agent loop
(`docs/architecture/module-contracts.md` §1).

§4 is the rule the whole product rests on: the LLM generates **proposals, never its own permissions**, and a
server-side `PolicyGate` validates tool name, input schema, org/account/scope, data class, destination,
budget and approval **before** execution. ADR-004 **D2**'s tools row puts the gate inside
`SdkToolBridge::handle()`, before the wrapped tool runs, and its contract matrix **row 3** requires that a
denial produces a tool result the model can read with nothing executed. ADR-005 **D4.8** adds that all policy
— allowlist, tier, grant, approval, egress, kill — is implemented in PHP policies and Swift code paths and
**never in prompt text**, and that tool names, descriptions and results are stored and rendered as data.
`tests/Security/Support/AgentRunUnderTest.php` is the seam S0-21 already drives and
`tests/Security/README.md` already fixes the refusal assertions (`tool_call:email.send` never invoked, a
`PolicyGate` denial recorded, no queued mailable, no `action_approvals` row).

Create `app/Approvals/Policies/PolicyGate.php`, `app/Approvals/Policies/Rules/`,
`app/AI/Providers/LaravelAi/SdkToolBridge.php`, `tests/Security/Support/PolicyGatedAgentRun.php`;
`tests/Security/PolicyGateTest.php`, `tests/Security/PolicyGateFailsClosedTest.php`,
`tests/Security/PolicyIsNotPromptTest.php`. Create `docs/architecture/policy-gate.md`.

- [ ] The gate validates all seven §4 inputs — tool name, input schema, org/account/scope, data class,
      destination, budget, approval — and an unknown tool, an unknown data class or a missing budget is a
      refusal, not a default-allow (`PolicyGateFailsClosedTest`).
- [ ] The gate runs **before** the wrapped tool in `SdkToolBridge::handle()`; a denial returns a
      model-readable tool result and nothing executes (ADR-004 D2, matrix row 3).
- [ ] Every refusal raises a `PolicyDeniedException` subclass and writes an `audit_events` row naming the
      rule that refused, with no customer content in `context`.
- [ ] `PolicyIsNotPromptTest` fails on a rule that reads a tool description, a model output or any prompt text
      to reach its decision (ADR-005 D4.8), and on a `PolicyGate` call placed after the wrapped tool.
- [ ] A mutating tool reached without a matching `action_approvals` row is refused and the refusal names the
      missing approval — the half S0-21 needs before S2-08 can remove its expected-failure marker.
- [ ] `docs/architecture/policy-gate.md` records every rule with its inputs, failure mode and test, so E3-5's
      MCP tools and E3-6's automation add **rules**, not new code paths (ADR-005 D4.10's forward constraint:
      a mutating MCP tool must be able to create a proposal and return "pending approval" instead of acting).

---

### S2-08 Implement the approvals engine and /approvals, and turn S0-21 green

**Labels:** `security`, `pwa`
**Sprint:** 2 · **Workstream:** H (Security/privacy) with C (TALL/PWA) · **Size:** L
**Depends on:** S2-07 (#89), S2-05 (#69) · **Turns S0-21 (#21) green**
**Issue:** #90
**Contracts:** **no IPC contract bump.** `GET /ipc/v1/approvals` keeps its specified response shape and
`App\Support\Health\PendingApprovals` is rebound from `NoPendingApprovals` to the real query; the menu bar's
`pending_approvals` badge (`docs/architecture/menubar.md`) starts showing real rows. Adding **any** mutating
approval operation to `/ipc/` is forbidden — ipc-contract §11.2 makes "approve from the menu bar" a new
contract major plus a security review, "not a minor bump". `/approvals` is already on the service-worker deny
list (`docs/architecture/pwa.md` §4), so the cache policy does not change.

§11 fixes the shape — frozen immutable proposal with input, recipient and hash, approver identity, expiry,
nonce, idempotency, fail closed — and states that mail send, CRM deletion, GitHub write and external forms
are **always** approval-driven. ADR-005 **D1.6** and ipc-contract **§11.2** fix *where*: approvals are issued
only from an authenticated, **re-authenticated** UI action, never from a prompt or a tool result, and never
over IPC with or without elevation. §1.2 names the five states the route shows: pending, approved, rejected,
expired, cancelled. The S0-16 DTOs already encode the invariants — `ApprovedOutgoingEmail` and
`AuthorizedUiAction` may be constructed only under `app/Approvals` (`AuthorizedActionProvenanceTest`), cannot
exist with an empty `approvalId` or `nonce`, and verify their own body hash — so this item supplies the rows
and the ceremony those DTOs have been waiting for since Sprint 0.

Create `app/Approvals/ProposalFreezer.php`, `app/Approvals/Actions/`, `routes/approvals.php`,
`app/Livewire/Approvals/`, `resources/views/approvals/`; `tests/Feature/Approvals/ApprovalLifecycleTest.php`,
`tests/Security/GrantOriginTest.php`, `tests/Security/ApprovalReplayTest.php`,
`tests/Security/ApprovalsFailClosedTest.php`. Create `docs/architecture/approvals.md`.

- [ ] A proposal is frozen once in a transaction with a content hash over its canonical RFC 8785 form, an
      `expires_at` and a nonce; nothing can edit it afterwards and an expired proposal can only be
      re-proposed, never revived.
- [ ] Approving consumes the nonce **exactly once**: a replayed approval is refused and cannot insert a second
      `action_approvals` row (`ApprovalReplayTest`), and `AuthorizedActionProvenanceTest` stays green.
- [ ] `GrantOriginTest` proves an approval cannot be created from a prompt, a tool result, a model output or
      any `/ipc/` route — with or without elevation (ADR-005 D1.6, ipc-contract §11.2) — and that approving
      requires the S2-05 re-authentication (threat model T-06, T-71).
- [ ] `/approvals` shows pending, approved, rejected, expired and cancelled (§1.2); the menu bar badge and
      `GET /ipc/v1/approvals` return the real pending set without a contract bump (the operation is unchanged; the contract is at 1.1.0 since #121).
- [ ] `tests/Security/ForeignEmailCannotTriggerSendTest.php`'s `ExpectedFailure::guard()` wrapper is
      **removed** and all four S0-21 cases pass on their own assertions; threat model **T-01, T-05, T-06** and
      **T-09** move to `mitigated`.
- [ ] `ApprovalsFailClosedTest` proves that with the approvals tables unreachable every approval-driven action
      refuses rather than proceeding (§11 "fail closed").

---
### E2-1 Local CRM core

### S2-09 Build the CRM CRUD screens for customers, contacts, projects and tasks

**Labels:** `crm`, `pwa`
**Sprint:** 2 · **Workstream:** E (CRM) with C (TALL/PWA) · **Size:** L
**Depends on:** S2-01 (#84), S2-06 (#88)
**Issue:** #91
**Contracts:** implements `App\CRM\WorkspaceRepository` (S0-16) over the S2-01 schema — the read API the
agent, the MCP `crm.search` tool and the brief generator all share, returning `*Summary` read models with a
`WorkspaceScope` and a `QueryPage`. Livewire screens may use Eloquent directly; the contract is the
agent/brief read API, not the UI's data layer (`docs/architecture/module-contracts.md` §3). The repository
stays **read-only** — mutations go through policies and, where §11 requires it, through S2-08 proposals.

§13.1's Sprint 2 line opens with "Local CRM CRUD" and §3.2 says what the records are: customers with a legal
identity and relationships, contacts with several email addresses, projects with status and deadlines, tasks.
§1.2 fixes the routes — `/crm/customers`, `/crm/contacts`, `/crm/projects`, `/crm/tasks`. The S0-18 fixture
pack already defines every field (`packaging/fixtures/crm/crm.schema.json`), so the screens have an acceptance
corpus before the first line is written. Two constraints bite from the first commit: §0 bans Filament, so this
is an own TALL surface on Livewire, Alpine and the S0-24 tokens under S1-02's CSP nonce with no CDN
(`docs/architecture/pwa.md` §1); and §4.2 requires that `AI unavailable` never blocks the CRM.

Create `app/CRM/Eloquent/EloquentWorkspaceRepository.php`, `app/Livewire/Crm/`, `routes/crm.php`,
`resources/views/crm/`; `tests/Feature/CRM/CustomersCrudTest.php`, `ContactsCrudTest.php`,
`ProjectsCrudTest.php`, `TasksCrudTest.php`,
`tests/Integration/BackendContract/WorkspaceRepositoryContractTest.php`,
`tests/Feature/CRM/CrmWorksWithoutAiTest.php`. Create `docs/architecture/crm.md`.

- [ ] The four §1.2 routes create, read, update and delete their records; Livewire and Alpine load through
      `@livewireScripts` with `Vite::cspNonce()` and no CDN asset, and Filament is absent from
      `composer.json` (§0, pwa.md §1).
- [ ] `EloquentWorkspaceRepository` satisfies ADR-002 D10's `repository` contract group on **both** profiles
      with identical ids, through `WorkspaceScope` and `QueryPage` — a query without a scope is unrefusable
      and a query without a page is unbounded (module-contracts.md §3).
- [ ] Deleting a customer or contact goes through an S2-08 proposal and approval (§11: CRM deletion is always
      approval-driven), never a direct destroy; a soft delete respects the row's retention rule (S2-03).
- [ ] Every screen is policy-guarded by S2-06 and renders at 375, 768 and 1280 px with no horizontal body
      scroll and 44 px touch targets (pwa.md §6).
- [ ] `CrmWorksWithoutAiTest` proves every CRM route works with `ai_state != ready` and that no copy claims AI
      is required; `AiReadyGateTest` is extended to the new routes so "AI ready" still appears on exactly one
      code path (§4.2, ai-state.md §3).
- [ ] FTS5 search over customers and contacts returns the same hit set on both profiles (ADR-002 D4, the
      `search` contract group).

---

### S2-10 Build the activity timeline with visible sources and remembered follow-ups

**Labels:** `crm`
**Sprint:** 2 · **Workstream:** E (CRM) · **Size:** L
**Depends on:** S2-09 (#91)
**Issue:** #92
**Contracts:** no frozen contract change. Implements `WorkspaceRepository::timeline()` over the
`communications` projection ADR-002 **D9.2** defines, so the screen, the agent tools and the brief generator
read the same rows.

§3.2's hardest sentence is *"Aktivitetstidslinje ska visa källor och komma ihåg lovade uppföljningar"* — a
timeline that shows where every entry came from and does not forget a promise. ADR-002 **D9.2** makes
`communications` one row per link between a source item and a customer or contact, carrying `match_reason`,
`matched_by` and `confidence`, precisely so a match is **explainable** and correcting it is a row edit rather
than a migration. §5.6 criterion 6 requires that the user can correct a link and always reach the original
message, and §5.6 forbids assuming one email domain belongs to one customer — the S0-18 fixtures carry exactly
that trap (two customers sharing a domain). `commitments` (§3.1) is where a promise lives, with
`follow_up_task_id` and `brief_item_id` so a forgotten follow-up becomes a task here and a brief item in
S2-17. §5.6 also requires AI suggestions to be visibly separated from actions actually performed.

Create `app/CRM/Communications/Timeline.php`, `app/CRM/Communications/MatchExplanation.php`,
`app/CRM/Communications/MatchCorrection.php`, `app/Livewire/Crm/Timeline.php`,
`resources/views/crm/timeline/`; `tests/Feature/CRM/TimelineSourcesTest.php`,
`tests/Feature/CRM/ExplainableMatchingTest.php`, `tests/Feature/CRM/CommitmentFollowUpTest.php`,
`tests/Feature/CRM/AiSuggestionsAreSeparatedTest.php`. Extend `docs/architecture/crm.md`.

- [ ] Every timeline entry shows its source type, account label and a link to the original, read from
      `source_type` / `source_id` / `source_uri` — never inferred at render time (§3.2, brief-parity.md §1's
      resolved-sources rule applied to the CRM).
- [ ] A wrong match can be corrected in the UI; the correction writes a row with `matched_by: user` and
      `correction_of` pointing at the previous one, and the previous row stays readable (§5.6 criterion 6).
- [ ] `ExplainableMatchingTest` drives the two S0-18 customers that share an email domain and proves neither is
      matched by domain alone, and that the UI states the reason for each match.
- [ ] `CommitmentFollowUpTest` proves a commitment whose `due_at` has passed surfaces as a remembered
      follow-up and can become a task through `follow_up_task_id` (§3.2).
- [ ] AI-extracted entries carry `provenance.origin = ai_extraction` and are visibly distinct from recorded
      facts and from executed actions (§5.6, `AiSuggestionsAreSeparatedTest`).
- [ ] `WorkspaceRepository::timeline()` returns the same `ActivityEntry` set the screen renders, scoped and
      paged, on both backend profiles.

---

### S2-11 Implement CSV and JSON import and export

**Labels:** `crm`
**Sprint:** 2 · **Workstream:** E (CRM) · **Size:** M
**Depends on:** S2-09 (#91), S2-10 (#92)
**Issue:** #93
**Contracts:** no frozen contract change. Reuses ADR-002 D10's `sync-idempotency` contract group, which
already defines what "replaying the same batch twice yields one row each" means on both profiles.

§3.2 requires CSV and JSON import and export for the CRM. The import is the first place a user's own file
becomes rows in the system of record, so it is the first place §3.1's provenance rule bites: an imported row
carries `provenance.origin = import` and a `source_uri` identifying the file, never a null. It is also an
untrusted-input boundary (§11: externally collected text is **data, never instruction**) — a CSV cell is a
value, not a formula and not a prompt, and the S0-19 injection corpus has rows for exactly this carrier.
ADR-002 D3's write-path rules apply: batched upserts, idempotent, no network I/O inside the transaction.

Create `app/CRM/Import/`, `app/CRM/Export/`, `app/Console/Commands/CrmImportCommand.php`,
`app/Console/Commands/CrmExportCommand.php`, `app/Livewire/Crm/Import.php`;
`tests/Feature/CRM/ImportExportRoundTripTest.php`, `tests/Feature/CRM/ImportIdempotencyTest.php`,
`tests/Security/ImportIsDataNotInstructionTest.php`, `tests/Feature/CRM/ImportIsAllOrNothingTest.php`.
Extend `docs/architecture/crm.md`.

- [ ] A round trip exports every CRM object to JSON and re-imports it into an empty organization with
      identical field values and no duplicate rows, proven against `packaging/fixtures/crm/`.
- [ ] Running the same import twice produces one row per record — an idempotency key per
      `(source_uri, source_id)` under a unique index — asserted in the `sync-idempotency` group on both
      profiles (ADR-002 D10).
- [ ] Every imported row carries `provenance.origin = import`, the file's `source_uri` and an explicitly
      chosen `sensitivity_class`, never the column default by accident (§3.1).
- [ ] A CSV cell beginning `=`, `+`, `-` or `@` is exported quoted and imported as text, and no imported text
      reaches a prompt as instruction (`ImportIsDataNotInstructionTest` over the S0-19 corpus, §11).
- [ ] `ImportIsAllOrNothingTest` proves a malformed file either writes a complete batch or writes nothing and
      reports per row — never half an import.
- [ ] Import and export are policy-guarded by S2-06 and bounded by ADR-002 D3's 100–500-row batch rule.

---

### S2-12 Implement GDPR export, rectification and erasure

**Labels:** `crm`, `compliance`
**Sprint:** 2 · **Workstream:** E (CRM) with H (security) · **Size:** M
**Depends on:** S2-11 (#93), S2-03 (#86)
**Issue:** #94
**Contracts:** no frozen contract change. Bound by **ADR-007 D5**'s permitted and forbidden claim wording,
which the S0-05 copy linter enforces. The open legal item **L-6** (Art. 28 register wording and the
export/erasure feature descriptions) stays open and is cross-referenced, not pre-empted.

§3.2 requires GDPR export and erasure; §10.3 widens it to data minimisation, purpose limitation, a
legal-basis note, the data source, export, rectification, erasure, gallring, processors, an incident log and
an access log. ADR-007 **D5** fixes the only claim that may be made — *"**Supports** your GDPR obligations with
export, rectification, erasure, retention rules, an access log and a processor register"*, always "supports",
never "makes you compliant" — and "GDPR compliant", "GDPR certified" and "100 % GDPR" are forbidden strings.
Erasure is the operation most likely to be quietly wrong in a system with append-only logs: `audit_events` and
`brief_deliveries` have **no soft delete by design** (data-model.md §1), so this item must decide and document
what erasure does to them rather than skipping them, and must prove no residue survives in the FTS5 index
tables, the cache or a queue payload.

Create `app/Security/Retention/SubjectAccessExport.php`, `app/Security/Retention/Erasure.php`,
`app/Security/Retention/Rectification.php`, `app/Console/Commands/GdprExportCommand.php`,
`app/Console/Commands/GdprEraseCommand.php`, `app/Livewire/Crm/DataSubject.php`;
`tests/Feature/Compliance/SubjectAccessExportTest.php`, `tests/Feature/Compliance/ErasureTest.php`,
`tests/Feature/Compliance/ErasureLeavesNoResidueTest.php`,
`tests/Feature/Compliance/GdprClaimWordingTest.php`. Create `docs/architecture/gdpr.md`.

- [ ] An export for one data subject returns every row referencing them across every §3.1 table, with the
      source and legal-basis fields §10.3 names, in a machine-readable format and with its own audit row.
- [ ] `ErasureLeavesNoResidueTest` proves erasure leaves nothing in the FTS5 index tables, the cache, a queue
      payload, a `failed_jobs.exception` or a brief body — the places a `DELETE` does not reach.
- [ ] What erasure does to the append-only logs is **documented and tested, not skipped**: `audit_events`
      keeps the event and loses the personal data in `context`; the `brief_deliveries` decision is written
      down in `docs/architecture/gdpr.md` with its reason.
- [ ] Rectification writes a correction with `provenance.origin = user_correction` and keeps the prior value
      reachable for as long as the row's retention rule requires (S2-03).
- [ ] `GdprClaimWordingTest` sweeps every rendered page and every command's output for ADR-007 D5's forbidden
      phrasings and asserts the permitted "supports your obligations" wording is what ships.
- [ ] Export and erasure are policy-guarded by S2-06 and require the S2-05 re-authentication.

---
### E2-5 Assistant chat on a user-selected provider

### S2-13 Implement the laravel/ai adapter, the capability registry and the contract matrix

**Labels:** `ai`
**Sprint:** 2 · **Workstream:** D (AI/Agents) · **Size:** L
**Depends on:** S2-02 (#85)
**Issue:** #95
**Contracts:** changes `packaging/fixtures/ai-providers/ai-providers.json` (`adapter_available` per adapter),
`App\AI\Providers\SupportedProviders::catalogue()` and the Swift `AiProviderCatalog` mirror **in the same
PR** — that is the mechanism `docs/architecture/ai-state.md` §2 specifies, not a contract break, and
`SupportedProvidersTest` plus `ChooseAiScreenTests` force the two sides to agree. Adds
`resources/ai/models.json` as a maintained artefact with a review step per model (ADR-004 D3). Evaluates
ADR-004 **D6**'s OpenRouter gate — ships in v1 only if matrix rows 1–9 pass with no adapter-specific code,
otherwise post-v1 and never shown in the UI.

ADR-004 **D1** pins `laravel/ai` at exactly **1.2.0** and overrides the two SDK defaults that would break
brand neutrality: `config/ai.php`'s `'default' => 'openai'` becomes an undefined provider name so a forgotten
argument throws instead of silently calling OpenAI, and the Ollama driver's hard-coded default model is never
relied on — every request carries an explicit model (matrix **row 12**). **D2** confines SDK types to
`app/AI/Providers/LaravelAi` behind an arch test, passes `provider` as a **single** provider so the SDK's
silent `withModelFailover()` is unreachable (threat model T-66), and defines the **twelve-row** contract-test
matrix. **D3** makes `ModelCapabilities` ours because the SDK carries no per-model capability data, fixes the
precedence (registry → probe overlay → user override, a probe never overriding a registry `false`), the
unknown-model defaults (every capability `false` except `text`, `contextWindow` 4096) and the **five forbidden
downgrades** (T-67). ai-state.md §2 fixes how an adapter becomes visible: bind it under
`AiProviderAdapter::class.'@'.$id`, flip the fixture flag, nothing else.

Create `app/AI/Providers/LaravelAi/{LaravelAiAdapter,SdkAgentBridge,ErrorMapper,UsageMapper}.php`,
`app/AI/Providers/ProviderRegistry.php`,
`app/AI/Providers/Detection/{OllamaDetector,LmStudioDetector}.php`, `app/AI/Routing/ModelRouter.php`,
`resources/ai/models.json`; `tests/Integration/AI/Contract/` (the twelve rows),
`tests/Unit/AI/ModelRouterTest.php`, `tests/Unit/AI/ForbiddenDowngradesTest.php`,
`tests/Security/NoSdkTypeLeakArchTest.php`. Create `docs/architecture/ai-providers.md`.

- [ ] All **twelve** ADR-004 D2 matrix rows pass for every shipped driver against a fake gateway in CI; the
      real-endpoint half (Ollama, LM Studio, a test key) is a **lab** run recorded in ADR-004's Evidence
      section — CI never claims it.
- [ ] `ModelCapabilities` follows ADR-004 D3's precedence, an unknown model is every capability `false` except
      `text` with `contextWindow` 4096, and an empty routing result is a `CapabilityUnavailableException`
      naming the missing capabilities — fail closed, never degraded.
- [ ] Each of D3's five forbidden downgrades has its own negative test and none is reachable: no regex-parsed
      structured output, no "describe the action" instead of a tool, no silent context truncation, no provider
      or model switch inside a run, no retry on another model after a capability error (T-67).
- [ ] `resources/ai/models.json` names the **recommended** model per v1 provider — **`gpt-5-mini`** for OpenAI
      and **`claude-opus-4-1`** for Anthropic — as a recommendation the user can change, **never** a
      preselection (§12.3.5, ai-state.md §4), and the adapter always passes an explicit model (row 12).
- [ ] `adapter_available` flips only for drivers whose adapter is actually bound under
      `SupportedProviders::adapterBinding($id)`; `SupportedProvidersTest` and `ChooseAiScreenTests` stay green
      so the Laravel catalogue, the Swift catalogue and the fixture cannot drift.
- [ ] Arch tests fail on `use Laravel\Ai\…` outside `app/AI/Providers/LaravelAi/`, on a `provider` passed as an
      array, on a dispatched `AgentFailedOver` or `ProviderFailedOver`, and on the string `GoZero` anywhere in
      `app/AI` (ADR-004 D2, D6; §0).

---

### S2-14 Enforce the privacy modes and the shared egress and SSRF guard

**Labels:** `security`, `ai`, `compliance`
**Sprint:** 2 · **Workstream:** H (Security/privacy) with D (AI) · **Size:** L
**Depends on:** S2-13 (#95), S2-03 (#86) · **Closes A15 and A18** (both `ENG`)
**Issue:** #96
**Contracts:** amends **ADR-004** with the **A18** decision (where the privacy mode lives, who may change it,
whether a downgrade needs re-auth, what audit event it writes) and **ADR-005 D4.2(b)** with the **A15**
checklist (address normalisation, the userinfo trap, the metadata list, `100.64.0.0/10`, the Unix-socket
prohibition, port rules, per-hop redirect revalidation, response caps, denial logging) — both as named new
decisions in the same PR, per the A19/A24 precedent. No frozen schema or wire contract change.

§10.1 and §4 make `LOCAL_ONLY` strict — no model traffic, embeddings, screenshots, tool transcript or prompt
to cloud, and **no silent fallbacks** — and the SDK has no such mode, so the enforcement is entirely ours.
ADR-004 **D4** specifies five independent layers, each alone sufficient and each with its own test: the
registry gate that never builds a non-local adapter; a loopback host allowlist with DNS resolved **once** and
the IP **pinned** for the request; an `Http::globalRequestMiddleware()` veto that throws
`EgressBlockedException` before a socket opens and re-checks redirects; no failover, because
`EgressBlockedException` is deliberately not a `FailoverableException`; and one path for every content type.
ADR-004's own residual risk is that layer 3 depends on the SDK using Laravel's `Http` — **matrix row 10 is
the alarm**. Two open questions block a safe implementation and are this item's to close: **A15**, the shared
SSRF guard that has no owner and no specification (proposed 16-row checklist: threat model §3.4.1, threats
T-23 – T-29, three of them marked **GAP**); and **A18**, the privacy mode's storage, change authority, re-auth
requirement and audit event (T-68). S2-03 landed A18's storage; this item lands the rule.

Create `app/Security/Egress/EgressGuard.php`, `app/Security/Egress/AddressNormalizer.php`,
`app/AI/AiServiceProvider.php` (the D4 layer-3 middleware), `app/Security/Privacy/PrivacyModeStore.php`,
`app/Security/Privacy/ProviderEligibility.php`; `tests/Security/SsrfGuardTest.php`,
`tests/Security/LocalOnlyEgressTest.php`, `tests/Security/PrivacyModeDowngradeTest.php`,
`tests/Security/NoSocketTransportArchTest.php`. Create `docs/security/egress-guard.md`; extend
`docs/architecture/ai-state.md` and `docs/OPEN-QUESTIONS.md` (A15, A18).

- [ ] `SsrfGuardTest` is a table test over **every** row of threat model §3.4.1: decimal, octal, hex,
      zero-padded and short-form literals, IPv4-mapped IPv6, `[::1]`, trailing-dot hosts, a userinfo-confused
      authority, the five named cloud-metadata endpoints, `100.64.0.0/10`, every forbidden scheme, a
      Unix-socket target, port abuse, and a resolver that answers differently on the second lookup — each
      denied on the **binary** address, never the textual form (T-23 – T-29).
- [ ] `LocalOnlyEgressTest` runs the whole AI fixture suite in `LOCAL_ONLY` with a cloud provider deliberately
      configured, records **zero** requests to a non-allowlisted host and asserts the cloud provider raised
      `egress_blocked`; `NoSocketTransportArchTest` forbids any HTTP client constructed with a socket
      transport option (T-27's GAP).
- [ ] Each of ADR-004 D4's five layers has its own test and each alone blocks, so removing one does not open
      the door; redirects are re-validated per hop and `LOCAL_ONLY_ALLOW_LAN` extends the allowlist only to
      RFC 1918 and link-local, never a public IP.
- [ ] **A18 is answered**: the mode lives in `privacy_policies`, a downgrade requires the S2-05
      re-authentication plus a UI re-confirmation and writes a named audit event, and
      `PrivacyModeDowngradeTest` proves the brief and chat surfaces reflect the change (T-68).
- [ ] `EU_RESTRICTED` admits a provider only on ADR-004 D5's eight recorded evidence fields and ADR-007 D6's
      R1–R7, refusing with the **missing field names**; an arch test fails on any hostname regex in `app/AI`,
      so no "EU" badge is ever derived from a domain name (ADR-004 D5, ADR-007 D5 forbidden phrasings).
- [ ] The packet-capture half is named as a **lab** step, never a CI claim: `docs/security/egress-guard.md`
      records the `tcpdump` protocol and the pcap is attached to the release checklist (ADR-004 D4 Evidence,
      §14; `docs/adrs/007` D5 permits the locality claim only while `LocalOnlyEgressTest` is green).

---

### S2-15 Implement the agent run loop and the control plane

**Labels:** `ai`
**Sprint:** 2 · **Workstream:** D (AI/Agents) · **Size:** L
**Depends on:** S2-13 (#95), S2-07 (#89)
**Issue:** #97
**Contracts:** no frozen contract change. **Rebinds `App\Support\Health\AiReadiness` over `ai_runs`** as
`docs/architecture/ai-state.md` §5 asks, and replaces `tests/Feature/Health/AiReadinessTest.php`'s assertion
that nothing in `app/` references `RecordAiCompletionSuccess` with this item's own test — the assertion is
replaced, never deleted, so no install can report AI ready without a real completion.

§4's agent-control list *is* this item — maxsteps, maxcost, maxtime, dedupe, cancellation, circuit breakers,
redaction, trace-id, output provenance and replay against synthetic test data — and ADR-004 **D7** gives every
number: `max_steps` **8** for chat and **12** for brief jobs with a hard ceiling of **25**,
`max_time_seconds` **120** / **300** (ceiling **600**), `step_timeout_seconds` **60** local and **45** cloud,
`max_output_tokens` **4096**, `max_cost_per_run` **€0.50**, `max_cost_per_day` **€5.00 per provider**,
`max_concurrency` **1** local and **4** cloud, a **60-minute** dedupe window keyed
`sha256(job_class, normalized_input, model_id)`, and a circuit breaker that opens after **3** connection or
overload errors in **60 s** and half-opens after **300 s** with **no automatic substitute**. The bounds live
in `app/AI/Agents/RunSupervisor` **outside** the SDK so they hold for every driver. This is also the first
caller of `App\Support\Health\RecordAiCompletionSuccess` — ai-state.md §1 names it the single writer of the
success marker, to be called only after a usable `generate()` or completed `stream()`, never from the
installer, a models list, a connectivity probe or a health check.

Create `app/AI/Agents/RunSupervisor.php`, `app/AI/Agents/AgentRun.php`, `app/AI/Tools/`, `app/AI/Memory/`,
`config/ai-agent.php`, `tests/fixtures/ai/` (the D7 replay transcripts);
`tests/Feature/AI/RunSupervisorBoundsTest.php`, `tests/Feature/AI/AiRunProvenanceTest.php`,
`tests/Feature/AI/DedupeAndCircuitBreakerTest.php`, `tests/Security/ToolTranscriptRedactionTest.php`,
`tests/Security/ToolSchemaTest.php`, `tests/Security/MemoryWriteRequiresReviewTest.php`,
`tests/Security/FetchedContentIsDataTest.php`. Create `docs/architecture/agent-runs.md`.

- [ ] Every ADR-004 D7 bound is enforced in `RunSupervisor` with its decided default and ceiling, and a breach
      refuses **before** the step that would exceed it rather than after; the menu bar's "Pause AI actions"
      global token (S1-08) stops a run between steps and per stream chunk.
- [ ] Every run writes an `ai_runs` row with D7's thirteen provenance fields, a ULID `trace_id` propagated to
      `tool_runs` and **never sent as a header to an external provider**, and an outcome that includes
      `cancelled` (matrix row 6).
- [ ] Dedupe returns the stored response for an identical request inside the 60-minute window; an open circuit
      excludes that provider from routing with **no** automatic substitute (`DedupeAndCircuitBreakerTest`).
- [ ] `ToolTranscriptRedactionTest` and `ToolSchemaTest` prove prompts, tool inputs, tool outputs and
      transcripts pass the `Redactor` before send or store, that a tool takes `connection_id` and never a
      credential, and that a secret-like parameter name is rejected by the schema lint (ADR-003 D7 row 4,
      T-13); an arch test forbids `App\AI` depending on `SecretValue`.
- [ ] `MemoryWriteRequiresReviewTest` proves the model cannot write permanent memory without an annotated,
      reviewed operation (§4, T-07); `FetchedContentIsDataTest` proves fetched and tool-returned text enters
      the delimited untrusted block with its server name and is never followed as instruction (T-03,
      ADR-005 D4.8, 64 KiB truncation, no auto-fetch of `resource_link` or `file://`).
- [ ] `RecordAiCompletionSuccess::record()` is called from **exactly one** place, after a usable response;
      `AiReadiness` is rebound over `ai_runs`, a failed run reports `error`, and switching provider still
      resets `ai_ready` by construction (ai-state.md §1).

---

### S2-16 Build the /assistant streaming chat with tool activity, citations and cost

**Labels:** `pwa`, `ai`
**Sprint:** 2 · **Workstream:** C (TALL/PWA) with D (AI) · **Size:** L
**Depends on:** S2-15 (#97), S2-14 (#96)
**Issue:** #98
**Contracts:** defines the citation and tool-activity payload for the chat surface in
`docs/architecture/assistant.md` — ADR-004 decides neither (the SDK has no citation concept and no UI
decision exists for token and cost display), so this item is where they are decided and recorded.
**No brief-schema change:** the brief's `source_refs` shape is reused rather than re-invented, and a field the
chat needs that the brief lacks would be a brief-schema **1.1.0 minor** bump (`brief-schema.md` §4) which this
item deliberately does not make. No service-worker change — `/assistant` is already on the deny list
(`docs/architecture/pwa.md` §4) because a transcript is content.

§1.2 specifies the route exactly: `/assistant` with streaming chat, tool activity, source citations and
cost/tokens. §10.4 and ADR-007 **D5** require AI interactions to be labelled as AI, and ADR-007 **D8** makes
that urgent rather than eventual — AI Act Art. 50 transparency has been in force since 2 August 2026 and the
Art. 50(2) grace period to 2 December 2026 does not apply to a product released after that date, so **the
chat and every automated action must carry the AI label and a machine-readable marking of generated text from
the first release, not later**. §4.2 requires every material claim to carry a source reference or an explicit
uncertainty marker, which is why citations reuse the brief's resolved-source shape rather than inventing a
second vocabulary (`docs/architecture/brief-parity.md` §1). §4.2 also requires that `AI unavailable` never
blocks the CRM, past briefs or admin.

Create `app/AI/Chat/ChatSession.php`, `app/AI/Chat/Citation.php`, `app/AI/Chat/ToolActivity.php`,
`app/Livewire/Assistant/`, `routes/assistant.php`, `resources/views/assistant/`;
`tests/Feature/Pwa/AssistantPageTest.php`, `tests/Feature/Pwa/AssistantStreamingTest.php`,
`tests/Feature/Pwa/AssistantCitationsTest.php`, `tests/Feature/Pwa/AssistantCostDisplayTest.php`,
`tests/Security/AssistantTranscriptNotCachedTest.php`, `tests/Feature/Pwa/AiLabellingTest.php`. Create
`docs/architecture/assistant.md`.

- [ ] Tokens stream into the page and cancellation reaches the run within one chunk (ADR-004 matrix row 6);
      `AssistantTranscriptNotCachedTest` proves no transcript reaches the service-worker cache, IndexedDB or
      `localStorage` (§1.2, pwa.md §4).
- [ ] Tool activity shows each attempted tool, its outcome and every `PolicyGate` denial as a model-readable
      result, read from `tool_runs`, with AI **suggestions** visibly separated from actions actually performed
      (§5.6).
- [ ] Every claim carries a citation to a CRM row, a brief source or an explicit uncertainty marker, in the
      same resolved `source_refs` shape the brief uses — type, account label, title, uri (§4.2,
      brief-parity.md §1).
- [ ] Tokens and cost per turn and per run are shown from `ai_runs` and `AiUsage`, with local runs showing
      tokens at cost zero (ADR-004 matrix row 8, D7's budget figures).
- [ ] `AiLabellingTest` proves the page is labelled as AI and carries the Art. 50 machine-readable marking
      (ADR-007 D5, D8); the `PwaFixtures::AI_ON_PHONE_DENYLIST` sweep still passes and "AI ready" still exists
      on exactly one code path (`AiReadyGateTest`).
- [ ] With `ai_state != ready` the route explains what is missing and links to `/settings/ai`, while the CRM,
      past briefs and admin keep working (§4.2); the page is policy-guarded by S2-06.

---
### E2-6 Real brief generation and the own TALL admin

### S2-17 Generate the Executive Brief for real, grounded and transactionally frozen

**Labels:** `ai`, `laravel`
**Sprint:** 2 · **Workstream:** D (AI/Agents) with B (Laravel platform) · **Size:** L
**Depends on:** S2-10 (#92), S2-15 (#97)
**Issue:** #99
**Contracts:** **the brief schema stays 1.0.0.** This item is scoped to produce documents the frozen schema
already describes; an item that needs a new field must bump it to **1.1.0** as an optional field and update
all three renderers plus the committed snapshot in the same PR (`brief-schema.md` §4,
`brief-parity.md` §3.4). `App\Briefs\BriefDocumentMapper::import()` stays the freeze transaction
(`data-model.md` §3) and `BriefParityPayload` stays the one semantic projection. No IPC change — the menu bar
keeps reading `GET /ipc/v1/briefs/latest`, which already returns only `frozen`-or-later versions.

§1.3 requires a brief to be assembled and frozen **transactionally once** and then rendered to the same
semantic data on all three surfaces, with content divergence forbidden. §4.2 requires every material claim to
carry a source reference or an explicit uncertainty marker, forbids invented deadlines, and requires the same
matter to be deduplicated across mail, CRM and GitHub. Sprint 1 proved the rendering half against a frozen
fixture (S1-03, S1-04, S1-09) and the two gates this item must not break are S1-10's tri-surface parity test
and S1-15's vertical slice: **the slice's seeded brief becomes a generated one**, and one `version` and one
`content_hash` must still hold across the PWA, the menu bar and the mail preview. A generated brief is not a
new surface, so `vertical-slice.md` §6's "new surface" path does not apply — the existing comparison must
simply keep passing on different input. Delivery stays **preview-only until E3-1**: §5.5 forbids sending
before the recipient and the sending account are confirmed, and S1-03's `PreviewCannotSendTest` is the
construction, not the configuration, that enforces it.

Create `app/Briefs/Generation/ComposeBrief.php`, `app/Briefs/Generation/Grounding.php`,
`app/Briefs/Generation/Deduplication.php`, `app/AI/Agents/Jobs/ExtractCommitments.php`,
`app/AI/Agents/Jobs/SummarizeThread.php`, `app/AI/Agents/Jobs/AssessProjectRisk.php` (§4.1);
`tests/Feature/Briefs/BriefGenerationTest.php`, `tests/Feature/Briefs/BriefGroundingTest.php`,
`tests/Feature/Briefs/BriefDedupeTest.php`, `tests/Feature/Briefs/BriefFreezeIsTransactionalTest.php`,
`tests/Feature/Briefs/NoInventedDeadlineTest.php`. Create `docs/architecture/brief-generation.md`.

- [ ] A brief is generated from real CRM rows and frozen in one transaction with its `content_hash` and
      `idempotency_key`; an interrupted generation leaves **no** partial brief, and a correction is
      `version + 1` under the same `id` rather than an edit (`brief-schema.md` §5).
- [ ] Every item carries `source_refs` resolvable to a real row **or** an explicit `uncertainty` with a reason
      and a confidence; a generated item with a bare claim is refused **before** it reaches the database —
      `BriefParityPayload` already refuses it at render, this moves the refusal earlier (§4.2).
- [ ] `NoInventedDeadlineTest` proves no `due_at` appears that is not in a source row, and `BriefDedupeTest`
      proves the same matter arriving from two sources produces one item carrying both, through `dedupe_key`
      (§4.2).
- [ ] S1-10's parity gate and S1-15's vertical slice stay **green with a generated brief**: one `version`, one
      `content_hash` across `GET /briefs/{brief}/parity.json`, `GET /ipc/v1/briefs/latest` and the mail
      preview, with the Swift export as the `parity` lane's oracle (`brief-parity.md` §3.2, §3.6).
- [ ] `model_provenance` records provider, model, privacy mode, prompt hash and `trace_id`; the brief carries
      the ADR-007 D5/D8 AI label and Art. 50 machine-readable marking; `PreviewCannotSendTest` is extended to
      the generated path so no outgoing mail can be dispatched before E3-1.
- [ ] With AI unavailable the previous brief still renders and the generation failure is a visible state, not
      an error page (§4.2); the four §4.2 languages still render (the S0-18 fixtures stay in the suite).

---

### S2-18 Schedule the brief idempotently per day with login catch-up, and write ADR-009

**Labels:** `ai`, `adr`
**Sprint:** 2 · **Workstream:** D (AI/Agents) with H (security) · **Size:** M
**Depends on:** S2-17 (#99)
**Issue:** #100
**Contracts:** creates **ADR-009 Brief scheduling and send consent** (a new ADR, recommended by ambiguity
**A2**, written in Sprint 2 so it gates Sprint 3 rather than Sprint 0) and registers it in
`docs/adrs/README.md`. ADR-002 **D7** explicitly defers the send-receipt durability case to ADR-009, so that
paragraph is answered here. No frozen contract change.
**Needs an owner ruling (not a hard block): §15.9 / A2** — `ENG+ANDREAS`. The owner confirms the ADR-009 scope
and whether the product ships with a *suggested* daily time at all. **Buildable before the ruling: the whole
item**, on the no-default-schedule behaviour already recorded as the defensible default in
`docs/OPEN-QUESTIONS.md` §15.9; only the suggested-time default would change, and it is one config value.

`docs/architecture/services.md` §5 states the Supervisor consequence plainly: it is a **user LaunchAgent**, so
nothing runs before login, and *"a 05:00 brief scheduled while nobody is logged in is not generated at
05:00"*. The rule that follows belongs to this item and services.md records it as owed here rather than
implementing it: generation must be **idempotent per day and catch up**, so the first `schedule:work` tick
after login produces the day's brief if none exists. The scheduling-and-consent half of §15.9 has no ADR among
§16 step 2's seven — ambiguity **A2** recommends ADR-009. §5.5 and §5.6 fix the consent shape: no mail before
the recipient and the sending account are confirmed by a test message, and consent to scheduled distribution
covers **only** that recipient and that content type — no other mail rides on it.

Create `docs/adrs/009-brief-scheduling-and-send-consent.md`,
`app/Briefs/Generation/DailyBriefSchedule.php`, `app/Briefs/Delivery/SendConsent.php`,
`app/Livewire/Briefs/ScheduleSettings.php`; `tests/Feature/Briefs/DailyBriefIdempotencyTest.php`,
`tests/Feature/Briefs/BriefCatchUpAfterLoginTest.php`, `tests/Feature/Briefs/SendConsentTest.php`,
`tests/Feature/Briefs/NoDefaultScheduleTest.php`. Extend `docs/architecture/brief-generation.md`,
`docs/adrs/README.md` and `docs/OPEN-QUESTIONS.md` (§15.9, A2).

- [ ] At most one brief per organization per **local day** however many ticks fire, enforced by a unique
      constraint rather than a check-then-insert (`DailyBriefIdempotencyTest`).
- [ ] A day whose scheduled time passed while nobody was logged in produces the brief on the first tick after
      login, with `period_start` / `period_end` still describing the intended period
      (`BriefCatchUpAfterLoginTest`, services.md §5).
- [ ] `NoDefaultScheduleTest` proves there is **no** default schedule: scheduling is opt-in, the user picks the
      time and the IANA zone with the Mac's own zone pre-filled, and nothing is scheduled until they do
      (OPEN-QUESTIONS §15.9).
- [ ] Enabling delivery requires a confirmed recipient and a **successful test message**; consent covers only
      that recipient and content type and no other mail may be sent on it (§5.5, §5.6 criterion 7).
- [ ] ADR-009 is written to the S0-02 template with Evidence and Residual risks, indexed in
      `docs/adrs/README.md`, and answers ADR-002 D7's deferred send-receipt durability case; §15.9 and A2 are
      updated to point at it instead of saying "no ADR covers this".
- [ ] The scheduler self-test and the `queue_worker` heartbeat still report through `/health/ready`
      (`data-model.md` §4) with the new scheduled job registered on the `briefs` queue.

---

### S2-19 Build the own TALL admin at /admin

**Labels:** `pwa`, `compliance`
**Sprint:** 2 · **Workstream:** C (TALL/PWA) with H (security) · **Size:** L
**Depends on:** S2-06 (#88), S2-03 (#86), S2-14 (#96)
**Issue:** #101
**Contracts:** no frozen contract change; `/admin` is already on the service-worker deny list
(`docs/architecture/pwa.md` §4). The ADR-007 surfaces Sprint 2 deliberately does **not** take —
`/about/licenses` (D2.3, D2.4), the sanitised diagnostics export (D9), the recruitment use-case guardrail
(D7) and the UI copy linter's full corpus — stay with **E4-3** and are cross-referenced rather than
half-built here.

§1.2 names `/admin` and its panes: users, orgs, security rules, logs, retention, updates and backup. §0 bans
Filament, so this is an own TALL surface on Livewire, Alpine and the S0-24 tokens. Two panes are not
cosmetic. The **security-rules** pane is where the S2-14 privacy mode is changed, which ADR-004 **D5** and
ADR-007 **D6** make an evidence-driven decision rather than a toggle: `EU_RESTRICTED` admits a provider only
when R1 `processing_region` is an EU/EEA region per the provider's **own** document, R3 `dpa_status` is at
least `reviewed`, R4 `tia_status` is `done` or `not_required` and R5 `retention` is filled; `LOCAL_ONLY`
admits only `this-mac`; and `CUSTOM` must show the user **exactly which fields are missing** before enabling.
The mode never infers any of this from a hostname. The **retention** pane is where `data_retention_rules`
(S2-03) becomes visible and editable. The **logs** pane reads `audit_events` through the `Redactor` and shows
no blob and no image path (ADR-003 D7 row 8).

Create `routes/admin.php`, `app/Livewire/Admin/`, `resources/views/admin/`,
`app/Security/Audit/AuditLogViewer.php`, `app/Livewire/Admin/ProviderRegister.php`;
`tests/Feature/Admin/AdminPanesTest.php`, `tests/Feature/Admin/AdminAuthorizationTest.php`,
`tests/Feature/Admin/PrivacyModeAdmissionTest.php`, `tests/Security/AdminLogsCarryNoSecretTest.php`,
`tests/Feature/Admin/AdminClaimWordingTest.php`. Create `docs/architecture/admin.md`.

- [ ] All seven §1.2 panes exist and work — users, organizations, security rules, logs, retention, updates,
      backup — with Filament absent from `composer.json` and no CDN asset anywhere (§0, pwa.md §1).
- [ ] Every pane is policy-guarded by S2-06 and every mutating action requires the S2-05 re-authentication;
      `AdminAuthorizationTest` proves a member without the ability gets a 403, not a hidden link.
- [ ] `PrivacyModeAdmissionTest` proves `EU_RESTRICTED` admits a provider only on ADR-004 D5's eight recorded
      fields and ADR-007 D6's R1–R7, that `LOCAL_ONLY` admits only `this-mac`, and that `CUSTOM` names the
      missing fields before enabling — never a hostname inference.
- [ ] The logs pane renders `audit_events` through the `Redactor` with no blob and no image path, and
      `AdminLogsCarryNoSecretTest` sweeps every rendered pane against
      `tests/Security/fixtures/secrets-corpus.json` (ADR-003 D7).
- [ ] The retention pane shows and edits `data_retention_rules` and states, per rule, which tables it governs
      and when the purge job next runs; the backup pane reads `backup_runs` (ADR-002 D5).
- [ ] `AdminClaimWordingTest` proves no pane uses an ADR-007 D5 forbidden phrasing and that the model and
      provider register shows region and DPA status **as declared by the provider**, with that word.

---

### Sprint 2 exit gate (§13.1)

### S2-20 Automate the Sprint 2 exit check: security checks and brief identity

**Labels:** `release`, `security`
**Sprint:** 2 · **Workstream:** I (QA/release) with H (security) · **Size:** M
**Depends on:** S2-04 (#87), S2-06 (#88), S2-08 (#90), S2-12 (#94), S2-14 (#96), S2-16 (#98), S2-17 (#99), S2-19 (#101)
**Issue:** #102
**Contracts:** no product contract change. Adds a CI job with a stable name so it can be added to the S0-27
required-check list — **a repository-admin action for the owner**, exactly like ambiguity **A14**'s pending
`parity` entry (`docs/contributing/branch-protection.md` §1.1). Nothing in this repository calls a settings
API.

§13.1's Sprint 2 exit criterion is *"security checks and brief identity"*, and S1-15 set the precedent for
what that has to mean: a green run rather than someone's recollection. Two halves. **Brief identity** is
already automated by S1-10's tri-surface parity gate and S1-15's vertical slice, but Sprint 2 changes what
flows through them — the slice's seeded brief becomes a generated one (S2-17), so the gate must assert one
`version` and one `content_hash` across the PWA, the menu bar and the mail on a brief the product produced
itself. **Security checks** has no single artefact yet, which is exactly how a sprint exits with one suite
quietly skipped: this item turns the named Sprint 2 security classes into an enumerated list a driver checks
for **existence** as well as a green result, and fails if any `ExpectedFailure::guard()` wrapper owned by an
E2-x item still stands — the same mechanism S0-21 uses, pointed the other way.

Create `tests/E2E/SprintTwoExitTest.php`, `packaging/fixtures/e2e/sprint-2-exit.mjs` with its `node:test`
shape tests, and a `sprint-2-exit` job in `.github/workflows/ci.yml`. Create
`docs/operations/sprint-2-exit.md`.

- [ ] The driver enumerates every named Sprint 2 security test class from `docs/operations/sprint-2-exit.md`
      and fails if one is **missing** as loudly as if one is red — a deleted suite cannot pass the gate.
- [ ] The run fails if any `ExpectedFailure::guard()` remains in `tests/Security/` whose `until:` names an
      E2-x item, so S0-21's marker cannot survive the sprint.
- [ ] Brief identity is asserted on a **generated** brief on a freshly installed core: one `version`, one
      `content_hash` across `GET /briefs/{brief}/parity.json`, `GET /ipc/v1/briefs/latest` and the mail
      preview (`brief-parity.md` §3.6, `docs/operations/vertical-slice.md`).
- [ ] The `migrations`, `queue`, `repository`, `search`, `sync-idempotency`, `cross-account` and
      `backup-restore` contract groups are asserted green on **both** ADR-002 D1 profiles, not only `local`.
- [ ] Every threat-model row tagged `planned-S2` is either `mitigated` with its named test green, or carries a
      dated, named reason it is not — checked by the driver against `docs/security/threat-model.md` §4, so the
      register cannot drift from the suite.
- [ ] `docs/operations/sprint-2-exit.md` documents the protocol well enough for an outside contributor to
      reproduce it (§17) and names the one action only the owner can take: adding `sprint-2-exit` to
      `required_status_checks` in both ruleset files (A14).

---

## Sprint 3 — Sources and automation (epic placeholders)

Exit (§13.1): no cross-account leakage; stop button works.

- **E3-1 Multi-account email platform** — Google, Microsoft 365 (mandatory for v1), iCloud, HEY,
  generic IMAP/SMTP, optional Proton Bridge; all ten §5.6 acceptance criteria (workstream F).
- **E3-2 Sync and normalization layer** — the nine `email_*` tables, provider-specific incremental
  cursors, resumable bootstrap of 100 000 synthetic messages with no duplicates (§5.6, workstream B/F).
- **E3-3 Unified inbox and explainable CRM matching** — per-account filters, Needs Attention,
  manual merge/split, AI suggestions visibly separated from executed actions (§5.6, workstream C/E).
- **E3-4 GitHub App integration** — per-org installs, allowlists, read-only default, short-lived
  tokens, signature-verified webhooks or polling, no write operations (§5.4, workstream F).
- **E3-5 MCP Manager** — registry discovery and the full Discover→Assess→Explain→Approve→
  Install/Connect→Grant→Audit→Revoke flow, read-only tools, allowlisted local runtime, own narrow
  Laravel MCP tools (§7, workstream G).
- **E3-6 Peekaboo optional automation** — allowlisted apps/windows, visible session, per-action
  authorization, screenshot redaction, timeouts, panic stop (§6, workstream G).

## Sprint 4 — Production candidate (epic placeholders)

Exit (§13.1): verified release gates.

- **E4-1 Tailscale Serve remote access** — private tailnet, single origin, iPhone PWA from a mobile
  network, ACLs confirmed, no public exposure by default (§8, workstream A/H).
- **E4-2 Updates, backups and recovery** — signed update feed, migration dry-run and rollback,
  encrypted local plus external backups, restore drills, documented post-power-cut manual steps
  (§9, workstream B/A).
- **E4-3 Compliance package** — per-role per-use-case mapping with release-valid dates, model and
  provider register, GDPR surface, risk classification with a recruitment guardrail, DPAs, SBOM,
  telemetry off by default, no certification claims (§10, workstream H/J).
- **E4-4 Install matrix and performance gates** — ≥30 installs per Mac profile, p50<180s/p95<300s,
  full coverage of upgrade, failed upgrade, interrupted install and reinstall paths (§12.2,
  workstream I).
- **E4-5 Third-party penetration test and signed risk acceptance** — plus OWASP ASVS checks, SAST,
  DAST and native entitlement review (§11, workstream H).
- **E4-6 Release engineering and docs completeness** — notarization and stapling, Gatekeeper
  clean-machine test, SBOM and license audit, published checksums, signed tag, README install video,
  accessibility pass, outside-contributor reproducibility (§14, §17, workstream I/J/C).
