# Laravel Executive AI — Backlog

> **Scope:** issue-ready items for **Sprint 0** and **Sprint 1** only. Sprints 2–4 carry
> epic-level placeholders to be decomposed when their gate opens (see `docs/ROADMAP.md`).
> **Created:** 2026-10-10 · **Spec:** `Mission/Laravel_Executive_AI_Engineering_Blueprint.md`
> **Items are dependency-ordered.** An agent may start any item whose `Depends on` is satisfied.

**Available labels (the only twelve that exist):** `macos` `laravel` `pwa` `ai` `crm`
`integrations` `mcp` `security` `compliance` `release` `adr` `docs`

**Workstream → label mapping:** A macOS/Swift→`macos` · B Laravel platform→`laravel` ·
C TALL/PWA→`pwa` · D AI/Agents→`ai` · E CRM→`crm` · F Integrations→`integrations` ·
G MCP/Automation→`mcp` · H Security/privacy→`security` · I QA/release→`release` ·
J Docs/community→`docs`.

**GitHub milestones:** `Sprint 0 — Architecture freeze`, `Sprint 1 — Vertical slice`. Each item
below is mirrored as a GitHub issue; the issue is the working copy, this file is the frozen plan.

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

## Sprint 2 — Real core (epic placeholders)

Decompose when the Sprint 1 gate opens. Exit (§13.1): security checks and brief identity.

- **E2-1 Local CRM core** — customers, contacts, projects, tasks, communications, activity timeline
  with visible sources and remembered follow-ups, CSV/JSON import/export, GDPR export and erasure
  (§3.2, workstream E).
- **E2-2 Full §3.1 schema** — every listed table with ULID, `organization_id`, retention policy,
  source provenance, sensitivity class and the required unique and idempotency indexes
  (workstream B/E).
- **E2-3 Authentication and authorization hardening** — passkeys/WebAuthn or MFA, session
  revocation, device management, re-auth for critical actions, and policies on every HTTP action,
  Livewire action, job, MCP tool, AI tool and integration call with IDOR/tenant negative tests
  (§11, workstream H/B).
- **E2-4 PolicyGate and the approvals engine** — frozen immutable proposals with hash, approver
  identity, expiry, nonce, idempotency, fail-closed; turns S0-21 green (§4, §11, workstream H).
- **E2-5 Assistant chat on a user-selected provider** — streaming chat with tool activity, source
  citations and cost/token display, over the pinned Laravel AI SDK, with `LOCAL_ONLY` enforced and
  verified by packet capture (§1.2, §4, §10, workstream D).
- **E2-6 Real brief generation and the own TALL admin** — transactional freeze, grounded items,
  dedupe, `/admin` for users, orgs, security rules, logs, retention, updates and backup, no Filament
  (§1.3, §1.2, workstream D/C).

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
