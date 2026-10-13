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
See also ambiguity **A5** on the "macOS 15/26" version notation.
**Recorded in support-matrix.md 2026-10-10 (PROVISIONAL numbers until S1-14):** floor macOS 15.0,
highest tested 26.6.2, Apple Silicon only, Intel explicitly unsupported (primary Apple source pinned);
RAM 8 GB / free disk 2 GB are derived from measured components, not from an install, because no
installable product exists yet — S1-14 measures them on the minimum machine (`docs/architecture/support-matrix.md` §4–§7).

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

**Verified against vendor docs 2026-10-10** (re-verify at pin time):
- The official SDK is the `laravel/ai` composer package (`composer require laravel/ai`). It offers
  streaming (`stream()`), tool calling (`HasTools`/`tools()`), structured output
  (`HasStructuredOutput`), embeddings (`Embeddings::for()`), and text providers including OpenAI,
  Anthropic, Gemini, Ollama, OpenRouter plus an `openai-compatible` driver (URL + key) that covers
  LM Studio / MLX-style local endpoints, and runtime-built providers via `Ai::build([...])` — which
  is how user-supplied keys avoid `.env`. This satisfies the §4 provider list without a custom
  driver, **provided** `LOCAL_ONLY` enforcement wraps the adapter (the SDK itself has no such mode).
- `laravel/mcp` (`composer require laravel/mcp`, v1.0.1 2026-09-24) builds MCP **servers** (tools,
  resources, prompts, OAuth 2.1 via Sanctum, stdio and HTTP/SSE) **and ships an MCP client**:
  `src/Client/` (`ClientManager`, `Transport`, `OAuth`, `Protocol`, `NegotiatedConnection`) plus
  `Client.php`/`WebClient.php`. *Correction 2026-10-10:* an earlier version of this note, based on
  the https://laravel.com/ai/mcp overview page alone, said the package was server-only; the source
  tree disproves that. ADR-005 therefore uses `laravel/mcp` for both directions, wrapped in an own
  connection factory for HTTPS-only, host allowlist, SSRF/DNS pinning and no-redirects, which the
  library does not enforce.

**ADR-004 drafted 2026-10-10** (`docs/adrs/004-provider-neutral-ai.md`): pins `laravel/ai` **1.2.0**
exactly (released 2026-10-07). Re-verification against the 1.2.0 source added four facts the note
above lacked, each now a decision in ADR-004: the SDK fails over silently between providers when
`prompt()` receives an array (we pass one provider, always); `config/ai.php` ships `'default' =>
'openai'` (we set it to an undefined name); the Ollama driver has a hard-coded default model (we always
pass a model); and the SDK carries **no per-model capability data** (our `ModelCapabilities` registry is
the source of truth).

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

**ADR-004 D8 (2026-10-10)** lists six candidates with verified licenses and Ollama download sizes
without choosing one. Qwen2.5-3B-Instruct is excluded outright (Qwen Research License, non-commercial
only). Llama 3.2 and Gemma 3 need Andreas' ruling on attribution/flow-down obligations; Phi-4-mini
(MIT), Qwen2.5-7B and Mistral 7B (Apache-2.0) need attribution only. Lab download timing is still owed.

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

**Sprint 2 gating (2026-10-11):** **S2-18 (#100)** writes **ADR-009 Brief scheduling and send consent**
(A2's recommendation) and implements the no-default-schedule behaviour, the idempotent-per-day
generation and the login catch-up that `docs/architecture/services.md` §5 leaves owed. This is a
**scope confirmation, not a block**: the whole item is buildable on the defensible default above, and
only the suggested-time default would change — one config value. ADR-009 also answers ADR-002 D7's
deferred send-receipt durability case. Mail delivery itself stays preview-only until E3-1.

**DECIDED 2026-10-11** (coordinator under the owner's delegation): the defensible default stands —
**no default schedule**; the product shows a *suggested* time (07:00 in the Mac's zone) as a pre-filled
field only, never enabled until the person saves it and a test message has succeeded. ADR-009's scope
is as recommended in A2. Delivery stays preview-only until E3-1.

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
**Sprint 2 gating (2026-10-11):** **ADR-009 is S2-18 (#100)**, inside Sprint 2 as recommended.
**ADR-008 is deliberately not a Sprint 2 item** — it is email/OAuth strategy (workstream F) and its
substance is §15.7, which is `ANDREAS`; it is owed before the first Sprint 3 issue opens and is
recorded in `docs/ROADMAP.md`'s "Dependency gate into Sprint 3", not in the Sprint 2 backlog.

### A3 — `communications`/`messages`/`threads` (§3.1) overlap `email_messages`/`email_threads` (§5.6)
Two table sets model the same objects at different layers, and the blueprint never says whether email
messages normalize **into** `communications` or sit beside it with a join. Picking wrong is a
migration across the whole CRM timeline. **Needs:** `ENG` decision recorded in ADR-002 or a schema
ADR, before Sprint 2's E2-2 lands. **Proposed 2026-10-10 in ADR-002 D9:** `email_messages`/
`email_threads` are the provider system of record; `communications` is the CRM timeline projection
linked by `source_type`/`source_id` with correctable matching; no separate `messages`/`threads`
tables. Final when E2-2 writes the schema.
**Sprint 2 gating (2026-10-11):** **S2-01 (#84)** is that item — it moves ADR-002 D9 from *Proposed*
to final in the same PR and lands `communications` with no foreign key to `email_messages`, since that
table arrives with E3-2. S2-01 also amends **ADR-002 D10**, whose `migrations` contract group still
requires `migrate:rollback` of the last batch — which contradicts the `up()`-only migration rule the
repository already follows and `MigrationsContractTest` already reflects. `ENG`; nothing is waiting on
the owner. **A4** (`email_connections` versus `connected_email_accounts`) is closed by **S2-03 (#86)**,
which lands the table under the resolved name.

### A4 — `connected_email_accounts` versus `email_connections`
§5.6 names the table `connected_email_accounts` in its security model and `email_connections` in its
data model, three paragraphs apart. **Recommendation:** `email_connections`, since it matches the
`email_*` family and the `EmailConnection` DTO in the §5.6 adapter contract. **Needs:** `ENG`.
**Resolved 2026-10-10 (ENG, ADR-002 D8): `email_connections`.**

### A5 — "macOS 15/26" in §15.1
It is unclear whether this means "macOS 15 through macOS 26" (a supported range), "macOS 15 or
macOS 26", or a typo. §2 separately says "macOS 15+". The support matrix needs an unambiguous floor
and a highest-tested version. **Needs:** `ENG`, recorded in S0-22. **Proposed 2026-10-10 (ADR-001
D5):** read as the range macOS 15 through 26 — floor 15.0, highest tested 26.x; Apple Silicon only,
Intel explicitly unsupported. S0-22 confirms with measurements.
**Recorded in support-matrix.md 2026-10-10 (PROVISIONAL numbers until S1-14):** range 15–26 confirmed;
highest tested is 26.6.2 (25G83) on the M4 Max prototype machine; 15.x becomes "tested" only when the
minimum machine runs S1-14.

### A6 — No `docs` label exists
§16 step 1 lists ten labels; the repo has eleven (`adr` was added). Workstream J (docs/community) has
no label of its own, so its issues were filed under `release`. **Resolved 2026-10-10:** `docs` label
added; `BACKLOG.md` remapped workstream J to it.

### A7 — §14 says "PHPUnit/Pest (choose one)" and never chooses
The test scaffolding in S0-26 cannot be written twice. **Resolved 2026-10-10 (ENG): Pest.** It is
the Laravel 13 default, gives readable names for the security gates in `tests/Security/`, and
`CONTRIBUTING.md` already names it. Recorded here and in S0-26; ADR-002 need not repeat it.

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
**Measured 2026-10-10 (ADR-002 E1):** 100 000 rows in 500-row transactions, 1–12 concurrent writer
processes plus a reader, `busy_timeout` 5 s: ~100 000 inserts/s at every writer count, **zero**
`SQLITE_BUSY`, p95 batch latency 6 ms (1 writer) → 102 ms (8) → 172 ms (12). Contention costs tail
latency only; the reopen condition is not met. ADR-002 D3 sets the write-path rules (no network I/O
in a transaction, batched upserts, `BEGIN IMMEDIATE`, default 2 / max 8 sync workers). Re-run on the
ADR-001 minimum machine before Accepted.

### A10 — §17 requires "at least two optional external AI adapters" without naming them
§4 lists six candidate providers. Shipping all six in v1 multiplies the contract-test surface for no
stated benefit. **Recommendation:** pick two for v1 and treat the rest as post-v1 adapters behind the
same interface. **Needs:** `ENG`, recorded in ADR-004.
**Resolved 2026-10-10 (ENG, ADR-004 D6): OpenAI and Anthropic** are the two v1 external adapters;
OpenRouter ships as a third only if the identical contract matrix passes in Sprint 2 with no
adapter-specific code; Gemini is post-v1. Anthropic is not `EU_RESTRICTED`-eligible today (first-party
`inference_geo` is `us`/`global` only) and is offered in `CUSTOM` mode until that changes.
**Sprint 2 gating (2026-10-11):** consumed by **S2-13 (#95)**, which evaluates ADR-004 D6's OpenRouter
condition (matrix rows 1–9 green with no adapter-specific code, or post-v1 and never shown in the UI)
and names the **recommended** model per provider in `resources/ai/models.json` — `gpt-5-mini` for
OpenAI, `claude-opus-4-1` for Anthropic — as a recommendation the user can change, never a
preselection. **Already resolved; nothing is waiting on the owner here.**

### A11 — Eight real provider test accounts are a procurement item, not a coding task
§5.6 acceptance criterion 1 requires three Google, two Microsoft, one HEY, one iCloud and one generic
IMAP account running concurrently, and §13 makes F the owner of "verkliga testkonton". A HEY account
is a paid subscription; Microsoft tenant testing may need a paid tenant. **Needs:** `ANDREAS` —
accounts and budget, before Sprint 3 starts.
**Sprint 2 gating (2026-10-11): none — this is a Sprint 3 item and no S2 item waits on it.** The
adjacent question for Sprint 2 is narrower: **S2-13 (#95)** runs ADR-004 D2's twelve-row contract
matrix against a **fake gateway** in CI, so no account is needed to merge it. Only the *lab* half of
the matrix — real Ollama and LM Studio on Mac A, plus a test key for OpenAI and Anthropic — needs
credentials, and that half is recorded in ADR-004's Evidence section as a release-gate obligation, not
claimed by CI. The same pattern applies to ADR-004 D4's packet-capture locality run, which S2-14 (#96)
documents as a lab step in `docs/security/egress-guard.md`.

### A12 — Apple Developer team identity and signing credentials
§16 step 5, §12.1 and §14 all depend on a signing identity and, eventually, notarization. No
engineering work can produce one. **Needs:** `ANDREAS` — which Apple Developer team (STAFE GROUP AB
or personal) owns the app, plus the certificate and CI secrets. Blocks S1-12.
**Also decide the bundle-id / Keychain prefix (2026-10-10):** ADR-001 uses the placeholder
`com.stafe.laravelexecutiveai`, the release policy example `se.stafegroup.laravel-executive-ai`,
ADR-003 and ADR-006 `se.stafe.executive-ai.<service>`. One prefix, tied to the Team ID, before S1-06;
ADR-003's `se.stafe.executive-ai` is the recommended default since two ADRs already build on it.
**Status (S1-12, 2026-10-10):** the pipeline is built and waits only for the identity. Exactly three
repository secrets switch `.github/workflows/build-macos.yml` from unsigned to Developer-ID-signed
images on `main`: **`APPLE_TEAM_ID`** (the 10-character Team ID), **`APPLE_CERTIFICATE_P12`** (base64 of a
*Developer ID Application* certificate exported with its private key) and
**`APPLE_CERTIFICATE_PASSWORD`**; optional `APPLE_KEYCHAIN_PASSWORD`. Step-by-step in
`packaging/signing/README.md` §2. Pull requests never sign. Until the secrets exist every run produces
`*-unsigned.dmg` and says so; nothing else in the repo changes when they are added — the Team ID is
read at signing time and never committed.
**DECIDED (Andreas, 2026-10-11):** the app is signed and distributed under Andreas's **personal
Apple Developer team** (the one that ships the games), Team ID `KVH252HXD2`; the source repository
stays with the **STAFE-GROUP-AB** GitHub organisation; the bundle-id / Keychain prefix stays
`se.stafe.executive-ai` (a bundle id is not bound to the team's name, only registered under the team,
so no prefix change follows — ADR-001/ADR-003/ADR-006 keep their current values and the Keychain access
group becomes `KVH252HXD2.se.stafe.executive-ai.secrets`). `APPLE_TEAM_ID` was set as a repository
secret the same day (a Team ID is public — it is in every signed binary — but it travels through the
same secret so the workflow has one source). **Still open, Andreas only:** the team currently holds an
*Apple Distribution* certificate (App Store) and no *Developer ID Application* certificate, which a DMG
outside the App Store needs; only the Account Holder can create one (developer.apple.com →
Certificates → Developer ID Application), after which `APPLE_CERTIFICATE_P12` and
`APPLE_CERTIFICATE_PASSWORD` are set per `packaging/signing/README.md` §2. For Sprint 4 notarization
the team's existing App Store Connect API key can be reused with `notarytool`. ADR-001 L4 / RR-15 are
closed as to custody; the certificate is the remaining item.

### A13 — Third-party penetration test before v1.0
§11 makes it a blocking gate and §13 requires H to sign a risk acceptance. Vendor selection, budget
and scheduling are not engineering decisions. **Needs:** `ANDREAS`, before the Sprint 4 gate.

### A14 — Who are the DRIs, given the work is done by AI agents?
§13 assigns each workstream a DRI plus a reviewer from another team, and Sprint 0's exit criterion is
*"all DRIs approve contracts"*. With parallel AI agents and a single human owner, the exit gate has no
defined signatory, and §13.2's "2 reviewers for security-critical modules" has no second human.
**Needs:** `ANDREAS` — a ruling on who approves Sprint 0 exit and what counts as a review for
security-critical modules. Without it, Sprint 0 cannot formally close.
**Proposed configuration (S0-27, 2026-10-10):** see `docs/contributing/branch-protection.md`.
**DECIDED 2026-10-11** (under the owner's delegation): the owner is the approver of every sprint exit
and every ADR; for §13.2's second reviewer on security-critical modules the standing practice is the
one used since Sprint 1 — the implementing agent's own fixture/conformance evidence is the first review
and the coordinating agent's full-diff "second-team review" comment on the PR is the second, both
recorded in the PR thread; the owner can overrule any merge after the fact. The `main` ruleset in
`docs/contributing/rulesets/main-now.json` is finalised the same day with **`parity`** added to the
required checks (the §16 step 9 gate); `build-macos` stays optional (path-filtered); `sprint-2-exit`
is added when S2-20 lands. Applying it is the one step an agent is not permitted to perform (a
repository-settings API); **the owner applied it on 2026-10-13 as ruleset id 24986875**
(`branch-protection.md` §0). Direct pushes to `main` are over, for the coordinator too. One
parameter changed from the S0-27 proposal:
`strict_required_status_checks_policy` is **false** — with four to six agents merging in parallel, "branch
must be up to date" would force every open PR to rebase and re-run a 15-minute macOS lane after each
merge; the checks still have to pass on the PR head, and the squash-only linear history keeps `main`
bisectable. Direct pushes to `main` are over: every change, including the coordinator's doc fixes, goes
through a PR. The repository's pre-existing "Standard" ruleset carried no rules and was
left in place. Sprint 0 and Sprint 1 are formally closed on that basis.
The coordinator reviews every agent PR as the "second team" (review comment); Andreas is DRI and
posts `LGTM` on PRs that touch a security-critical path. Needs Andreas to (1) apply the `main`
ruleset (`gh api --method POST repos/STAFE-GROUP-AB/laravel-executive-ai/rulesets --input
docs/contributing/rulesets/main-now.json`) and (2) decide the second-account question: all agent
work is pushed under his own GitHub identity, so GitHub cannot record a real approval and
"require reviews" cannot be switched on; the recommendation is a second account for the
coordinator, after which `main-target.json` (1 approval + Code Owner review) is applied.
**Consequence for S0-23 (2026-10-10):** every row in the threat model's residual-risk register
(`docs/security/threat-model.md` §5) carries `Signature: pending — A14`. §13's workstream H
Definition of Done ("signed risk acceptance") cannot be met until A14 is answered, and the threat
model states that rather than implying a sign-off that has not happened.
**Consequence for S1-15 (2026-10-10):** blueprint §16 step 9 makes the §16 step 8 vertical slice
the gate on every Sprint 3 workstream issue. The slice runs in the `parity` CI job
(`docs/operations/vertical-slice.md` §5), which is **not** in the required-check list of
`docs/contributing/rulesets/main-now.json` / `main-target.json`. Before the first Sprint 3 issue is
opened, Andreas adds `{ "context": "parity", "integration_id": 15368 }` to `required_status_checks`
in both files and re-applies the ruleset (branch-protection.md §1.1) — the same admin action as (1)
above; no agent calls a settings API for it. Until then the `php` lane (required) still runs the
slice on every PR with the committed-snapshot oracle.
**Sprint 2 gating (2026-10-11):** **S2-20 (#102)** adds a second job, `sprint-2-exit`, automating
§13.1's Sprint 2 exit criterion ("security checks and brief identity") the way S1-15 automated §16
step 8 — including failing when a named Sprint 2 security test class is **missing**, not only when it
is red. It carries the same final dependency on A14: adding `sprint-2-exit` to
`required_status_checks` alongside the still-pending `parity` entry is one repository-admin action for
the owner, and `docs/operations/sprint-2-exit.md` names it as such. Nothing in the repository calls a
settings API. A `Sprint 2 — Real core` GitHub milestone also does not exist yet; creating it and
assigning #84–#102 is the same kind of repository action, and the S2 issues carry their sprint in the
body meanwhile.

### A15 — The shared SSRF / egress guard has no owner and no ADR
ADR-005 D4.2(b) specifies the guard for MCP connections and says it is *shared with `Browser/Fetch`*,
and ADR-004 D4.2 specifies the same resolve-and-pin behaviour for model endpoints — but no document
specifies address normalisation (decimal `2130706433`, octal `0177.0.0.1`, hex, short-form `127.1`,
IPv4-mapped IPv6 `::ffff:127.0.0.1`, NAT64/6to4 wrappers), the userinfo-authority trap
(`http://allowed@127.0.0.1/`), the explicit cloud-metadata endpoint list (`169.254.169.254`,
`metadata.google.internal`, `169.254.170.2`, `fd00:ec2::254`, `100.100.100.200`), `100.64.0.0/10`
(shared address space, and the tailnet range), the Unix-socket/named-pipe prohibition, port
restrictions, per-hop redirect re-validation outside MCP, the response size and content-type cap, or
what a denial logs. §11 requires all of it. **Needs:** `ENG` — H with D, as a section in a new ADR or
in the E2-5 design. The proposed 16-row checklist is `docs/security/threat-model.md` §3.4.1 (threats
T-23 – T-29); blocks a safe `Browser/Fetch` in E2-5.
**Sprint 2 gating (2026-10-11):** closed **by** **S2-14 (#96)**, which amends **ADR-005 D4.2(b)** with
the §3.4.1 checklist as a named new decision in the same PR and lands `tests/Security/SsrfGuardTest.php`
as a table test with one row per threat, plus `NoSocketTransportArchTest` for T-27's GAP. `ENG`, so no
owner decision is waiting — but S2-16 (#98) must not ship a `Browser/Fetch` surface before S2-14 merges.

### RESOLVED (S2-14, 2026-10-13) — one guard, specified as ADR-005 D4.11

**Decision:** the §3.4.1 sixteen-row checklist is adopted verbatim as the specification and bound to
one implementation, `App\Security\Egress\EgressGuard` with `AddressNormalizer`. The guard has an
owner — workstream H, in `app/Security/Egress/` — and a named decision: **ADR-005 D4.11**, added in
the same PR. The six previously unstated rules are now stated there, and the full operator-level
document is [`docs/security/egress-guard.md`](security/egress-guard.md).

What the decision settles, point by point against this entry's own list:

- **Address normalisation.** Decimal, octal, hexadecimal, zero-padded, short-form and IPv4-mapped
  IPv6 spellings reduce to the textual form of the **binary** address, and the deny ranges are tested
  against that and nothing else. NAT64 (`64:ff9b::/96`) and 6to4 (`2002::/16`) wrappers are refused
  **whole** rather than unwrapped, because a socket to one of them reaches a relay and the pin could
  not describe what it connects to. Trailing dots, case, and Unicode or punycode spellings reduce to
  one host.
- **The userinfo trap.** A userinfo component, a second `@`, a control character or a
  percent-encoded host is refused as `MalformedAuthority` — never stripped, because stripping would
  connect to the second reading. The check runs on the authority **as written**, before
  `parse_url()`, which rewrites a control character to `_` instead of failing.
- **The metadata list.** All five named endpoints, by address as well as by name:
  `169.254.169.254`, `169.254.170.2`, `fd00:ec2::254`, `100.100.100.200`,
  `metadata.google.internal`. The name check is the convenience; the address check is the control,
  so a name that merely *resolves* to one is refused too.
- **`100.64.0.0/10`.** Denied, and deliberately **not** widened by `LOCAL_ONLY_ALLOW_LAN`: it is the
  tailnet range as well as CGNAT, and the toggle's warning says RFC 1918 and link-local.
- **The Unix-socket prohibition — T-27's GAP, closed.** `unix:`, `http+unix:`, `npipe:`, `file:`,
  `php:`, `data:`, `phar:` and schemeless or named-pipe targets are refused as *transports*. No HTTP
  client in `app/` may be constructed with a Unix-socket or named-pipe option, enforced by
  `tests/Security/NoSocketTransportArchTest.php`. Our own broker socket (ADR-003 D3.2) is on that
  test's explicit local-IPC allowlist, which the same test holds to being neither an HTTP client nor
  egress.
- **Port restrictions.** `443` for a public HTTPS target, plus `8443` for a model provider, because
  a self-hosted gateway on that port is a real configuration and refusing it would push owners to
  disable the guard. Loopback is legitimate for three purposes only, and our own core's fixed
  loopback port is denied to all of them except our own MCP server.
- **Per-hop redirect revalidation.** Off by default (D4.2(c) unchanged); where following is enabled,
  the whole checklist re-runs per hop. That holds structurally rather than by convention, because
  Laravel pushes global HTTP middleware *inside* Guzzle's redirect middleware.
- **Response size and content-type caps.** Deliberately **not** this guard's: it decides targets,
  not bodies. They stay with the surface that reads a body — S2-16's `Browser/Fetch`, under
  ADR-005 D4.8's 64 KiB truncation — and `docs/security/egress-guard.md` §8 says so explicitly so
  the split is a decision and not an omission.
- **Denial logging.** Every refused *attempt* writes one `audit_events` row, action
  `egress.blocked`, outcome `refused`, carrying the requested host, the resolved address, the rule
  and the purpose, and **never** the URL, whose query may hold a token. Asking the guard a question
  — the `/settings/ai` screen explaining why a provider is unavailable — writes nothing, so a page
  render cannot flood the trail.

Plus the two properties the entry did not name and the item existed for: DNS is resolved **once per
decision**, every A and AAAA record is evaluated, a host whose records disagree is refused outright,
and the chosen address is **pinned at the socket** with `CURLOPT_RESOLVE`; and anything the guard
cannot classify is refused with the rule that refused it, so there is no branch that permits a target
because nothing matched it.

**Evidence:** `tests/Security/SsrfGuardTest.php` (a table over every §3.4.1 row, 104 cases),
`tests/Security/LocalOnlyEgressTest.php` (one test per ADR-004 D4 layer),
`tests/Security/NoSocketTransportArchTest.php`. No test opens a socket: under `php artisan test` the
container binds `LoopbackHostResolver`, so even a DNS lookup cannot happen by accident.

**S2-16 (#98) is unblocked** and must declare `EgressPurpose::WebFetch` on every fetch, which is the
strictest purpose: HTTPS only, port 443 only, no loopback target at all, and off entirely under
`LOCAL_ONLY`.

### A16 — PWA browser-hardening headers are unspecified
ADR-006 D3 fixes proxies, hosts, cookies and CSRF for both origins but sets no
`Content-Security-Policy` (including `frame-ancestors`), `X-Frame-Options`, `Referrer-Policy`,
`Permissions-Policy`, `X-Content-Type-Options`, COOP or CORP. That leaves clickjacking of the
approval UI without a control and XSS without a second layer (threat-model T-21, T-22).
**Needs:** `ENG` — C (TALL/PWA) with H, recorded in S1-05's sibling item **S1-04**, which ships the
app shell and should set them by default with a `PwaSecurityHeadersTest`.
**Status (S1-02):** the headers landed in S1-02 — `App\Http\Middleware\SecurityHeaders` on the whole
`web` group with a per-request CSP nonce shared by Vite and Livewire, pinned by
`tests/Feature/Auth/PwaSecurityHeadersTest.php` and documented with the rationale for each value in
[`docs/architecture/auth.md`](architecture/auth.md) §9. S1-04's app shell ships inside them. Still
open for the tailnet origin: HSTS and the `TrustProxies`/`TrustHosts` half of D3 (E4-1).

### A17 — Spotlight and QuickLook are not excluded from the data directory
Time Machine exclusion is decided (ADR-002 D5 `tmutil addexclusion`; ADR-003 E26
`isExcludedFromBackupKey`), but nothing addresses Spotlight indexes or QuickLook thumbnails built
from `data/`, `data/backups/` or a diagnostics export — plaintext derivatives of customer content
outside our deletion story (threat-model T-45). **Needs:** `ENG` — A (macOS/Swift) with B, in E4-2.

### A18 — Where the privacy mode lives, who may change it, and whether the change is audited
ADR-004 D4/D5 define what `LOCAL_ONLY`, `EU_RESTRICTED` and `CUSTOM` *do*, and ADR-001 D4 removes
`.env` — but no ADR says where the mode and the `LOCAL_ONLY_ALLOW_LAN` toggle are stored, who may
change them, whether a downgrade needs re-authentication, what audit event it writes, or how the UI
re-confirms (threat-model T-68). **Needs:** `ENG` — D with H, in E2-5.
**Sprint 2 gating (2026-10-11):** split across two items. **S2-03 (#86)** lands the storage —
a `privacy_policies` table holding the active mode and the `LOCAL_ONLY_ALLOW_LAN` toggle with the
changing user and the audit event id. **S2-14 (#96)** lands the rule and amends **ADR-004** with the
answer: a downgrade requires the S2-05 (#69) re-authentication plus a UI re-confirmation and writes a
named audit event, proven by `PrivacyModeDowngradeTest`. **S2-19 (#101)** is where the change is made
(the `/admin` security-rules pane), under ADR-004 D5 and ADR-007 D6's evidence-driven admission rules.
`ENG` throughout — no owner decision is waiting.
**Status (S2-03, 2026-10-13):** the storage half is landed. `privacy_policies` holds `mode`
(`LOCAL_ONLY` · `EU_RESTRICTED` · `CUSTOM`, `App\AI\Routing\PrivacyMode::configValue()`), `allow_lan`,
`source`, `reason`, `changed_by_user_id`, `audit_event_id`, `reauthenticated_at`, `effective_from`
and `superseded_at`; it is append-only and
`privacy_policies_organization_active_unique (organization_id, active_marker)` makes two live
policies for one workspace impossible to record (`docs/architecture/data-model.md` §2,
`tests/Integration/BackendContract/GovernanceSchemaContractTest.php`). Still open for S2-14: which
transitions require re-auth, what the UI re-confirms, the named audit event, and the ADR-004
amendment.

### RESOLVED (S2-14, 2026-10-13) — the rule, as ADR-004 D9

**Decision**, added as **ADR-004 D9** in the same PR, implemented in
`App\Security\Privacy\PrivacyModeStore` and documented for operators in
[`docs/architecture/privacy-modes.md`](architecture/privacy-modes.md):

- **Where it lives.** `privacy_policies`, append-only, at most one live row per workspace.
  `config('lea.privacy_mode')` keeps exactly two jobs — the value the install-time default row is
  written from, and the fallback for a boot before migrations — and **the stored row always wins**.
  Anything unparseable falls closed to `LOCAL_ONLY`.
- **Who may change it.** The workspace owner, through a signed-in PWA session.
  `OwnerAbilities::isOwner()` is the same check the IPC abilities use, so the menu bar cannot change
  the mode at all and no artisan command does either. S2-19 (#101)'s `/admin` security-rules pane is
  the one surface that calls the store.
- **Whether a downgrade needs re-auth.** Yes, and **only** a downgrade. A change that widens where
  model traffic may go — a mode of greater breadth, or `LOCAL_ONLY_ALLOW_LAN` turning on — needs a
  fresh `CriticalAction::ChangePrivacyMode` proof (S2-05, #69) **and** an explicit UI
  re-confirmation, so submitting the form is not the same as confirming it; the proof's verification
  time lands in `reauthenticated_at`. **Tightening needs neither**, because a control that makes it
  laborious to become stricter is a control that gets left loose.
- **What audit event it writes.** `privacy.mode_changed`: actor `user:<ulid>`, subject
  `privacy_policies/<id>`, context carrying the old and new mode, the old and new LAN flag, whether
  the change widened the boundary, the re-authentication method and the owner's reason. The event
  and the policy row are written in **one transaction** and the row stores the event id, so a mode
  in force without an audit trail is not representable. A refused change writes neither.
- **How the UI re-confirms, and the half of T-68 that is about honesty.** Every surface that
  displays the mode now reads the store rather than the config seed — `/health/ready`, the menu bar
  that polls it, and the PWA shell the brief and chat pages render inside. Enforcing the stored mode
  while displaying the configured one would have told the owner the opposite of the truth at exactly
  the moment it mattered.
- **The LAN toggle.** Storable only in `LOCAL_ONLY` (`PrivacyMode::supportsLanToggle()`), because it
  has nothing to widen in the other two modes — which is what lets the egress guard treat the opt-in
  as a single branch (RFC 1918 and link-local, nothing else) rather than a special case per mode.
  RR-18 is unchanged: off by default, warned, and a public IP never admitted in this mode.

**Evidence:** `tests/Security/PrivacyModeDowngradeTest.php` — the gates, the append-only history, the
audit event, and the brief surface reflecting the stored mode. **S2-19 (#101)** builds the pane on
this store and adds no second writer.

### A19 — Contradiction: which processes may call Keychain APIs
ADR-003 D2 says "exactly **one** process holds the shared access group: the Supervisor", its D5
entitlement table gives the Peekaboo automation helper **Keychain: none** and says it "is forbidden
from ever holding a credential", and its forbidden list bans "Keychain API calls anywhere except the
Supervisor (and the GUI app's own S0-15 pairing item)". ADR-005 D3.4 has the **helper** generate a
256-bit session token and store it in a Keychain item `<bundle-prefix>.automation.session` "with an
ACL limited to the helper and the core's signing identity" — a third Keychain writer, holding a
credential, using the legacy `SecAccess` ACL mechanism that ADR-003 D5 keeps only as a *fallback*.
One of the two ADRs must change: either ADR-003 D1/D5 gains a helper row, or ADR-005 D3.4 moves the
token into the broker. **Needs:** `ENG` — H owns the reconciliation. Recorded in
`docs/security/threat-model.md` §6.2 C1 (threats T-59, T-60); blocks E3-6 and the ADR-003 D1
inventory. S0-23 deliberately did not resolve it.

### A20 — `Mcp::local('executive')` has no documented caller authentication
ADR-005 D4.10 protects the HTTP variant of our own MCP server with `auth:sanctum`, `throttle:mcp`,
`Origin` validation and a loopback bind, but the stdio variant offered "for local editors" is spawned
by whoever can run it — any same-user process — and would expose `crm.search`, `tasks.list` and
`brief.read` without a session. The MCP spec itself says stdio implementations should retrieve
credentials from the environment rather than follow the authorization spec, which is not a design yet.
**Needs:** `ENG` — G with H: either drop the local variant from v1 or define its credential
(threat-model T-55).

### A21 — Two Time Machine exclusion mechanisms
ADR-002 D5 has the installer mark the data directory with `tmutil addexclusion`; ADR-003 D6/E26 uses
`URLResourceKey.isExcludedFromBackupKey`, which E26 itself warns "resets to `false`" after common
file operations. Both may be intended for different paths (the data directory vs the GUI brief
cache), but neither ADR says so, and the weaker mechanism silently failing is exactly how unencrypted
copies accumulate (threat-model T-43). **Needs:** `ENG` — A with B, one sentence in whichever ADR
owns the data directory.
**Status (S1-06):** implemented as `tmutil addexclusion` on `data/database/` and `data/storage/framework/`
only — not on all of `data/` — so `data/backups/` stays inside Time Machine as ADR-002 D5 wants
(`docs/architecture/runtime-layout.md` §4). `isExcludedFromBackupKey` remains the mechanism for the
GUI brief cache (S1-09). Andreas confirms or changes the scope; ADR-002 D5 gets the sentence then.

### A22 — The helper's peer-verification fallback must be unreachable in a shipped build
ADR-005 D3.3 verifies the core's code signature on `automation.sock` but falls back to
"uid + token + signature" **and logs a warning** if the bundled PHP binary is not signed by our team.
ADR-001 D2 says it is signed, so the fallback should be unreachable in a notarised build — but it
remains a code path, and a silent downgrade of the strongest control on that boundary belongs in
health, not in a log line (threat-model T-60). **Needs:** `ENG` — G with A and H: make the fallback
refuse to engage when `app()->isProduction()`, with a test, or delete it.

### A23 — Two licence findings from the first real FrankenPHP build (S1-06)
`packaging/runtime/build-runtime.sh` built the curated binary on 2026-10-10 (FrankenPHP 1.13.0,
PHP 8.5.11 ZTS, Caddy 2.11.7, static-php-cli 2.8.5; 146 MB, system libraries only) and found two
things the ADRs do not cover (`docs/architecture/runtime-layout.md` §5):

1. **Mercure (AGPL-3.0) is linked by FrankenPHP's own `caddy/` module** even when it is left out of
   the xcaddy `--with` list, which is all ADR-001 D1/E4 relies on. The `nomercure` Go build tag is
   required; static-php-cli 2.8.5 hard-codes its tag list, so the recipe patches the pinned source
   behind an exact-string guard and verifies the tag in `go version -m`. **Needs:** `ENG` — ADR-001 D1
   names the tag and the patch; owner A with H.
2. **GNU libiconv (LGPL-2.1) is statically linked** because static-php-cli builds `libxml2` (a
   dependency of `php` itself on macOS) against it; the upstream release artefact has the same. ADR-007
   D2.2 forbids this without a legal opinion and ADR-001 L1 did not list it. The build gate fails
   closed on any GPL/LGPL/AGPL notice; the measurement used `LXAI_ALLOW_COPYLEFT_FOR_MEASUREMENT=1`
   and that binary must not be signed or shipped. Options: patch the libxml2 recipe to use the macOS
   system iconv, or obtain the L-1 opinion on LGPL §6 for a static link. **Needs:** `DECISION` —
   Andreas (with L-1); blocks S1-12 (the signed artefact).

**Status (S1-12, 2026-10-10):** S1-12 shipped the DMG pipeline **without a runtime** rather than wait:
the image is app + Supervisor only, `Contents/Resources/runtime/` is absent, `runtime.json` stays
`status: unbuilt`, and the image's `README.txt` says so. The installer stops at `PACKAGE_VERIFY` on
that build. The steps that follow the decision (sign `frankenphp` with `allow-jit` only, re-assemble
`--status signed`, copy into the bundle) were listed in `packaging/signing/README.md` §4 and
`docs/operations/release-build.md` §5.

**DECIDED (Andreas, 2026-10-11, delegated to the runtime agent):** both findings are fixed in the
build recipe rather than argued away — **no legal-opinion route**.

1. The **`nomercure` Go build tag** is applied (and `nobadger,nomysql,nopgx,nowatcher` as upstream
   has them), on top of keeping Mercure and Vulcain out of the xcaddy `--with` list. ADR-001 D1 now
   names the tag and the patch.
2. **static-php-cli's libxml2 recipe is patched to use the macOS system iconv**, so no GNU libiconv
   is statically linked and the LGPL question never arises for the shipped artefact. ADR-007 D2.2
   already prescribed exactly this route ("use the macOS system iconv"); it is now recorded as done,
   with the behavioural note below. Three edits to the pinned spc 2.8.5 source, each guarded by its
   exact text and re-checked after the build (`packaging/runtime/build-runtime.sh`):
   `config/lib.json` makes libxml2's `libiconv` dependency Linux-only, `config/ext.json` gives the
   `iconv` extension `arg-type-macos: with` (so PHP configures `--with-iconv`, not
   `--with-iconv=<buildroot>`), and the libxml2 recipe hands CMake `Iconv_INCLUDE_DIR` /
   `Iconv_LIBRARY` from the macOS SDK — needed because spc's generated toolchain file restricts
   `find_package()` to its own buildroot, so `find_package(Iconv)` otherwise fails outright
   ("Could NOT find Iconv", measured 2026-10-13).

**Evidence (built 2026-10-13, Apple M4 Max, macOS 26.6.2, Xcode 26.2, ~16 min cold; full numbers in
[`docs/architecture/runtime-layout.md`](architecture/runtime-layout.md) §5):**

- The licence gate **passes with nothing set** — `LXAI_ALLOW_COPYLEFT_FOR_MEASUREMENT` is not used,
  and CI never sets it (pinned by `packaging/runtime/test/build-runtime-workflow.test.mjs`).
  `spc dump-license` yields 12 notice files (curl, ICU, libpng, libsodium, libxml2, libzip, oniguruma,
  OpenSSL, SQLite, zlib, zip, php-src), **0** with GPL/LGPL/AGPL text and **no libiconv entry at all**.
- `go version -m` reports `build -tags=nobadger,nomysql,nopgx,nomercure,nowatcher` and **no**
  `github.com/dunglas/mercure` or `vulcain` dependency line.
- `otool -L` lists only `libSystem`, `libresolv`, `libc++`, `libutil`, `/usr/lib/libiconv.2.dylib`
  and the CoreFoundation/CoreServices/SystemConfiguration/Security frameworks. The iconv entry is
  the macOS system library: dynamically linked, provided by the OS, never redistributed by us —
  and no `libiconv.a`/`libcharset.a` exists in the build tree (the recipe fails closed if one does).
- Functional check on the artefact: `iconv` round-trips UTF-8 ↔ ISO-8859-1 and Windows-1252, and
  libxml2 decodes `encoding="ISO-8859-1"` and `encoding="SHIFT_JIS"` documents correctly through the
  system library — the one real risk of the change. **Behavioural note:** macOS's iconv reports
  `ICONV_IMPL=libiconv, ICONV_VERSION=1.11` (GNU libiconv 1.17 before), and its `//TRANSLIT` output
  differs (`Räksmörgås` → `R"aksm"oagas` instead of `Raksmorgas`). Nothing in the product uses
  `//TRANSLIT`; a feature that needs it must transliterate in PHP, not in iconv.

**Shipping it (S1-12, this PR):** the package is built, gated, signed and published by
`.github/workflows/build-runtime.yml` (dispatch only) as a tarball on a pre-release tag
`runtime-<php>-<frankenphp>-<n>`; `packaging/manifest/runtime.json` gains `status: signed` plus the
`artifact` member that pins the asset URL, size and sha256; `build-macos.yml` downloads it, verifies
the digest, unpacks it into `Contents/Resources/runtime/` **before** signing and refuses a runtime
from another Apple team. A23 is **closed**.

### A24 — The Supervisor's container: bare tool (ADR-001 D3) or helper bundle (ADR-003 D5) — DECIDED 2026-10-11
ADR-001 D3 places the Supervisor at `Contents/MacOS/LaravelExecutiveAISupervisor` (a bare Mach-O,
`BundleProgram` points straight at it); ADR-003 D5 says it "must be packaged as a bundle
(`Contents/Helpers/LaravelExecutiveAISupervisor.app`) … with a Developer ID provisioning profile
embedded" because `keychain-access-groups` is a restricted entitlement, and claims ADR-001 D3 "now
specifies" that — it does not. S1-07 built ADR-001 D3 literally (`docs/architecture/services.md` §1):
a `com.apple.product-type.tool` target embedded by the app. If the data-protection keychain refuses a
bare tool on the signed build, the fix is a product-type change and a one-line `BundleProgram` change,
nothing in the code. **Needs:** `ENG` — A with H, decided by the S1-12 signing prototype; whichever
ADR is wrong gets amended in the same PR.
**Findings (S1-12, 2026-10-10) — still open, because the prototype cannot run without A12:**
1. The signing step (`packaging/signing/sign.sh`) signs the bare tool as ADR-001 D3 built it, with
   `LaravelExecutiveAISupervisor.entitlements` and `$(AppIdentifierPrefix)` expanded to `<TEAM ID>.`;
   `codesign` accepts a restricted entitlement on a bare Mach-O without a provisioning profile and
   `codesign --verify` passes. So a **green CI signing report is not evidence either way** — `codesign`
   does not validate restricted entitlements; AMFI does, at launch, on a real Mac.
2. Apple's documented rule still favours ADR-003 D5: restricted entitlements
   (`keychain-access-groups`, `com.apple.application-identifier`) are authorised by a provisioning
   profile, a bare Mach-O cannot carry `embedded.provisionprofile`, and Developer ID profiles do
   include `keychain-access-groups: [<TEAM ID>.*]`. The expected outcome of the first signed launch is
   therefore `Killed: 9` with *restricted entitlements not validated* in the `amfid` log, and the fix
   is the one services.md §1 names (product type → application bundle under `Contents/Helpers/`,
   profile embedded, `BundleProgram` path) plus an **ADR-001 D3** amendment. If the tool launches and
   `SecItemAdd` into the access group succeeds instead, **ADR-003 D5** loses its "must be a bundle"
   sentence.
3. The pipeline is structurally ready for both: a helper bundle is nested code and slots into the
   inside-out order before the app with its own entitlements file; the `$(AppIdentifierPrefix)`
   expansion then moves back to Xcode. The exact commands and the pass/fail criteria for the lab run
   are the checklist in `packaging/signing/README.md` §5; the verdict is recorded here and in
   services.md §1 in the same PR as the ADR amendment (S1-14 lab Mac).

**DECIDED 2026-10-11 (lab verdict; delegated to ENG): the helper bundle. ADR-003 D5 was right;
ADR-001 D3 is amended.** The first Developer-ID-signed image (build 7a74ef5, CI run 38120770614)
was mounted on the owner's Mac and `Contents/MacOS/LaravelExecutiveAISupervisor` was launched
directly: it terminated immediately with **exit 137 (SIGKILL)** — prediction 2 above, confirmed.
macOS validates a restricted entitlement (`keychain-access-groups`) at launch against a
provisioning profile embedded in the signed bundle, and a bare Mach-O cannot carry one. Note that
`codesign --verify` had passed in CI on the same binary, which is exactly why finding 1 says a green
signing report decides nothing here.

What now ships (this PR):
- the Supervisor target is `com.apple.product-type.application` with its own `Info.plist`
  (`LSUIElement`, `LSBackgroundOnly`, `CFBundlePackageType APPL`, and `LXAIAppBundleIdentifier`
  naming the app), embedded by the app at `Contents/Helpers/LaravelExecutiveAISupervisor.app`;
- `BundleProgram` =
  `Contents/Helpers/LaravelExecutiveAISupervisor.app/Contents/MacOS/LaravelExecutiveAISupervisor`,
  and `AssociatedBundleIdentifiers` lists the app **and** the helper so Login Items attributes the
  job either way;
- the entitlements file adds `com.apple.application-identifier`, mirroring the profile;
- `SupervisorIdentity` resolves the **outer** app's identifier (the innermost enclosing `.app` is
  now the helper itself, which would have poisoned the Mach service name, the plist name and the
  Application Support path);
- `packaging/signing/sign.sh` decodes the new repository secret `APPLE_SUPERVISOR_PROFILE` into
  `…app/Contents/embedded.provisionprofile` before signing the helper, and **fails closed** without
  it — no override, since the only build such an override could produce is one whose Supervisor
  macOS kills. The profile (Developer ID, `MAC_APP_DIRECT`, bundle id
  `se.stafe.executive-ai.supervisor`) was created through the App Store Connect API and carries
  `keychain-access-groups` = `[<TEAM ID>.*]` plus
  `com.apple.application-identifier` = `<TEAM ID>.se.stafe.executive-ai.supervisor`. Nothing about
  it is committed.

Nothing in the Supervisor's code or process model changed. **Re-test on the next signed image**
(`packaging/signing/README.md` §5): the helper's inner executable, launched directly, must now run
and exit **78** (`EX_CONFIG` — no runtime installed yet, A23) instead of 137. A24 stays `DECIDED`
unless that re-test fails, in which case the profile/bundle-id pairing is the first suspect.

### A25 — A keep-data uninstall deletes `core.app-key` while keeping the database (ADR-003 D8)
S1-13 (#41) ships the ADR-003 D8 promise literally: uninstall deletes **every**
`se.stafe.executive-ai.*` Keychain item, including `core.app-key`, even when the person chooses
*Keep my data* (`docs/operations/uninstall.md` §5). In Sprint 1 that is harmless — nothing in `data/`
is encrypted with `APP_KEY` yet (connector credentials live in the Keychain, not the database) — and
the Supervisor generates a fresh key on the next boot. From the first encrypted attribute or
encrypted cast (Sprint 2+: OAuth state, MCP server config, anything under `encrypted:` casts), a
keep-data uninstall followed by a reinstall would leave rows that can no longer be decrypted.
Options: (a) a keep-data uninstall keeps `core.app-key` (and only it) and D8's wording becomes
"every item except the application key when data is kept"; (b) the uninstaller re-encrypts with a
throwaway key written into `data/` before deleting — rejected on sight, it moves the key next to the
data; (c) keep-data uninstall exports nothing and the docs say encrypted rows are lost — a bad
surprise for exactly the person who chose to keep their data. **Recommended:** (a), decided before the
first `encrypted` cast lands; the `previous[]` field of the `laravel_app_key` envelope already
supports rotation afterwards. **Needs:** `DECISION` — H (security lead) with A; gate on the first
Sprint 2 item that adds an encrypted column.

**Sprint 2 gating (2026-10-11):** that item is now named — **S2-04 (#87)**, the only Sprint 2 item
permitted to add an `encrypted:` cast, which `EncryptedAttributeInventoryTest` enforces for every
other item. S2-04 is a **hard block** on this decision: nothing in it is buildable before the answer.
Everything around it is — S2-01 (#84), S2-02 (#85) and S2-03 (#86) land every table, index and
contract test, and the CRM, chat and brief items write to them, all with plaintext columns classified
by `sensitivity_class`. **Answer it in wave 1 even though S2-04 runs in wave 3:** a late answer costs
S2-04 a backfill migration that re-writes rows which by then already exist. S2-04 amends ADR-003 D8
and `docs/operations/uninstall.md` §5 in the same PR. See `docs/ROADMAP.md` "Owner decisions that gate
specific items".

**DECIDED 2026-10-11 — option (a)** (taken by the coordinator under the owner's delegation of the
same day, recorded here as the owner's decision): a keep-data uninstall keeps **`core.app-key` and only
it**; every other `se.stafe.executive-ai.*` item is still deleted. ADR-003 D8's wording becomes "every
item except the application key when data is kept"; a delete-everything uninstall deletes the key too.
S2-04 (#87) is unblocked and amends ADR-003 D8 and `uninstall.md` §5 in its PR; S1-13's `Uninstaller`
gains the one exception in the same PR (the Supervisor's `removeAllSecrets` takes a `keepApplicationKey`
flag, default false).
