# ADR-007: MIT for our code; bundle only notice-only binaries, never model weights; claim features, not compliance

| | |
|---|---|
| **Status** | Proposed |
| **Date** | 2026-10-10 |
| **Deciders** | J (Docs/community) DRI, H (Security/privacy) reviewer, A (macOS/Swift) reviewer for the bundled-binary list, Owner (Andreas) for every item marked NEEDS LEGAL REVIEW — `OPEN-QUESTIONS.md` §15.10 is `ANDREAS` |
| **Closes** | Blueprint §15 item 10; `OPEN-QUESTIONS.md` §15.10 and the licensing half of §15.6. Cross-references A2 (ADR-008/009 planned, not written here), A11, A13 |

## Context

The project license is already decided: MIT, copyright STAFE GROUP AB (`LICENSE`, `docs/ROADMAP.md`
"Decisions already closed"). §15.10's open half is everything *around* that decision:

- §2 and §12.1 require "licenskompatibla, versionslåsta, signerade/notariserade artefakter" with
  "PHP/runtime included", and §12.1 orders an escalation at architecture review "if signed packaging
  of PHP/runtime proves legally or technically impractical" — never a silent Homebrew dependency.
- §10 opens with "open source-distribution är inte ett generellt undantag" and §10.9 adds that a local
  model "betyder inte per automatik att modellens träningsdata, licens eller användning är fri från
  compliance-frågor; kontrollera modelllicenser och redistribution".
- §0 forbids "påståenden om formell 'EU AI Act-certifiering'"; §17 requires a documented GDPR/AI Act
  use-case assessment "utan falska certifieringspåståenden".
- §10 demands a *legally reviewed* compliance mapping "med giltiga datum vid release, inklusive
  eventuella ändringar i EU AI Act:s implementeringstidslinje" — and that timeline did change in 2026.
- §14's release checklist requires "SBOM + license audit"; §11 requires SBOM and SCA.

What breaks if this stays open: ADR-001 cannot name a PHP build without knowing which statically
linked libraries are acceptable; ADR-004 cannot offer a "small tested model download" without knowing
which licenses may be shown and accepted at runtime; README, installer and PWA copy will drift into
"GDPR compliant" / "fully private" phrasing that no one has reviewed; and Sprint 4's release gate has
no definition of what the license audit must prove.

This ADR records what an engineer can establish from primary sources, and names, item by item, what
only a qualified legal reviewer can close. **Engineers do not accept legal risk** (template, Evidence).

## Decision

### D1. Project license

1. All first-party code, documentation, fixtures, design tokens and build scripts in this repository
   are licensed MIT, copyright STAFE GROUP AB, as in `LICENSE`. Contributions are accepted under MIT
   only (`CONTRIBUTING.md`). No CLA, no dual licensing, no "source-available" carve-outs.
2. The product name is **Laravel Executive AI**. It does not contain "PHP" (PHP-3.01 clause 4) and the
   "Laravel" trademark is used descriptively; whether the name needs a trademark licence from Laravel
   Holdings Inc. is item L-7 below.

### D2. Bundled executables — admission rule

A binary may ship inside the signed `.app`/`.dmg` or the app-owned runtime in Application Support
(§12.1) only if **all** of the following hold:

1. Its license is on the admitted list: MIT, BSD-2/3-Clause, Apache-2.0, ISC, Zlib, 0BSD, PHP-3.01,
   PostgreSQL, curl, Unicode/ICU, libpng-2.0, bzip2-1.0.6, IJG, SQLite public domain, FTL (chosen
   over GPL-2 in FreeType's dual option), ImageMagick. These are notice-only licenses: attribution
   and license text in a shipped notices file satisfy them.
2. **No GPL, LGPL, AGPL, MPL, SSPL, EUPL, BUSL or "research/non-commercial" code is statically
   linked into or shipped as a separate binary** without a written legal opinion (item L-1). This
   concretely excludes the `gettext`/`intl` and GNU `libiconv` options in static-php-cli builds; the
   PHP build **must** be configured without `gettext` and without GNU libiconv (use the macOS system
   iconv or drop the `iconv` extension). GNU readline is excluded; static-php-cli's `readline`
   extension links `libedit` (BSD-3-Clause), which is admitted.
3. Every third-party license text and required notice is shipped in
   `Resources/THIRD-PARTY-NOTICES.txt` inside the `.app` and surfaced in the Mac app's About window
   and the PWA's `/about/licenses` page. The file is **generated, not hand-written**, in CI from
   `bin/spc dump-license` (static-php-cli), `composer licenses --format=json`, the npm license report
   and the Swift Package manifest, and is diffed against `docs/compliance/third-party-licenses.md`
   so an undeclared dependency fails the build (S0-05 CI, S0-28 release policy).
4. The PHP-3.01 acknowledgement "This product includes PHP software, freely available from
   <http://www.php.net/software/>" appears verbatim in the notices file and the About window for as
   long as the bundled PHP is 8.5 or older. (PHP 8.6+ is announced to move to PHP License 4.0 = BSD-3;
   ADR-001 records the exact version, and this clause is re-checked then.)
5. Caddy (inside FrankenPHP) is Apache-2.0: ship the license text; the repository has no `NOTICE`
   file as of 2026-10-10, so Apache §4(d) adds nothing further — re-check at each version bump.
6. Each bundled executable is individually Developer ID-signed with hardened runtime and secure
   timestamp before notarization (ADR-001 owns the mechanics; this ADR only records that
   notarization is a malware scan, not a license review — the rights question stays with us).

### D3. Invoked, never bundled

- **Tailscale**: the macOS GUI client (App Store and standalone) is proprietary and governed by
  Tailscale's terms of service; only `tailscaled`/the CLI are BSD-3-Clause. We detect and invoke the
  user's installed Tailscale (ADR-006); we never redistribute any Tailscale binary, never use the
  Tailscale name or logo to imply endorsement (BSD §3), and the installer links to Tailscale's own
  download page.
- **Ollama / LM Studio / MLX endpoints**: detected and reused (§12.1). Ollama is MIT (llama.cpp and
  ggml inside it are MIT) and *could* be bundled; we do not, because bundling an inference runtime
  also drags in its default model-pull behaviour and update channel. Revisit in ADR-004 if detection
  proves unreliable.
- **Peekaboo**: MIT (copyright Peter Steinberger; repo lives under the `openclaw` org — A1 is ruled
  on in ADR-005, not here). Only "den verifierade versionen från definierad releasekälla" (§6) may be
  fetched, checksum- and signature-verified, at the user's opt-in.

### D4. Local models — never in the DMG, download-at-runtime with license shown and accepted

1. **No model weights ship in the `.dmg`, the `.app`, or the runtime manifest** until Andreas has a
   written legal opinion that the specific model may be redistributed inside an MIT-licensed product
   (`OPEN-QUESTIONS.md` §15.6 "Defensible default"). This is binding for Sprints 1–4.
2. The "offer one small tested model" path (§0, §12.1, ADR-004) is implemented as **download at
   runtime by the user**, through the user's own Ollama/LM Studio or a direct Hugging Face fetch,
   with this product behaviour: the model register entry shows the license name, a link to the
   license text and the vendor's acceptable-use policy; the user ticks "I have read and accept the
   model license" before the download starts; the acceptance (model id, license URL, license text
   hash, timestamp) is recorded in the local `model_register` audit row; a model whose license
   requires passing on the agreement (Llama, Gemma, Qwen License) is shown with that requirement
   spelled out. The download is explicitly asynchronous and never counted in the five-minute gate.
3. Per-model verdicts as of 2026-10-10 (details and quotes in
   `docs/compliance/third-party-licenses.md` §3):

   | Model | License | Bundle in DMG | Runtime download | Why |
   |---|---|---|---|---|
   | Llama 3.1 / 3.2 / 3.3 (Meta) | Llama 3.x Community License (not OSI) | **No** | Yes, with acceptance | Must display "Built with Llama", pass on the Agreement, AUP binds users, 700 M MAU clause; redistributing weights makes STAFE a licensor of Meta terms inside an MIT app — L-3. **Llama 3.2 multimodal (vision) rights are not granted to entities domiciled in the EU** — the app must hide Llama 3.2 11B/90B Vision for EU workspaces; text-only 1B/3B are unaffected |
   | Qwen2.5 7B / 14B, Qwen3 (all sizes) | Apache-2.0 | Needs review (L-4) | Yes | Apache-2.0 is MIT-compatible; weights are large and training-data provenance is §10.9's open question |
   | Qwen2.5 **3B** | Qwen Research License | **No** — forbidden for our users too | **No** | "FOR NON-COMMERCIAL PURPOSES ONLY" — must not be offered or suggested by the app |
   | Qwen2.5 72B | Qwen License (100 M MAU) | No | Yes, with acceptance | Too large for the Mac profile anyway |
   | Mistral 7B Instruct v0.3 | Apache-2.0 | Needs review (L-4) | Yes | Same as Qwen Apache-2.0 sizes |
   | Gemma 3 (1B/4B/12B) | Gemma Terms of Use (Google may update; Prohibited Use Policy; gated repo) | **No** | Yes, with acceptance | Must pass on Terms and use restrictions as "enforceable provision"; incompatible with an MIT grant — L-3 |
   | Phi-4 / Phi-4-mini (Microsoft) | MIT | Needs review (L-4) — the cleanest candidate | Yes | MIT weights; still subject to §10.9 training-data review |

4. The app **must refuse** to register a model whose license tag it recognises as non-commercial or
   research-only (Qwen Research License, Mistral Research License, CC-BY-NC-*) for any workspace that
   is not flagged "personal, non-commercial" by the user. Unknown license tags are shown as
   "License: unknown — review before use" and require the same acceptance tick.

### D5. Compliance boundaries — what the product, README and marketing may and may not say

Permitted (each tied to a feature or a test):

- "Self-hosted — runs on your Mac."
- "In Local Only mode no prompt, embedding, screenshot or tool transcript leaves your Mac — verified
  by the packet-capture locality test `tests/Security/LocalOnlyEgressTest` (§14)." The sentence may
  only be published while that test is green in CI.
- "Supports your GDPR obligations with export, rectification, erasure, retention rules, an access log
  and a processor register (§10.3)." Always "supports", never "makes you compliant".
- "AI-generated content and automated actions are labelled as such (§10.4; AI Act Art. 50)."
- "Telemetry is off by default; diagnostics export is sanitised and requires your action (§10.8, §14)."
- "Model and provider register shows processing region, subprocessors and DPA status as declared by
  the provider" — with the word *declared*.

Forbidden phrasings (code review, docs review and the UI copy linter in S0-05 reject them):

- "GDPR compliant", "GDPR certified", "100 % GDPR", "makes you GDPR compliant" — compliance is the
  controller's (the user's organisation's) obligation; GDPR Art. 42 certification exists only through
  approved schemes and bodies, and this product holds none.
- "EU AI Act certified", "EU AI Act compliant", "AI Act approved", "conformity assessed" (§0).
- "EU-safe provider", "GDPR-safe provider", "EU provider" **inferred from a `.eu`/`.de`/`.se` domain
  or a marketing page** (§4 Europe Restricted: "flagga inte leverantörer som EU-säkra utifrån
  domännamn"). A provider is shown as EU_RESTRICTED-eligible only when register fields R2–R5 below
  are filled from the provider's own contractual documents.
- "Fully private", "nothing leaves your device", "zero data sharing" **whenever any cloud provider,
  remote MCP server, email sync or GitHub integration is enabled**. §4 requires the separate category
  "explicit internet connection for sync" to be described transparently.
- "Anonymous telemetry" — there is none; say "no telemetry".
- "Legally reviewed" — until the review in L-9 has actually happened and is dated.

### D6. Model/provider register fields (§10.2, §10.6)

Every entry in `ai_model_register` / `ai_provider_register` carries, and the admin UI shows:

| Field | Content | How it is verified |
|---|---|---|
| R1 `processing_region` | Region(s) where prompts are processed and stored | Provider's DPA/region documentation URL + date checked; for local runtimes the literal `this-mac` |
| R2 `subprocessors` | Provider's current subprocessor list URL + date snapshot | Manual review at registration; re-check reminder every 90 days |
| R3 `dpa_url` and `dpa_status` | Link to the provider's DPA and whether the user's organisation has executed it (`not_reviewed` / `reviewed` / `executed`) | User attestation; the app cannot verify a contract |
| R4 `tia_status` | Transfer impact assessment for third-country transfers (`not_required` / `pending` / `done`) | User attestation; `EU_RESTRICTED` requires `done` or `not_required` |
| R5 `retention` | Provider's stated prompt/output retention (e.g. "0 days — zero data retention tier", "30 days abuse monitoring") | Provider documentation URL + date |
| R6 `license` | Model license name + URL + text hash + acceptance record (D4) | Automatic |
| R7 `provenance` | Model id/version/quantisation; written into every run's `model_provenance` (§1.3 brief schema) | Automatic |

`EU_RESTRICTED` mode admits a provider only if R1 is an EU/EEA region per the provider's own
document, R3 ≥ `reviewed`, R4 is `done` or `not_required`, and R5 is filled. The mode never infers
any of this from a hostname. `LOCAL_ONLY` admits only `this-mac`. `CUSTOM` shows the user exactly
which fields are missing before enabling.

### D7. Recruitment/employment guardrail (§10.5)

Product behaviour, independent of the legal classification:

1. A **use-case declaration** is part of workspace setup: "Will this workspace be used to evaluate
   job applicants, employees or contractors (screening, ranking, performance, promotion,
   termination, task allocation based on behaviour)?" Default *No*.
2. If *Yes*: the workspace is flagged `employment_high_risk_candidate`; AI jobs `AssessProjectRisk`,
   `ExtractCommitments`, `SummarizeThread` and any custom prompt are run through a classifier-plus-
   keyword gate; outputs that rank, score, shortlist or recommend decisions about identifiable
   natural persons in a work context are **refused with an explanation**, not silently softened.
   The refusal is logged in the audit log. A human-authored note can still be stored in the CRM.
3. If *No*: the same gate runs in **flag** mode — a visible banner "This looks like a decision about
   an employee or candidate. Laravel Executive AI is not built or assessed for employment decisions
   (EU AI Act Annex III(4)). Treat this as information only, and record your own reasoning." The
   output is kept but marked in `model_provenance.guardrail = employment_flagged`.
4. **Emotion inference** about any person is refused everywhere (Art. 5(1)(f) prohibits AI "to infer
   emotions of a natural person in the areas of workplace and education institutions"). Sentiment
   of a *thread* for triage is allowed; "how does this employee feel" is not.
5. No workspace setting, prompt or MCP tool can disable the gate. Changing the declaration from
   *Yes* to *No* is itself audit-logged.

Legal framing, high level, **exact applicability NEEDS LEGAL REVIEW (L-8)**: Annex III point 4 lists
"AI systems intended to be used for the recruitment or selection of natural persons, in particular to
place targeted job advertisements, to analyse and filter job applications, and to evaluate
candidates" and systems "to make decisions affecting terms of work-related relationships, the
promotion or termination of work-related contractual relationships, to allocate tasks based on
individual behaviour or personal traits or characteristics or to monitor and evaluate the performance
and behaviour of persons in such relationships". A general-purpose secretary is not *intended* for
that; a user can still *deploy* it that way, which is why the guardrail exists. Art. 6(3) would
exempt "a narrow procedural task" or "a preparatory task to an assessment" but "shall always be
considered to be high-risk where the AI system performs profiling of natural persons" — hence D7.2
refuses ranking/scoring of identifiable persons outright rather than arguing the exemption. The
self-hosting organisation is the *deployer* (Art. 3(4)); STAFE, placing the app on the market, is the
*provider* of a general-purpose tool; a deployer who repurposes it for Annex III use becomes a provider
under Art. 25 — the installer and README say so in plain words. The Art. 2(12)
open-source exemption does **not** help here: it "does not apply to AI systems released under free and
open-source licences, unless they are placed on the market or put into service as high-risk AI systems
or as an AI system that falls under Article 5 or 50" — and we fall under Art. 50 by design.

### D8. Dates as of 2026-10-10 (§10: "giltiga datum vid release")

Source: Regulation (EU) 2024/1689 Art. 113 as amended by **Regulation (EU) 2026/1744 of 8 July 2026
("Digital Omnibus on AI")**, OJ L 2026/1744 of 24 July 2026, in force on the third day after
publication (27 July 2026) — https://eur-lex.europa.eu/eli/reg/2026/1744/oj/eng. The Commission page
`digital-strategy.ec.europa.eu/en/policies/regulatory-framework-ai` (last updated 3 August 2026)
states the same dates. The earlier proposal's "standards-availability trigger" is not in the adopted
text; the dates are fixed.

| Obligation | Applies from | Relevance to us |
|---|---|---|
| Art. 5 prohibited practices; Art. 4 AI literacy | 2 Feb 2025 (in force) | D7.4 emotion inference refusal; README literacy note |
| GPAI model obligations, governance, penalties | 2 Aug 2025 (in force) | We are not a GPAI *provider*; we deploy third-party models |
| General application incl. **Art. 50 transparency** | **2 Aug 2026 (in force)** | D5 labelling; Art. 50(2) machine-readable marking of generated text has a grace period to 2 Dec 2026 for systems placed on the market before 2 Aug 2026 — we release after, so no grace |
| Annex III high-risk (incl. employment) | **2 Dec 2027** (was 2 Aug 2026) | D7 — if a legal review says a deployment is Annex III(4), this is the date |
| Annex I product-embedded high-risk | 2 Aug 2028 (was 2 Aug 2027) | Not relevant |

Consequence: **Art. 50 is already in force on the day this ADR is written.** The Executive Brief,
the chat and every automated action must carry the AI label and a machine-readable marking of
generated text from the first release — not "later" (D5, §1.3 brief schema `model_provenance`).
The EUR-Lex summary was read, but the consolidated Art. 113 wording was not quoted verbatim by
tooling on 2026-10-10; confirm the exact amended text before the dates appear in a release note
(**L-8**).

### D9. Telemetry and diagnostics

- No telemetry endpoint exists in the codebase. There is no "opt-in telemetry" toggle in v1; the §14
  gate "telemetry opt-in" is satisfied by *absence*. Adding any outbound analytics is an ADR, not a PR.
- Diagnostics export (`Settings → Export diagnostics`) is a user-initiated file write to a
  user-chosen path; it is sanitised by `app/Support/Diagnostics/Scrubber` (tokens, email bodies,
  prompts, contact data, screenshots removed; hashes kept) and the user previews the archive list
  before saving. Nothing is uploaded. Test: `tests/Security/DiagnosticsExportScrubTest`.

### D10. SBOM and license audit (§11, §14)

CI produces a CycloneDX SBOM (`cyclonedx/cyclonedx-php-composer`, Apache-2.0 dev tool; npm and
Swift equivalents) for every release candidate, runs the license diff of D2.3, and fails on any
license outside the admitted list or any model weight file (`*.gguf`, `*.safetensors`, `*.bin` >
50 MB) in the artefact. The release checklist (S0-28) carries "SBOM published alongside checksums".

## Evidence

- **Vendor documentation / license texts (fetched 2026-10-10 unless stated):** PHP License 3.01
  clauses 1–6 (php.net/license/3_01.txt); FrankenPHP MIT (github.com/php/frankenphp LICENSE, "Copyright
  (c) 2022-present Kévin Dunglas"); Caddy Apache-2.0 §4 and absence of NOTICE file; static-php-cli
  MIT ("Copyright (c) 2022-2023 Jerry Ma"), its `config/lib.json` library list (readline, libedit,
  libiconv, gettext, icu, openssl, libxml2, zlib, onig, libzip, sqlite, curl … present) and the
  `bin/spc dump-license` command; libiconv library "under LGPL" (gnu.org/software/libiconv); OpenSSL
  "For the 3.0 release, and later releases derived from that, the Apache License v2 applies";
  laravel/framework, laravel/ai, laravel/mcp, livewire/livewire, alpinejs/alpine,
  tailwindlabs/tailwindcss, openclaw/Peekaboo, ollama/ollama = MIT and tailscale/tailscale =
  BSD-3-Clause (GitHub license API + LICENSE files); Qwen2.5-3B "FOR NON-COMMERCIAL PURPOSES ONLY",
  Qwen2.5-72B 100 M MAU clause, Qwen2.5-7B and Qwen3-8B `license: apache-2.0`,
  mistralai/Mistral-7B-Instruct-v0.3 `apache-2.0`, microsoft/phi-4 `license: mit`, google/gemma-3-4b-it
  gated with `license: gemma`, Gemma Terms of Use (last modified 1 April 2026) distribution clauses;
  Ollama library pages show sizes (llama3.1:8b 4.9 GB, qwen2.5:3b 1.9 GB, qwen2.5:7b 4.7 GB,
  gemma3:4b 3.4 GB, phi4:14b 9.1 GB) and link license terms for Gemma and Qwen; AI Act Art. 2(12),
  5(1)(f), 50, Annex III(4) via artificialintelligenceact.eu article pages; Commission AI policy page
  (updated 3 Aug 2026) for the Omnibus dates; Gibson Dunn summary 27 May 2026 for the Art. 50(2)
  grace period. Full table in `docs/compliance/third-party-licenses.md`.
- **Also verified 2026-10-10:** Llama 3.1/3.2/3.3 license text at
  github.com/meta-llama/llama-models (`models/llama3_x/LICENSE`, `USE_POLICY.md` for the EU
  multimodal clause) after the llama.com redirect chain ended in 404; Regulation (EU) 2026/1744 at
  EUR-Lex (title, 8 July 2026, OJ 24 July 2026, Annex III → 2 Dec 2027, Annex I → 2 Aug 2028,
  four-month Art. 50(2) transition); Apple notarization requirements page quotes and the DPLA section
  map (PDF version 20251008) — see inventory §5; Ollama per-tag license layers.
- **Not retrieved verbatim (marked, not asserted):** the consolidated post-Omnibus Art. 113 wording on
  EUR-Lex itself (read via artificialintelligenceact.eu and the Commission page); exact lettering of
  the DPLA §3.2 FOSS sub-clause; whether the Omnibus touched Art. 25 wording.
- **Legal / licensing input:** **none yet.** No lawyer has reviewed anything in this ADR. That is why
  the status is Proposed and why the Residual risks section is addressed to Andreas.
- **Measured prototype:** not applicable to this ADR; ADR-001 carries the signed-bundle prototype and
  ADR-004 the model download sizes.

## Alternatives considered

1. **Bundle a small MIT model (Phi-4-mini) in the DMG for an offline-capable first run.** Ruled out
   for v1: adds ~2–4 GB to the download the five-minute gate explicitly excludes (§0), makes STAFE
   the distributor of weights whose training-data provenance has not been reviewed (§10.9), and
   removes the runtime acceptance record that D4.2 relies on. Reopen when L-4 is signed.
2. **Pick AGPL/EUPL for the project to force downstream openness.** Rejected: MIT is a closed owner
   decision (ROADMAP), and copyleft would conflict with the Apache-2.0 patent terms of Caddy only in
   one direction but would deter the "extern utvecklare kan bygga från källkod" goal (§17) for
   consultancies that embed the tool.
3. **Ship the Tailscale OSS daemon ourselves to avoid a manual install step.** Rejected: the macOS
   network-extension GUI is proprietary, the OSS `tailscaled` on macOS needs root/utun handling the
   §12 installer forbids ("no admin password until … genuinely needs it"), and ADR-006 already
   treats Tailscale as an optional post-core step.
4. **Build PHP with gettext/libiconv and rely on the LGPL "relink" exception.** Rejected: LGPL §6
   relinking inside a notarized, sealed bundle is impractical and is exactly the kind of risk
   engineers may not accept; the extensions are not needed for a Laravel core on macOS.
5. **Write "GDPR compliant" with a footnote.** Rejected: §10 "Teamet får inte anta att produktens
   tekniska inställningar automatiskt gör kunders användning lagenlig"; the controller is the user.

## Consequences

Easier: ADR-001 has a closed list of admissible licenses and two excluded extensions; ADR-004 has a
model shortlist with verdicts and a defined acceptance flow; README/UI copy has a forbidden list that
a linter can enforce; the release gate has a mechanical license audit.

Harder: a first run with no Ollama/LM Studio and no API key is "AI setup required" until a download
completes (already mandated by §0); every new provider needs register fields R1–R5 filled by a human
before `EU_RESTRICTED` admits it; adding any C extension to the PHP build needs a license row first.

**Forbidden in code review from now on:**
- Any file matching `*.gguf`, `*.safetensors`, `*.bin`, `*.mlmodelc` in the repo, the DMG or the
  runtime manifest.
- Any dependency whose license is not on the D2.1 list, or any `gettext`/GNU `libiconv`/GNU
  `readline` in the static-php-cli build config.
- The strings in D5 "Forbidden phrasings" in UI copy, README, release notes, blog posts or the
  installer; "EU" badges derived from hostnames.
- Any outbound analytics, crash reporter or "ping" without a new ADR.
- Offering Qwen2.5-3B, any `*-Research-License` model or any `CC-BY-NC` model in a workspace not
  flagged personal/non-commercial.
- Hand-editing `THIRD-PARTY-NOTICES.txt`.

## Residual risks

Each item below needs a qualified legal reviewer, not an engineer. Andreas commissions and accepts
the review (`OPEN-QUESTIONS.md` §15.10 `ANDREAS`). Until signed, the risk sits with the Owner.

| # | Item | Why an engineer cannot close it |
|---|---|---|
| L-1 | Statically linked C libraries in the PHP binary: confirm the final `spc dump-license` output contains no LGPL/GPL; confirm the macOS system iconv route; confirm FreeType is taken under FTL and ImageMagick is omitted or credited | Static-linking analysis under LGPL §6 and dual-license election are legal judgements |
| L-2 | PHP-3.01 clauses 3, 4 and 6 as applied to "Laravel Executive AI" and to a bundled interpreter renamed/relocated inside `.app/Contents/` | Naming/endorsement clauses need interpretation |
| L-3 | Llama 3.x Community License and Gemma Terms of Use: confirm that *recommending and downloading at runtime with user acceptance* keeps STAFE outside "distribute or make available"; confirm the AUP/Prohibited Use pass-through wording the app shows | Licensor-vs-conduit distinction |
| L-4 | Apache-2.0 and MIT model weights (Qwen2.5 7B+, Qwen3, Mistral 7B, Phi-4): whether redistribution inside the DMG is acceptable given §10.9 training-data provenance and any trademark use ("Qwen", "Mistral", "Phi") | Copyrightability of weights and trademark use |
| L-5 | Apple Developer Program License Agreement: sections on Developer ID distribution, notarization (§5.3 per partial excerpt) and the warranty that distributed code infringes no third-party rights (§3.2 per partial excerpt) — confirm no additional constraint on redistributing open-source binaries in a Developer ID-signed app; confirm which Apple team signs (A12) | Contract interpretation; the agreement body was not retrievable by tooling |
| L-6 | GDPR Art. 28 processor wording in the register (R3/R4) and the data-export/erasure feature descriptions — confirm the "supports your obligations" phrasing | Controller/processor roles for a self-hosted tool with optional cloud providers |
| L-7 | Use of the "Laravel" word mark in the product name; Laravel's trademark policy applicability to an independent open-source product | Trademark |
| L-8 | EU AI Act: provider vs deployer role for an open-source self-hosted secretary; Art. 50(1)/(2)/(4) duties and the machine-readable marking method; Annex III(4) applicability to D7's "flag" mode; **verify the post-Omnibus consolidated Art. 113 dates in the Official Journal** | Regulatory classification with dates that changed in 2026 |
| L-9 | Commissioning the §10 "juridiskt granskad compliance mapping per roll och use case" itself — scope, reviewer, budget, date | Only the Owner can commission it |
| L-10 | Export-control / sanctions screening for model downloads and provider endpoints (some model licenses incorporate trade-law compliance) | Trade law |

Risks accepted now by engineering (H signs per §13): running the license audit from upstream-declared
license tags rather than human review of each of ~80 C-library license files until L-1 is done;
treating hostname-independent register fields as sufficient for `EU_RESTRICTED` while L-6 is open.

A13 (third-party penetration test) is a §11 gate owned in `docs/security/`; cross-referenced, not
duplicated. A11 (paid provider test accounts) is a procurement item that also needs DPAs — L-6 covers
the wording, Andreas the purchase.

## Blueprint references

§0, §2, §4, §6, §7, §10 (10.1–10.9), §11, §12.1, §13, §13.2, §14, §15 item 6 and 10, §17, §18.

## Reopen conditions

- A legal opinion (L-1…L-10) contradicts any verdict in D2 or D4 — update the row and this ADR.
- ADR-001 selects a PHP ≥ 8.6 build (PHP License 4.0 / BSD-3) — remove D2.4.
- `bin/spc dump-license` or the CI license diff reports a license outside D2.1.
- Meta, Google, Alibaba, Mistral or Microsoft change a model license (Gemma Terms state Google may
  update; register R6 hash changes) — re-run the D4 table.
- The Official Journal publishes a further amendment to Art. 113, or the Commission issues Art. 50
  marking guidelines/codes of practice — update D8 and D5.
- Caddy adds a `NOTICE` file; FrankenPHP or static-php-cli changes license.
- The project decides to bundle any model weights or inference runtime.
- Tailscale changes the licensing of its macOS CLI or its terms on automation.
