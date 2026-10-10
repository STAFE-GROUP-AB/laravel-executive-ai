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
ADR, before Sprint 2's E2-2 lands. **Proposed 2026-10-10 in ADR-002 D9:** `email_messages`/
`email_threads` are the provider system of record; `communications` is the CRM timeline projection
linked by `source_type`/`source_id` with correctable matching; no separate `messages`/`threads`
tables. Final when E2-2 writes the schema.

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

### A11 — Eight real provider test accounts are a procurement item, not a coding task
§5.6 acceptance criterion 1 requires three Google, two Microsoft, one HEY, one iCloud and one generic
IMAP account running concurrently, and §13 makes F the owner of "verkliga testkonton". A HEY account
is a paid subscription; Microsoft tenant testing may need a paid tenant. **Needs:** `ANDREAS` —
accounts and budget, before Sprint 3 starts.

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
that build. Both findings remain open; the steps that follow the decision (sign `frankenphp` with
`allow-jit` only, re-assemble `--status signed`, copy into the bundle) are listed in
`packaging/signing/README.md` §4 and `docs/operations/release-build.md` §5.

### A24 — The Supervisor's container: bare tool (ADR-001 D3) or helper bundle (ADR-003 D5)
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
