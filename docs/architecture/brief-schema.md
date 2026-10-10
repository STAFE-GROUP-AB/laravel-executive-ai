# Executive Brief schema (S0-13)

**Status:** Frozen at `schema_version` **1.0.0** · **Owner:** Workstream B (Laravel platform)
**Schema file:** [`packaging/fixtures/brief.schema.json`](../../packaging/fixtures/brief.schema.json) (JSON Schema draft 2020-12)
**Canonical fixture:** [`packaging/fixtures/briefs/brief-canonical.json`](../../packaging/fixtures/briefs/brief-canonical.json)
**Validation:** `node packaging/fixtures/validate-schema.mjs` (CI step, see S0-05)

Blueprint §1.3 says a brief is assembled and frozen transactionally **once**, then rendered to the
*same semantic data* in SwiftUI, HTML mail and the PWA, with content divergence forbidden. That is
only enforceable if all three renderers read one versioned document. This page is that document's
contract: what every field means, why it exists, how it may change, and what "frozen" means.

## 1. Document shape

```text
Brief
├── schema_version        "1.0.0"                      contract version (this file)
├── id                    ULID                         identity across versions
├── version               int ≥ 1                      monotonic per id
├── generated_at          RFC 3339
├── period_start/end      RFC 3339                     [start, end)
├── language              BCP-47                       sv | en | it | de | …
├── subject               string                       mail subject, menu headline
├── summary               string
├── items[]               Item                         ordered, order is semantic
├── sources[]             Source                       every referenced source, with account
├── action_ids[]          ULID                         links to action proposals (§11)
├── model_provenance      ModelProvenance
├── content_hash          sha256:…                     JCS hash of the content envelope
├── delivery_state        draft|frozen|queued|sent|failed|skipped
├── idempotency_key       ULID                         assigned at freeze
├── deliveries[]          Delivery                     append-only attempt log
└── stale_after           RFC 3339
```

`additionalProperties: false` applies at every level. A producer that needs a new field bumps the
schema (section 4); it does not smuggle data through an unknown key.

## 2. Field-by-field rationale

### Identity and time

| Field | Type | Why |
|---|---|---|
| `schema_version` | semver string, `const` per schema release | Renderers compare it with the maximum they support (section 4). Kept separate from `version` because the two change for unrelated reasons. |
| `id` | ULID | §1.3 `id`. ULID per §3.1 (every business object has UUID/ULID). Stable across regenerations so `/briefs` can show version history (§1.2). |
| `version` | integer ≥ 1 | §1.3 `version`. Monotonic per `id`. A brief is never edited after freeze; a regeneration is `version + 1` (section 5). §3.1 asks for unique indexes on version numbers: `(id, version)` is unique. |
| `generated_at` | RFC 3339 with offset | §1.3. The menu bar shows it next to the headline (§1.1 "senaste briefens datum/genereringstid"). |
| `period_start`, `period_end` | RFC 3339 | §1.3 `period_start/end`. Half-open interval. An empty period (`start == end`) is representable because §13.1's fixture pack must cover "an empty period" (S0-18). |
| `stale_after` | RFC 3339 > `generated_at` | §1.3. Drives the **stale brief** state in §1.1 and `brief_state` in the health contract (S0-14). The producer decides it from the brief schedule (§15.9), renderers only compare it with now. |

### Content

| Field | Type | Why |
|---|---|---|
| `language` | BCP-47 | §4.2 requires evaluation in Swedish, English, Italian and German. One tag for the brief's own prose; quoted excerpts inside `items[].body` may be in any language. |
| `subject` | single-line string ≤ 200 | §1.3. Doubles as the mail `Subject:` and the menu bar headline, so it may not contain line breaks. |
| `summary` | string ≤ 2000 | §1.3. Renderers must not truncate it (section 6). |
| `items[]` | ordered `Item` | §1.3. The order is part of the semantics (it *is* the prioritisation §0 item 7 says must be identical everywhere); renderers preserve it. |

### `Item`

| Field | Type | Why |
|---|---|---|
| `id` | ULID | Stable id so approvals, snapshots and the `brief_items` table (§3.1) can point at an item. |
| `kind` | `action` · `followup` · `risk` · `info` · `commitment` | What the owner is meant to do with it. `commitment` maps to §3.1 `commitments` (promises extracted from communication); `action` is something the owner should do; `followup` is an expected or promised follow-up; `risk` and `info` carry no implied action. |
| `title`, `body` | strings | Plain text. HTML mail and SwiftUI both render *from* plain text; the brief never contains markup, which also keeps prompt-injected HTML out of the mail (§11). |
| `priority` | `critical` · `high` · `normal` · `low` | §0 item 7: "exakt samma … prioriteringar". Priority is data, not styling, so every renderer shows the same one. |
| `due_at` | RFC 3339, optional | §4.2 "inga påhittade deadlines": the schema only allows `due_at` on an item that has `source_refs` (`if/then`). An unsourced deadline is invalid, not merely discouraged. |
| `source_refs[]` | ULIDs into `sources[]`, ≥ 1 | §4.2 "alla väsentliga påståenden … källreferenser". |
| `uncertainty` | `{reason, confidence}` | §4.2 "… eller explicit osäkerhetsmarkering". `confidence` is model-reported and informational; it never upgrades an unsourced claim to a sourced one. |
| `action_ids[]` | subset of top-level `action_ids` | Lets a renderer show "Approve" next to the item that produced the proposal (§1.1 "uppgifter som kräver godkännande"). |
| `dedupe_key` | string, optional | §4.2 "deduplicera samma ärende mellan mejl, CRM och GitHub". A stable key for the underlying matter; the producer must not emit two items with the same key. |

**At least one of `source_refs` or `uncertainty` is required** (`anyOf`). This is the schema-level
encoding of §4.2's rule; a brief that contains a bare claim does not validate. Both may be present
(a partly sourced claim with a stated gap).

### `Source`

| Field | Type | Why |
|---|---|---|
| `id` | ULID | Referenced from items. |
| `type` | `email` · `crm` · `github` · `calendar` · `manual` · `ai_inference` | The system of origin. `ai_inference` exists so a derived fact can still be cited honestly (a source that is itself a model step, with its own trace). |
| `uri` | URI | Locator of the original record, resolvable inside the product (`lxai://…` for local objects, `https://github.com/…` for GitHub). §3.1 `source_id/source_uri`; §5.6 "källhänvisning till original". Never carries credentials. |
| `account_id` | ULID or null | §5.6: the daily brief "ska visa källkonto för varje åtgärd". Required (non-null) for `email`, `github` and `calendar`, which always come from a connected account. Null for `crm` (the local system of record, §0 item 6), `manual` and `ai_inference`. |
| `account_label` | string | Display form of the account (mailbox address, GitHub org). Required whenever `account_id` is set so renderers can show the account without a lookup. Never a secret. |
| `title` | string, optional | Mail subject, issue title, CRM object name. |
| `captured_at` | RFC 3339 | When the record was read for this brief. |
| `excerpt_hash` | `sha256:` hex | Hash of the exact excerpt used. A reviewer can detect that the source changed after the freeze, and the parity test can assert that no renderer re-fetched a source. |

### `action_ids[]`

ULIDs of **action proposals** (§11: "frozen immutable proposal, input/recipient/hash, approver
identity, expiry, nonce, idempotency"). The brief links to proposals; it never embeds them. The
proposal is the object the approval module freezes and the PWA `/approvals` page lists (§1.2).

### `ModelProvenance`

| Field | Type | Why |
|---|---|---|
| `provider` | lowercase identifier | Which adapter produced the brief (§4 "output provenance"). Free-form by design: §1.1 forbids a preselected brand and the adapter list will grow; a new provider must not require a schema bump. `none` means no model was involved (placeholder brief). |
| `model` | string | Model identifier as the adapter reports it. |
| `privacy_mode` | `LOCAL_ONLY` · `EU_RESTRICTED` · `CUSTOM` | §4 and §10: the mode the brief was produced under is part of its provenance and is shown in the PWA. |
| `prompt_hash` | `sha256:` hex | §4 "replay med syntetiska testdata": hash of the redacted prompt so a run can be matched against fixtures without storing the prompt in the brief. |
| `trace_id` | string | Points at the `ai_runs` record (§3.1), where cost, tokens and tool calls live. |

### Freeze and delivery

| Field | Type | Why |
|---|---|---|
| `content_hash` | `sha256:` hex | SHA-256 over the RFC 8785 (JSON Canonicalization Scheme) form of the **content envelope**: every top-level field except `delivery_state`, `deliveries` and `content_hash` itself. Computed at freeze. Renderers and the parity test (S1-10, #38) use it to prove they rendered the same frozen content. `validate-schema.mjs` recomputes it for every fixture. |
| `delivery_state` | enum, ordered | §1.3 `delivery_state`. `draft < frozen < queued < sent | failed | skipped`. `skipped` is the local preview mode of §1.3 ("lokalt preview-läge utan utgående mejl") and the case where the owner has not yet consented to delivery (§5.5). |
| `idempotency_key` | ULID | Assigned once at freeze. Every delivery attempt of this version carries it. |
| `deliveries[]` | `Delivery` | Append-only log: `channel` (`menubar` · `pwa` · `email`), `recipient`, `attempt`, `status`, `message_id`, `sent_at`, optional sanitised `error`. |

**Idempotent delivery (§1.3, §5.5 "delivery IDs och retry guards").** Double delivery is both
representable and detectable:

1. The mail `Message-ID` is derived deterministically from `idempotency_key`, channel and
   recipient, so even a retry that reaches the provider twice is deduplicated downstream.
2. Before sending, the delivery job looks for an existing `deliveries[]` entry with the same
   `(channel, recipient)` and `status: sent`. If one exists the job records `skipped`, never a
   second `sent`.
3. The storage layer (`brief_deliveries`, §3.1; `email_delivery_attempts`, §5.6) enforces a unique
   index on `(idempotency_key, channel, recipient, attempt)` and a partial unique index on
   `(idempotency_key, channel, recipient) WHERE status = 'sent'`.
4. The validator rejects any fixture with two `sent` entries for one `(channel, recipient)`, so the
   fixture pack can never teach a renderer that double delivery is normal.

The canonical fixture shows the intended shape: the first email attempt failed with a sanitised SMTP
error, the second succeeded, and there is exactly one `sent` per channel.

## 3. Content envelope versus delivery bookkeeping

Two kinds of data live in one document and change at different times:

- The **content envelope** is everything a reader sees: identity, period, language, subject,
  summary, items, sources, action ids, provenance, `stale_after`, plus `idempotency_key`. It is
  written exactly once, inside the freeze transaction, and hashed into `content_hash`.
- **Delivery bookkeeping** (`delivery_state`, `deliveries[]`) is appended to afterwards by the
  delivery jobs.

This split is why `content_hash` excludes the delivery fields and why the freeze rule below can be
stated without exceptions.

## 4. Compatibility rule

`schema_version` is semver, and the schema file is released as a whole:

| Change | Bump | Example |
|---|---|---|
| Add an **optional** field or an optional `$defs` member | **minor** (1.0.0 → 1.1.0) | adding `items[].owner_note` |
| Remove, rename or retype a field; make an optional field required; **remove or add an enum value** | **major** (1.x → 2.0.0) | adding `kind: decision` |
| Clarify a description, tighten a pattern that all shipped producers already satisfy | **patch** | |

Adding an enum value is a major bump because every renderer branches on the enum (a SwiftUI
`switch`, a Blade `@switch`) and an unknown value would silently render wrong. `model_provenance.provider`
is deliberately a free-form identifier rather than an enum for exactly this reason.

**Renderer obligations.** All three renderers (SwiftUI menu bar, Blade HTML mail, PWA) **declare the
maximum `schema_version` they support** as a constant in their code, and:

- refuse to render a document whose major is higher than theirs and show the explicit "update
  required" state instead (never a partial brief);
- render a document with a higher *minor* and ignore fields they do not know (decoders must be
  lenient on unknown keys; `additionalProperties: false` is a producer-side and fixture-side rule,
  enforced by the validator, not a reason for a reader to crash);
- render every lower version in the same major.

The `const` on `schema_version` in the schema file means a fixture always validates against the
exact schema release it was written for. Bumping the schema is a PR that updates the `const`, this
page, and the fixtures together (S0-18 owns the fixture freeze policy).

## 5. Freeze rule

A brief whose `delivery_state` is `frozen` or later is **immutable**:

- No field of the content envelope may change. `content_hash` is the proof; a renderer or test that
  recomputes it and gets a different value has found a bug, not a feature.
- `delivery_state` may only move forward along `frozen → queued → sent | failed | skipped`, and
  `deliveries[]` may only grow.
- Corrections, late data, or a new generation produce a **new `version`** under the same `id` with
  a new `idempotency_key`. The previous version stays readable in `/briefs` (§1.2) and remains the
  version that was emailed.

The freeze itself is one database transaction (§1.3 "sammanställs och fryses transaktionellt en
gång"): write the brief row, its items, its sources and the proposals' links, compute `content_hash`,
set `delivery_state: frozen` and `idempotency_key`, commit. Delivery jobs are dispatched only after
commit, so no channel can ever observe a half-written brief.

A `draft` brief is the only mutable state and never leaves the generator; a `draft` may not have
deliveries (the validator checks this).

## 6. Identical rendering across surfaces

§1.3 forbids content differences between SwiftUI and HTML mail; §16.3 and S1-10 (#38) prove it with
snapshot tests. The contract every renderer signs up to:

**Must be identical (semantic projection).** `subject`, `summary`, `generated_at`, `version`,
`period_start/end`, `language`, and for every item **in order**: `kind`, `title`, `body` (whole, not
truncated), `priority`, `due_at` if present, the `uncertainty` marker if present (both `reason` and
a visible indication of uncertainty), and for each `source_ref` the source's `type`,
`account_label` (§5.6) and `title`/`uri`. Every `action_ids` entry is reachable as an approval
affordance. The `model_provenance.privacy_mode` is shown.

**May differ (presentation).** Typography, colour, iconography, grouping into visual sections,
collapsing long bodies behind a disclosure *that still contains the full text*, link styling,
whether the `uri` is shown as text or as a link, date formatting according to `language`.

**Must never happen.** Dropping, reordering, merging or adding items; truncating `summary` or
`body`; hiding the uncertainty marker; omitting the source account; rendering a different
`version` in two surfaces at the same time; re-fetching a source to "freshen" an excerpt (the
`excerpt_hash` is for exactly that).

**How #38 tests it.** Each renderer exports its semantic projection of the canonical fixture as
canonical JSON (same JCS canonicalisation `validate-schema.mjs` uses). The three exports must be
byte-identical to each other and the rendered `content_hash` must equal the fixture's. Snapshot
tests then guard each surface's *presentation* separately.

## 7. Examples

### An item with sources (from the canonical fixture)

```json
{
  "id": "01M4FJ7KVR644NVGHTFEGR4VEY",
  "kind": "commitment",
  "title": "Du har lovat Officina Ferrari leveransbesked senast fredag",
  "body": "I ditt svar till Giulia Ferrari i går skrev du: \"Vi confermo la data di consegna entro venerdì 10 ottobre.\" Giulia svarade: \"Perfetto, grazie Andreas. Aspettiamo la conferma per organizzare il ritiro a Milano.\" Inget leveransbesked är skickat ännu.",
  "priority": "high",
  "due_at": "2026-10-10T23:59:59+02:00",
  "source_refs": ["01M4FJ7SQ88YMXHC57TMV9Z6SV"],
  "action_ids": ["01M4FJ7XM84RV70ZSNAMPQ03HX"],
  "dedupe_key": "email:thread:officina-ferrari:consegna-ottobre"
}
```

and its source, which names the connected account the renderer must show:

```json
{
  "id": "01M4FJ7SQ88YMXHC57TMV9Z6SV",
  "type": "email",
  "uri": "lxai://email/threads/01M4FJ7SQ88YMXHC57TMV9Z6SV",
  "account_id": "01M4FJ7YKGT1R7QHBVEY0MNKEC",
  "account_label": "andreas@example.it (iCloud Mail)",
  "title": "Re: Consegna ottobre — Officina Ferrari S.r.l.",
  "captured_at": "2026-10-09T05:29:45+02:00",
  "excerpt_hash": "sha256:1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f809"
}
```

The `due_at` is allowed because the deadline ("entro venerdì 10 ottobre") is in the referenced
source.

### An item with an uncertainty marker

```json
{
  "id": "01M4FJ7NT86MJ76MGPQV413FP9",
  "kind": "info",
  "title": "Möjlig förskjutning av Q4-releasen",
  "body": "Kombinationen av den röda CI-pipelinen och två öppna säkerhetsgranskningar kan innebära att Q4-releasen inte kan tas den 13 oktober som planerat. Inget beslut eller datum är dokumenterat i någon källa; detta är en bedömning, inte ett faktum.",
  "priority": "low",
  "uncertainty": {
    "reason": "Slutsats dragen från CI-status och granskningskö; ingen källa anger ett nytt releasedatum.",
    "confidence": 0.4
  }
}
```

No `source_refs`, therefore no `due_at` is permitted, and every renderer must show the marker.

### Invalid: a bare claim

```json
{ "id": "01M4FJ7NT86MJ76MGPQV413FP9", "kind": "action", "title": "Ring kunden", "body": "…", "priority": "high" }
```

Fails `anyOf`: neither `source_refs` nor `uncertainty`. This is §4.2 as a validation error.

## 8. Validation and CI

```bash
cd packaging/fixtures && npm ci      # pinned ajv + ajv-formats, lockfile committed
node packaging/fixtures/validate-schema.mjs
```

The script validates both schema files as draft 2020-12 schemas in ajv strict mode, then validates
every `packaging/fixtures/briefs/*.json` against `brief.schema.json` (plus `content_hash`,
referential integrity of `source_refs`/`action_ids`, period ordering, `stale_after > generated_at`,
and the single-`sent`-per-target rule) and every `packaging/fixtures/health/*.json` against
`health.schema.json`. Exit code 1 on any failure. S0-05 runs it as a CI step:

```yaml
- run: node packaging/fixtures/validate-schema.mjs
```

(after `npm ci --prefix packaging/fixtures`).

## 9. What this document does not decide

- The brief **schedule, timezone and send-consent flow** (§15.9) — an ADR owned by Workstream B.
- The **fixture pack and its freeze policy** — S0-18 (#18) extends `packaging/fixtures/briefs/` with
  the awkward cases (no source, conflicting sources, stale, empty period, all four languages).
- The **storage schema** (`briefs`, `brief_items`, `brief_deliveries`) — S1-01, which must round-trip
  this document without loss.
- The **parity test harness** — S1-10 (#38), which consumes section 6.
