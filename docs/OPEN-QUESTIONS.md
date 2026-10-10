# Laravel Executive AI — Open Questions

> Maps the ten unresolved decisions in blueprint §15 to the ADR that closes each one, the owning
> workstream, the evidence the blueprint demands, and whether an engineer can decide it or Andreas
> must. §15 is binding: **"Unresolved is not permission to guess. Each needs an ADR, measured
> prototype or vendor-doc citation."**
> **Created:** 2026-10-10 · **Spec:** `Mission/Laravel_Executive_AI_Engineering_Blueprint.md`

**Decide-by legend:** `ENG` an engineer or agent can close it with evidence · `ANDREAS` requires
Andreas personally (legal, licensing, Apple/Google accounts, budget, product policy) ·
`ENG+ANDREAS` engineers produce the evidence, Andreas makes the call.

---

## §15.1 — Support matrix: macOS versions, Mac chips, minimum RAM and disk

| | |
|---|---|
| **Closed by** | ADR-001 (macOS runtime packaging), recorded in `docs/architecture/support-matrix.md` |
| **Owner** | A (macOS/Swift), with I (QA/release) for the measurements |
| **Evidence required** | **Measured prototype.** §2 demands documented macOS 15+ and Apple Silicon compatibility and an explicit statement on Intel; §12.1 preflight cannot be written against guessed numbers |
| **Defensible default** | macOS 15 as the floor (§2 says "macOS 15+"); **Apple Silicon only, Intel explicitly not supported** — §0 already drops Linux and Windows, and a bundled signed universal PHP runtime for an architecture nobody on the team tests is cost without reach |
| **Decide by** | `ENG` |

RAM and disk minima must come from a real install on a constrained machine, not from a spec sheet.
See also ambiguity **A4** on the "macOS 15/26" version notation.

---

## §15.2 — PHP and HTTP server packaging, updates, legal constraints, service lifecycle

| | |
|---|---|
| **Closed by** | ADR-001 |
| **Owner** | A (macOS/Swift) and B (Laravel platform); legal half rolls up to ADR-007 |
| **Evidence required** | **Measured prototype plus vendor/license citation per bundled binary.** §12.1: if signed packaging of PHP proves legally or technically impractical, *escalate at architecture review rather than silently requiring Homebrew* |
| **Defensible default** | A versioned, app-owned, immutable runtime in Application Support (§12.1), supervised by `launchd`/`SMAppService` (§2, §9), behind a bundled minimal HTTP server — **never** `php artisan serve`, which §2 forbids as a production server |
| **Decide by** | `ENG+ANDREAS` — engineers choose and prove the stack; the redistribution rights of each bundled executable are a legal question Andreas owns |

---

## §15.3a — SQLite FTS5 versus an optional MySQL profile

| | |
|---|---|
| **Closed by** | ADR-002 (local DB/queue) |
| **Owner** | B (Laravel platform) |
| **Evidence required** | **ADR plus benchmark.** §2 permits a switch only if the team *"efter benchmark kan bevisa en enklare underhållen MySQL-bundle inom fem minuter"*, and demands a migration path plus contract tests between backend profiles |
| **Defensible default** | Already decided: SQLite WAL + Laravel database queue for core-ready (§2); FTS5 full-text first, vector search opt-in only after benchmark, no external vector DB as a requirement (§3.2) |
| **Decide by** | `ENG` |

The open part is not *which* database but the **migration path and dual-profile contract tests** §2
requires, plus the WAL write-contention question raised in ambiguity **A9**.

---

## §15.3b — Keychain service access, and whether app sandboxing is feasible given Peekaboo

| | |
|---|---|
| **Closed by** | ADR-003 (Keychain/secrets), cross-referenced from ADR-005 |
| **Owner** | H (Security/privacy), with A for the entitlements |
| **Evidence required** | **Vendor-doc citation plus prototype.** §3.1 names the specific hazard: SQLite is not protected by FileVault while the user is logged in, so credentials belong in the Keychain, threat models must be written, and SQLCipher evaluated separately |
| **Defensible default** | One Keychain item per service identity; assume the **main app cannot be App-Sandboxed** while Peekaboo needs Screen Recording and Accessibility (§6) — but this must be proven with the actual entitlement set, not assumed |
| **Decide by** | `ENG` |

SQLCipher is a separate yes/no with a measured performance cost; §3.1 requires that residual risks be
documented either way.

---

## §15.4 — MCP client transport and the exact officially supported Laravel APIs and versions

| | |
|---|---|
| **Closed by** | ADR-004 (provider-neutral AI) for the SDK half, ADR-005 for the MCP transport half |
| **Owner** | D (AI/Agents) and G (MCP/Automation) |
| **Evidence required** | **Vendor-doc citation at a pinned version.** §4: use the official Laravel AI SDK *"om kompatibilitet och API:er verifieras med låst version"* — verification is a precondition, not a formality |
| **Defensible default** | Pin the Laravel AI SDK and `laravel/mcp` versions, cite https://laravel.com/docs/13.x/ai-sdk, https://laravel.com/ai/mcp and https://modelcontextprotocol.io/specification, and write contract tests for every API relied on so an upstream change breaks a test rather than production |
| **Decide by** | `ENG` |

Any SDK capability the project needs but the pinned version does not support must be recorded in
ADR-004 as a gap, with the local workaround named.

---

## §15.5 — Peekaboo permissions, UI-session scenarios, app/window allowlist, helper signing

| | |
|---|---|
| **Closed by** | ADR-005 (Peekaboo/MCP security) |
| **Owner** | G (MCP/Automation), with A for the helper signing model |
| **Evidence required** | **Measured prototype plus vendor-doc citation.** §6 fixes the non-negotiables: no circumvention of TCC, lock screen or other system controls, and no automated reading or bypassing of passwords or 2FA codes |
| **Defensible default** | Optional, off by default, never blocking install or CRM/AI chat (§6); verified pinned version from a defined release source with checksum and signature verification; a visible, cancellable session with a per-action grant and a panic stop; a narrow app/window allowlist starting with Safari only |
| **Decide by** | `ENG+ANDREAS` — the technical model is engineering, but the **helper signing model needs an Apple Developer team identity**, and ambiguity **A1** (Peekaboo is published under the `openclaw` GitHub org that §0 bans) needs Andreas to rule on |

---

## §15.6 — Local inference choices, quantized model licenses, download size

| | |
|---|---|
| **Closed by** | ADR-004 for the runtime choice, ADR-007 for the licensing |
| **Owner** | D (AI/Agents), with J for the license register |
| **Evidence required** | **Vendor-doc citation per model plus a measured download size.** §10.9: a local model does not automatically make its training data, license or use free of compliance questions — model licenses and redistribution must be checked |
| **Defensible default** | Support already-installed Ollama or LM Studio first and **reuse** it (§12.1); offer at most one small tested model as an explicitly asynchronous post-core download (§0, §12.1); bundle no model weights in the DMG until a license review says a specific model may be redistributed |
| **Decide by** | `ENG+ANDREAS` — which runtimes to support is engineering; whether a given model's license permits redistribution inside an MIT product is Andreas' call |

---

## §15.7 — Google OAuth verification strategy and restricted Gmail scopes

| | |
|---|---|
| **Closed by** | **No ADR in §16 step 2 covers this** — see ambiguity **A2**; recommend a new ADR-008 (email account and OAuth strategy) |
| **Owner** | F (Integrations), with H for the scope policy |
| **Evidence required** | **Vendor-doc citation.** §5.1 requires referencing Google's OAuth-verification and restricted-scope limitations *before public launch*; §5.6 requires Google restricted scopes, Microsoft tenant-admin consent and the actual availability of HEY/Apple methods to be documented and tested before public release |
| **Defensible default** | Smallest possible scopes, read-only by default, send only behind a separately granted scope and an explicit per-message approval (§5.1, §5.6); polling before Pub/Sub push (§5.1); document admin-consent and manual-exception paths rather than promising a frictionless flow |
| **Decide by** | `ANDREAS` — Google app verification for restricted Gmail scopes is an organizational process requiring a verified publisher, a published privacy policy, and potentially a paid third-party security assessment. It is a business decision with cost and calendar implications, not a coding task |

A self-hosted open-source product has a second option engineers cannot pick unilaterally: ship
**unverified** and require each user to create their own Google Cloud project and OAuth client. That
removes the verification burden but adds setup friction that collides with §5.6 criterion 2 ("no user
needs Terminal for Gmail or Microsoft"). Andreas must choose.

---

## §15.8 — PWA origin, secure cookies, push, Tailscale Serve ACLs and macOS distribution

| | |
|---|---|
| **Closed by** | ADR-006 (private remote access) |
| **Owner** | A (macOS/Swift) and C (TALL/PWA), with H for the ACL posture |
| **Evidence required** | **Measured prototype plus vendor-doc citation.** §8 requires a tested iPhone home-screen PWA over a mobile network with Tailscale connected, and confirmed tailnet ACLs proving the whole machine is not exposed |
| **Defensible default** | Tailscale **Serve** on a private tailnet, never Funnel (§8); local HTTP on `127.0.0.1`; one tunnelled origin with valid HTTPS; `APP_URL`, `TrustProxies`, secure cookies, CSRF and session configured for that origin; push opt-in with minimal time-limited payloads and no customer text in cleartext; Cloudflare at most a future optional adapter and never the Europe-first default (§8) |
| **Decide by** | `ENG` |

---

## §15.9 — Brief default schedule and timezone, SMTP sender verification, send consent

| | |
|---|---|
| **Closed by** | **No ADR in §16 step 2 covers this** — see ambiguity **A2**; the consent half belongs in ADR-007, the scheduling half needs a product decision |
| **Owner** | D (AI/Agents) for generation, F (Integrations) for sending, H for consent mechanics |
| **Evidence required** | **ADR.** §5.5: no email is sent before the recipient and the sending account are confirmed; §5.6: consent to scheduled automatic brief distribution covers only that chosen recipient and content type, and no other mail may be sent without separate approval |
| **Defensible default** | **No default schedule at all.** Scheduling is opt-in, the user picks time and timezone, and the Mac's own timezone is the pre-filled suggestion. A test message to a verified recipient is a precondition for enabling the schedule (§5.5). This is the only default consistent with §5.5's "no email before confirmation" |
| **Decide by** | `ENG+ANDREAS` — the no-default-schedule recommendation above is defensible from the blueprint, but whether the product ships with a suggested daily time at all is a product decision Andreas should confirm |

---

## §15.10 — MIT versus an alternative license; legal review of bundled executables and models

| | |
|---|---|
| **Closed by** | ADR-007 (license/compliance) |
| **Owner** | J (Docs/community), with H for the compliance mapping |
| **Evidence required** | **ADR plus a per-artifact license citation.** §10 states that open-source distribution is not a general exemption; §10.9 extends that to model licenses and redistribution |
| **Defensible default** | **License half already closed: MIT.** The open half is the review itself — every bundled executable (PHP, the HTTP server, any helper, Peekaboo) and every candidate model needs its license and redistribution terms recorded and confirmed MIT-compatible |
| **Decide by** | `ANDREAS` — §10 explicitly calls for a *legally reviewed* compliance mapping. An engineer can assemble the license inventory; only Andreas can commission and accept the legal review, and only Andreas can accept the residual risk |

---

# Additional ambiguities found in the blueprint

These are not in §15 but would stall an implementing agent. Each needs a one-line ruling.

### A1 — Peekaboo is published under the `openclaw` GitHub organization that §0 bans
§0 excludes "OpenClaw" outright, while §18 pins Peekaboo at
`https://github.com/openclaw/Peekaboo`. §6 clearly intends Peekaboo to be used. The most likely
reading is that the ban targets the OpenClaw **agent product**, not a library that happens to live
in that org — but an agent reading §0 literally would refuse to add the dependency.
**Needs:** `ANDREAS`, one sentence in ADR-005. Blocks S0-10 and all Sprint 3 Peekaboo work.

### A2 — §15.7 and §15.9 have no ADR among §16 step 2's seven
The seven ADRs cover runtime, DB, secrets, AI, Peekaboo/MCP, remote access and licensing. Email
account strategy (§15.7) and brief scheduling/send consent (§15.9) — both with binding v1
requirements in §5.6 — fall between them. **Recommendation:** add **ADR-008 Email account and OAuth
strategy** and **ADR-009 Brief scheduling and send consent**, written in Sprint 2 so they gate
Sprint 3 rather than Sprint 0. **Needs:** `ENG+ANDREAS` (scope approval).

### A3 — `communications`/`messages`/`threads` (§3.1) overlap `email_messages`/`email_threads` (§5.6)
Two table sets model the same objects at different layers, and the blueprint never says whether email
messages normalize **into** `communications` or sit beside it with a join. Picking wrong is a
migration across the whole CRM timeline. **Needs:** `ENG` decision recorded in ADR-002 or a schema
ADR, before Sprint 2's E2-2 lands.

### A4 — `connected_email_accounts` versus `email_connections`
§5.6 names the table `connected_email_accounts` in its security model and `email_connections` in its
data model, three paragraphs apart. **Recommendation:** `email_connections`, since it matches the
`email_*` family and the `EmailConnection` DTO in the §5.6 adapter contract. **Needs:** `ENG`.

### A5 — "macOS 15/26" in §15.1
It is unclear whether this means "macOS 15 through macOS 26" (a supported range), "macOS 15 or
macOS 26", or a typo. §2 separately says "macOS 15+". The support matrix needs an unambiguous floor
and a highest-tested version. **Needs:** `ENG`, recorded in S0-22.

### A6 — No `docs` label exists
§16 step 1 lists ten labels; the repo has eleven (`adr` was added). Workstream J (docs/community) has
no label of its own, so its issues were filed under `release`. **Resolved 2026-10-10:** `docs` label
added; `BACKLOG.md` remapped workstream J to it.

### A7 — §14 says "PHPUnit/Pest (choose one)" and never chooses
The test scaffolding in S0-26 cannot be written twice. **Recommendation:** Pest, for the Laravel
default and for readable security-test names; either is defensible, but it must be decided once and
recorded. **Needs:** `ENG`, before S0-26.

### A8 — Nine installer states versus "never more than 6 mandatory interaction steps"
§12.1's state machine has nine states; §12.3 forbids more than six mandatory interaction steps
without an ADR and measurement. These are compatible only if several states are non-interactive. The
installer spec should state which states require user interaction and show the count is ≤6.
**Needs:** `ENG`, recorded in S1-05.

### A9 — SQLite WAL versus eight concurrent email sync workers
§2 picks SQLite WAL for install speed. §5.6 acceptance criterion 3 requires 100 000 synthetic
messages across eight accounts synced concurrently and resumably. WAL allows concurrent readers but
only one writer, so eight sync workers plus the brief generator plus the scheduler all contend for a
single write lock. This is the most likely place the §2 decision gets reopened, and §2 anticipates it
by requiring a dual-profile migration path. **Needs:** `ENG` — a measured write-throughput prototype
in Sprint 0 or early Sprint 2, before the Sprint 3 adapters are built on an assumption.

### A10 — §17 requires "at least two optional external AI adapters" without naming them
§4 lists six candidate providers. Shipping all six in v1 multiplies the contract-test surface for no
stated benefit. **Recommendation:** pick two for v1 and treat the rest as post-v1 adapters behind the
same interface. **Needs:** `ENG`, recorded in ADR-004.

### A11 — Eight real provider test accounts are a procurement item, not a coding task
§5.6 acceptance criterion 1 requires three Google, two Microsoft, one HEY, one iCloud and one generic
IMAP account running concurrently, and §13 makes F the owner of "verkliga testkonton". A HEY account
is a paid subscription; Microsoft tenant testing may need a paid tenant. **Needs:** `ANDREAS` —
accounts and budget, before Sprint 3 starts.

### A12 — Apple Developer team identity and signing credentials
§16 step 5, §12.1 and §14 all depend on a signing identity and, eventually, notarization. No
engineering work can produce one. **Needs:** `ANDREAS` — which Apple Developer team (STAFE GROUP AB
or personal) owns the app, plus the certificate and CI secrets. Blocks S1-12.

### A13 — Third-party penetration test before v1.0
§11 makes it a blocking gate and §13 requires H to sign a risk acceptance. Vendor selection, budget
and scheduling are not engineering decisions. **Needs:** `ANDREAS`, before the Sprint 4 gate.

### A14 — Who are the DRIs, given the work is done by AI agents?
§13 assigns each workstream a DRI plus a reviewer from another team, and Sprint 0's exit criterion is
*"all DRIs approve contracts"*. With parallel AI agents and a single human owner, the exit gate has no
defined signatory, and §13.2's "2 reviewers for security-critical modules" has no second human.
**Needs:** `ANDREAS` — a ruling on who approves Sprint 0 exit and what counts as a review for
security-critical modules. Without it, Sprint 0 cannot formally close.
