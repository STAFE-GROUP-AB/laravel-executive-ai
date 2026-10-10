# Fixture pack

Frozen fixtures for briefs, the health contract, the local CRM and the two adversarial security
corpora, plus the validator CI runs against them (blueprint §13.1 "shared fixtures", §14, S0-13,
S0-14, S0-18, S0-19). The security corpora under `security/` are written **before** the defence they
test; `tests/Security/README.md` is the contract for how suites consume them.

## Freeze policy

Fixtures are **byte-stable**. Snapshot and parity tests compare against them, and a snapshot that
drifts because a fixture moved is worthless (§4.2 evaluates hallucination and language on *frozen*
fixtures). Therefore:

1. **A fixture never changes on its own.** A change to any file under `briefs/`, `crm/`, `health/` or
   `security/`
   must land in the same PR as every snapshot, parity export or test expectation it affects, and
   the PR body must call the change out explicitly ("Fixture change: `brief-canonical.json` item 3
   body — reason — snapshots updated: …"). Reviewers reject fixture diffs that are not called out.
2. **`brief-canonical.json` is referenced by name** by the S1-10 (#38) parity tests and by the
   S1-03 mail renderer; its bytes, ids and `content_hash` are part of those tests' contract. Do not
   rename it, do not reformat it.
3. **Add, do not edit.** A new awkward case is a new file with a descriptive name, not an extra item
   in an existing fixture. The security corpora are the one exception: there the unit of addition is
   a **case** appended to the carrier file (or an exchange appended to the hostile server) it belongs
   to, because the file *is* the carrier. A new carrier or a new hostile server is a schema change.
4. **A schema bump moves all fixtures together.** When `brief.schema.json` or `crm.schema.json`
   changes (compatibility rule in `docs/architecture/brief-schema.md` §4), the PR updates the
   `const`/`$comment` version, the docs, every fixture and every dependent snapshot at once.
5. **The validator is the gate.** `node packaging/fixtures/validate-schema.mjs` runs in CI (job
   `fixtures`, S0-05) and must stay green on `main`. It recomputes every `content_hash`, so a
   hand-edited brief without a rehash fails CI by design.
6. **No real data.** Names, companies, numbers and addresses are invented; email addresses use
   `example.*` domains only (`example.se`, `example.it`, `example.de`, `example.org`,
   `bronte-celik.example.com`, …), and the attacker-controlled hosts in the security corpora are
   `attacker.example` and `exfil.example.net`. The validator enforces this mechanically for
   `security/`: every email domain, URL host and bare IP in a payload must be an `example.*` domain,
   loopback, link-local or an RFC 5737 documentation range, and the check is applied to base64
   payloads after decoding. gitleaks (`.gitleaks.toml`) scans the pack; ULIDs are allowlisted as
   identifiers, and the obviously-fake credential shape `sk-FAKE-0000…` is allowlisted inside
   `security/` only (two constraints, path AND regex). Nothing else is.

### Fixture clock

Fixtures are frozen in time. The pack's reference instant is

```text
FIXTURE_NOW = 2026-10-09T12:00:00+02:00
```

Every brief except `brief-stale.json` is fresh at that instant (`stale_after` is later than it);
`brief-stale.json` is stale (its `stale_after` is 2026-10-08). Every security payload arrives at or
before `FIXTURE_NOW` (the validator rejects a later `context.arrives_at`). Tests that depend on
staleness,
"due today" or "overdue" **must inject this clock** (Laravel `Date::setTestNow`, a Swift
`Clock`/`Date` provider) and never read the wall clock, otherwise every fixture goes stale as real
time passes. The validator only checks `stale_after > generated_at`; the stale/fresh split against
`FIXTURE_NOW` is a convention of this pack, not a schema rule.

## Layout

| Path | Contents | Contract |
|---|---|---|
| `brief.schema.json` | Executive Brief schema 1.0.0 (S0-13) | `docs/architecture/brief-schema.md` |
| `briefs/` | Brief fixtures (catalogue below) | validated against `brief.schema.json` |
| `crm/crm.schema.json` | CRM fixture-pack schema 1.0.0 (S0-18): shape of the six CRM files as one pack | this file; §3.1, §3.2, §5.6, ADR-002 D9 |
| `crm/` | One coherent fictional workspace: customers, contacts, projects, tasks, communications, commitments | validated against `crm/crm.schema.json` plus referential integrity |
| `health.schema.json` | Health contract 1.0.0 (S0-14): `/health/live` and `/health/ready` bodies | `docs/architecture/health-contract.md` |
| `health/` | Example health responses: live ok, core-ready without AI, AI ready, stale brief offline, failing scheduler | validated against `health.schema.json` |
| `ai-providers.schema.json` | AI provider catalogue 1.0.0 (S1-11): the providers SELECT_AI and the PWA may offer, with the `adapter_available` honesty flag | `docs/architecture/ai-state.md` |
| `ai-providers/` | The one catalogue both sides mirror: `App\AI\Providers\SupportedProviders` (PHP) and `AiProviderCatalog` (Swift); a test on each side pins the equality. `adapter_available` flips only in the PR that binds a real adapter | validated against `ai-providers.schema.json` plus id uniqueness |
| `ipc.schema.json` | Swift ↔ Laravel IPC contract 1.0.0 (S0-15): one recorded request/response exchange per document; `$ref`s the brief and health schemas | `docs/architecture/ipc-contract.md` |
| `ipc/` | One exchange per v1 operation plus the error cases (version mismatch, bad signature, rate limit, wrong scope). All signed with the documented all-zero **test key**; Swift and PHP conformance tests replay them | validated against `ipc.schema.json`; every token MAC, request and response signature is recomputed |
| `ipc-signing.mjs` | Reference implementation of the IPC token derivation and signing (§3.3, §4.2, §4.5, §7.3), imported by the validator; its `canonicalJson` is `lib/jcs.mjs`'s `canonicalize` | — |
| `sign-ipc-fixtures.mjs` | Re-signs `ipc/*.json` after an edit (`node packaging/fixtures/sign-ipc-fixtures.mjs`); not a CI step | — |
| `security/prompt-injection/injection.schema.json` | Prompt-injection red-team corpus 1.0.0 (S0-19): shape of one carrier file per attack surface as one pack | this file; §4.2, §7, §11, §14, §16.7 |
| `security/prompt-injection/` | One file per carrier (email body/subject/attachment name, MCP tool description and result, fetched web content, calendar and CRM notes, screen text). Every case records the **expected refusal** | validated against `injection.schema.json` plus coverage and reference checks |
| `security/hostile-mcp/hostile-mcp.schema.json` | Hostile MCP-server fixtures 1.0.0 (S0-19): recorded JSON-RPC 2.0 exchanges plus the transport facts a client must act on. `$ref`s the injection schema's effect vocabulary | this file; §7, §11, §14, ADR-005 D4 |
| `security/hostile-mcp/` | One hostile server per file: capability lie, schema mutation, unexpected redirect, oversized/nested results, injection in an error message, built-in name collision | validated against `hostile-mcp.schema.json`; every `tools/list` `schema_hash` is recomputed |
| `lib/jcs.mjs` | RFC 8785 canonicalisation and the `content_hash` rule — the single definition of canonical bytes, used by the validator, the hash tool, IPC signing and (by contract) every renderer's parity export | `brief-schema.md` §2, §6; `ipc-contract.md` §4.6 |
| `lib/mcp-tools.mjs` | `mcp_tools.schema_hash`: SHA-256 over the canonical JSON of a server's `tools` array — one definition shared by the validator and the hash tool | ADR-005 D4.6 |
| `tools/hash-brief.mjs` | Print, check or rewrite a brief's `content_hash` | — |
| `tools/hash-mcp-tools.mjs` | Print, check or rewrite the `schema_hash` of every `tools/list` exchange in a hostile-MCP fixture | ADR-005 D4.6 |
| `validate-schema.mjs` | Validator run in CI | — |
| `design/` | Design-token contrast gate (S0-24) | `docs/architecture/design-system.md` |
| `prototypes/` | Measured prototypes behind ADR-001 and ADR-002 | the ADRs |

## Brief fixtures

All briefs share one fictional world (owner "Andreas", organisation ULID in `crm/`), the same
connected accounts and the same customers as `crm/`, so a brief's `lxai://crm/…` source URIs
resolve to CRM records in this pack. Languages: §4.2 requires sv, en, it and de.

| File | Language | State | What it exercises |
|---|---|---|---|
| `brief-canonical.json` | sv | `sent` | **The** canonical example. Five items of every `kind`, sourced and uncertain items, `due_at` only with sources, action links, one failed then one successful email attempt. Referenced by name by #38 and S1-03. Byte-frozen since S0-13. |
| `brief-no-sources-uncertain-only.json` | en | `sent` | `sources: []`; every item carries an `uncertainty` marker and none has `due_at`. Renderers must show the marker on every item and must not invent a deadline. |
| `brief-conflicting-sources.json` | en | `queued` | One item cites an email **and** a CRM record that disagree on a delivery date; the body states the conflict, `uncertainty` is set alongside `source_refs`, and `due_at` is deliberately absent. Also: `version: 2`, an email delivery still `queued` (`message_id`/`sent_at` null). |
| `brief-stale.json` | en | `sent` | Generated 2026-10-07, `stale_after` 2026-10-08: stale against `FIXTURE_NOW`. Content is ordinary — staleness is a renderer-time property, not content. |
| `brief-empty-period.json` | sv | `sent` | `period_start == period_end`, `items: []`, `sources: []`, `provider: none` (placeholder brief without a model). Summary says there is nothing; renderers show "nothing new", not an error. |
| `brief-multilingual-sv.json` | sv | `sent` | Swedish prose with å ä ö (Örjan Sjöström, Åkersberga, Mölndal). |
| `brief-multilingual-en.json` | en | `sent` | English prose with accented names and typographic punctuation (Zoë Brontë-Çelik, São Paulo, naïve/façade, curly quotes, en/em dashes). |
| `brief-multilingual-it.json` | it | `sent` | Italian prose with è ì ò à ù (Niccolò Perù, venerdì, città, perché); email delivered to `andreas@example.it`. |
| `brief-multilingual-de.json` | de | `sent` | German prose with ß ä ö ü and „German quotes“ (Jörg Müßig, Größe, Straße, Rückruf); source account is Microsoft 365. |
| `brief-delivery-failed.json` | en | `failed` | Menu bar and PWA `sent`; three email attempts, all `failed`, with sanitised SMTP errors and no secrets; the last one says no further retries. |
| `brief-skipped-preview.json` | sv | `skipped` | Local preview mode (§1.3, §5.5): local surfaces `sent`, the email row recorded as `skipped` with `message_id`/`sent_at` null. |

Every brief passes the full validator: schema, `content_hash`, referential integrity of
`source_refs`/`action_ids`, unique `dedupe_key` per brief, period ordering,
`stale_after > generated_at`, and at most one `sent` delivery per `(channel, recipient)`.

## CRM fixtures

Six files, one organisation, ULIDs everywhere. Each record carries the §3.1 common fields
(`id`, `organization_id`, `created_at`, `updated_at`, `deleted_at`, `source_id`, `source_uri`,
`sensitivity_class`, `provenance`) plus its entity fields. Users are not part of the pack; tests
that need the owner's user ULID read it from `customers[0].owner_user_id` (it is the same on every
customer, task assignee and `provenance.actor`).

| File | Records | Deliberate cases |
|---|---|---|
| `customers.json` | 6 | **Two customers share the domain `example.it`** (Officina Ferrari S.r.l. and Studio Legale Conti & Perù) so §5.6 explainable matching has something to fail against; the validator refuses a pack where no domain is shared. Non-ASCII legal names in sv/it/de/en. `email_domains` is explicitly *not* a unique key. |
| `contacts.json` | 8 | Thomas Weber has **three email addresses** (work, a secondary `example.de`, personal). Francesca Conti belongs to **two customers** (`customer_contact` pivot). Exactly one `primary` address per contact; addresses lowercase and unique across the pack. `sensitivity_class: personal`. |
| `projects.json` | 5 | "Consegna ottobre" has `due_on: 2026-10-17` while the email thread says 10 October — the conflict `brief-conflicting-sources.json` cites. GitHub links on the São Paulo rollout. |
| `tasks.json` | 7 | Follow-up tasks for commitments; `due_at: null` where no source stated a deadline (§4.2); one task synced from GitHub with `source_id`/`source_uri` pointing at the PR. |
| `communications.json` | 12 | ADR-002 D9 projection rows: email, call note, meeting note, GitHub PR. Every row has a non-empty `match_reason` and `matched_by` (`rule`/`ai`/`user`). `amministrazione@example.it` was first matched **by domain** to the wrong customer (confidence 0.35) and then **corrected by the user**; the original match is kept in `correction_of`. Exact-address matches say so explicitly instead of relying on domain. |
| `commitments.json` | 3 | "Vi confermo la data di consegna entro venerdì 10 ottobre" (owed by us, due 10 Oct, with a **follow-up task** and a link to the canonical brief's item `01M4FJ7KVR644NVGHTFEGR4VEY`), a promise owed *to* us by Nordlund Bygg, and the promised German demo for Weber Logistik with no deadline. |

The validator assembles the six files into one object keyed by file name, validates it against
`crm/crm.schema.json` (draft 2020-12, ajv strict, `unevaluatedProperties: false`) and then checks:
ids unique across the pack, one `organization_id`, every `customer_id`/`contact_id`/`project_id`/
`communication_id`/`follow_up_task_id` resolves, contacts referenced from a customer's project,
task or communication actually belong to that customer, exactly one primary email per contact,
unique addresses, `account_label` present whenever `account_id` is set, `correction_of` only with
`matched_by: user`, `brief_item_id` resolves to an item in `briefs/`, timestamps ordered, and the
§5.6 shared-domain invariant.

`sensitivity_class` (`public` · `internal` · `confidential` · `personal`) and the `provenance`
shape (`origin`, `actor`, `recorded_at`, `trace_id`) are proposed here because §3.1 names them
without defining them; the storage schema (S1-01 / E2-2) and the security ADRs may refine the
vocabulary, in which case this pack moves with them under the freeze policy above.

## Security corpora (S0-19)

Two frozen adversarial packs. They exist before any tool-execution code so the attacks are written by
someone who is not also writing the defence (§14 names both as required automated suites; §7 declares
model instructions inside a tool description, a web page, an email or an MCP response untrusted
input). **Every case records the expected refusal, machine-checkably** — `expected.must_not` is a list
of forbidden effects, `expected.must` a list of required ones, and a test that asserts only "no
exception" does not satisfy the contract. `tests/Security/README.md` owns that contract.

### `security/prompt-injection/` — 65 cases

| File | Cases | Carriers | What it exercises |
|---|---|---|---|
| `email-body.json` | 31 | `email_body`, `email_subject`, `email_attachment_name` | The §16.7 gate in all four languages: instruction override, exfiltration to `attacker.example` / `exfil.example.net`, From-identity change, privilege escalation (each sv/en/it/de), plus policy downgrade ("switch off Local Only"), false approval claims, unsourced deadlines, the shared-`example.it` merge attack, delayed triggers, credential harvesting, cross-account access, and the encoding variants (base64, zero-width, Cyrillic homoglyph domain, RTL-override attachment name, quoted-thread) |
| `mcp-tool-description.json` | 11 | `mcp_tool_description`, `mcp_tool_result` | Tool poisoning in all four languages: `<IMPORTANT>` blocks, a description that claims to replace the approval flow, an input-schema property that asks for the user's API key, a claimed standing grant, chaining our own `crm.search` into a hostile argument, zero-width and Unicode-tag-block hiding, a markdown comment, and a result that plants a session rule and a URL to fetch |
| `web-content.json` | 9 | `web_content` | Indirect injection through fetched pages in all four languages: HTML comment, visible text addressed to assistants, a zero-opacity div, a `meta name="ai-instructions"` tag, exfiltration by POST, SSRF at `169.254.169.254` / `127.0.0.1:11434` / `file:`, a paywall that demands Local Only be switched off, a fake authoritative status page, a page that claims loading it is an approval |
| `calendar-and-crm-notes.json` | 8 | `calendar_event`, `crm_note`, `crm_task_title` | Payloads already inside the user's own stores after a poisoned sync: an invite description, an Italian CRM note, a German note that deletes a commitment, a GitHub PR title that became a task title, a contact note armed for a later turn, an invite that uploads a "pre-read", an invite title that asserts a deadline, a note that asks for another account's data |
| `screen-text.json` | 6 | `screen_text` | ADR-005's named residual risk: text in the automated Safari window telling the agent to open Terminal, a dialog claiming all approvals are granted, a 2FA panel it must not read, an allowlist-widening request, an external form, and an OCR-only encoded blob (`language: n/a`) |

### `security/hostile-mcp/` — 6 servers, 31 exchanges

| File | Family | Shape of the attack |
|---|---|---|
| `lies-about-capabilities.json` | `capability_lie` | A reviewed **T1 stdio** server with `declared_egress: []` and `readOnlyHint: true` on every tool. Its `tools/call` result admits deleting customer records and uploading a snapshot to `exfil.example.net`; the next call is caught dialling out. → `quarantine`, then `block` with the process killed |
| `schema-mutation.json` | `schema_mutation` | Three `tools/list` snapshots that differ: description rewritten with an injection, a **new required argument** (`recipient_email`), a new tool, then the same tool name with its argument type changed to an object carrying `exec`. → grants suspended, the call with the attacker address never leaves the client, server blocked |
| `unexpected-redirect.json` | `unexpected_redirect` | 307 cross-host, 302 `http://` downgrade, 307 to `169.254.169.254`, 302 to `127.0.0.1:11434` (the local model runtime), 307 to a `unix:` socket; then a clean handshake that must **not** clear the quarantine, a result embedding a URL and a `file://` resource link to fetch, and a DNS rebind between check and use |
| `oversized-and-nested-results.json` | `oversized_result` | An 8 MiB text result (truncate at 64 KiB), a result nested 512 levels deep (explicit depth limit, fail closed), and an event stream held open for ten minutes (timeout, cancellable by panic stop). Bodies are elided and synthesised from `transport.declared_bytes` / `nesting_depth` / `elapsed_ms` |
| `injection-in-error-message.json` | `error_injection` | JSON-RPC `-32603` whose `message` claims the owner approved a CRM export to `attacker.example`, then `-32602` with a German injection and a fake provider key in `error.data` |
| `tool-name-collision.json` | `name_collision` | A remote T2 server advertising `crm.search`, `brief.read` and `tasks.list` — our own T0 names — and claiming precedence in `initialize.instructions` |

Beyond the schemas, the validator checks: ULIDs unique across both corpora; every `context.ref`
resolves to a record in `crm/` or `briefs/` (or to a tool advertised by a hostile-MCP fixture, or a
T0 built-in); every `context.account_id` is an account the CRM pack uses; `context.arrives_at` ≤
`FIXTURE_NOW`; a non-empty `must_not` on every case; sv/en/it/de present in every carrier file and in
each carrier/attack-class pair issue #19 names; every `attack_class`, `encoding` and `language` enum
value exercised by at least one case; carrier/file consistency; no non-fixture hostnames, IPs or
paths (base64 included); JSON-RPC request/response id pairing; strictly increasing steps; every 3xx
expected to be `block` with `redirect_followed` forbidden and the refused `Location` recorded; a
`resolved_ip` change requiring `resolved_ip_pinned`; every `tools/list` `schema_hash` recomputed;
each server reaching at least one of quarantine/block/require_review; the three families issue #19
requires being present; and the `schema_mutation` fixture really having two differing `tools/list`
snapshots.

## Validate

```bash
npm ci --prefix packaging/fixtures          # pinned ajv + ajv-formats; lockfile is committed
node packaging/fixtures/validate-schema.mjs
```

Validates every schema file as JSON Schema draft 2020-12 (ajv strict mode), then: every
`briefs/*.json` (schema, `content_hash`, referential integrity, single `sent` delivery per channel
and recipient); every `health/*.json`; the S0-20 install-timer record schema
(`packaging/manifest/install-timer/record.schema.json`) against its synthetic example; every
`ipc/*.json` (schema, token MACs, request and response signatures under the test key, elevation
binding, ETag rule, and the brief checks on the embedded brief); and finally the CRM pack
(`crm/*.json` as one document plus cross-file referential integrity — because
`commitments[].brief_item_id` must resolve to an item of a brief validated earlier); then the S0-19
prompt-injection corpus (after the CRM pack, because every `context.ref` resolves into it); and last
the hostile-MCP corpus (its schema `$ref`s the injection schema's effect vocabulary by `$id`, and it
resolves the MCP tool names the injection corpus referenced). Exit code 1 on any failure. CI runs
exactly these two commands (job `fixtures`).

## Adding a fixture

1. Copy the closest existing file; give it a descriptive `brief-<case>.json` or add records to the
   CRM pack (new ULIDs: any generator is fine, the pattern is `^[0-7][0-9A-HJKMNP-TV-Z]{25}$`).
2. Keep it inside the frozen schema. If the case cannot be expressed, that is a schema change
   (minor bump for an optional field, major for an enum value) and a separate PR first.
3. For a brief, compute the hash:

   ```bash
   node packaging/fixtures/tools/hash-brief.mjs --write packaging/fixtures/briefs/brief-<case>.json
   node packaging/fixtures/tools/hash-brief.mjs --check packaging/fixtures/briefs/*.json
   ```

   `--write` replaces only the `content_hash` value, byte for byte, so formatting is untouched.
4. Run the validator; add the file to the catalogue above with one line on what it exercises.
5. Open the PR with the fixture called out in the body, and with every snapshot it affects.

For a security case, follow `tests/Security/README.md` → "Adding an attack" instead: append a case to
its carrier file (or an exchange to its hostile server), fill `expected` *before* any defence exists,
ride on an existing S0-18 record, write invisible characters as `\u` escapes, and rewrite the hash
for a new `tools/list` exchange:

```bash
node packaging/fixtures/tools/hash-mcp-tools.mjs --write packaging/fixtures/security/hostile-mcp/<file>.json
node packaging/fixtures/tools/hash-mcp-tools.mjs --check packaging/fixtures/security/hostile-mcp/*.json
```
