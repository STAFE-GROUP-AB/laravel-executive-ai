# Brief parity across surfaces (S1-03 skeleton, finished by S1-10)

**Status:** Skeleton — payload shape frozen by S1-03 (#31); the tri-surface harness lands with
S1-10 (#38) · **Owner:** Workstream I (QA/release) with B (Laravel) and A (macOS)
**Binding references:** blueprint §1.3 ("samma semantiska data … innehållsskillnader förbjuds"),
§16 step 3, §17; [`brief-schema.md`](brief-schema.md) §6 (what must be identical, what may differ)

The same frozen brief document is rendered three times — SwiftUI menu bar (S1-09), Blade HTML mail
(S1-03) and the PWA (S1-04). §1.3 forbids content differences; this page defines the **one semantic
projection** every renderer must derive its output from and export for comparison, so that a layout
change cannot break the gate and a content change cannot pass it (S1-10 acceptance).

## 1. The parity payload

Every renderer projects the brief document to the structure below, exports it as **RFC 8785 (JCS)
canonical JSON** (`packaging/fixtures/lib/jcs.mjs`, `App\Support\Json\Jcs`), and the three exports
must be **byte-identical**. The reference implementation is
[`App\Briefs\Render\BriefParityPayload::fromDocument()`](../../app/Briefs/Render/BriefParityPayload.php);
the committed export of the canonical fixture is
[`tests/__snapshots__/brief-canonical.parity.json`](../../tests/__snapshots__/brief-canonical.parity.json).

```text
ParityPayload
├── schema_version   string      document schema_version
├── id               string      brief id (identity across versions)
├── version          int
├── generated_at     string      RFC 3339 exactly as in the document (not reformatted)
├── period_start     string      RFC 3339 as in the document
├── period_end       string      RFC 3339 as in the document
├── language         string      BCP-47 as in the document
├── subject          string
├── summary          string      whole, never truncated
├── privacy_mode     string      model_provenance.privacy_mode (LOCAL_ONLY | EU_RESTRICTED | CUSTOM)
├── content_hash     string      document content_hash — must equal the fixture's
└── items[]          Item        document order, every item, nothing merged or dropped
    ├── id           string
    ├── kind         string      action | followup | risk | info | commitment
    ├── priority     string      critical | high | normal | low
    ├── title        string
    ├── body         string      whole, never truncated
    ├── due_at       string|null RFC 3339 as in the document; null when absent (§4.2)
    ├── source_refs[] SourceRef  in the item's source_refs order; [] when none
    │   ├── id            string      source id
    │   ├── type          string      email | crm | github | calendar | manual | ai_inference
    │   ├── account_label string|null §5.6: the connected account, null for crm/manual/ai_inference
    │   ├── title         string|null
    │   └── uri           string
    ├── uncertainty  {reason: string, confidence: number}|null
    └── action_ids[] string     [] when none; every entry is an approval affordance
```

Rules that make the bytes comparable:

- **Fixed shape.** Every key is always present. Absent optionals are `null`, absent lists are `[]`.
  No renderer adds a key (S1-10: "adding a field to one surface and not the others fails").
- **Order is data.** `items[]` and `source_refs[]` keep the document order. Renderers that group
  visually (by kind, by priority) still export document order.
- **Raw timestamps.** Dates are exported as the document's RFC 3339 text. Locale formatting
  (Carbon `isoFormat('LLLL')` in Blade, `Date.FormatStyle` in SwiftUI) is presentation.
- **Resolved sources.** `source_refs` carries the source's `type`, `account_label`, `title` and `uri`
  (brief-schema §6), not the bare id, so an export proves the renderer *had* the account label.
- **Numbers as JSON.** `confidence` keeps its JSON number form (`0.4`, not `"0.4"` or `40`).
- **Payload-driven rendering, not payload-extracted.** The Blade renderer builds the payload first
  and renders *from* it (`BriefViewModel` receives only the payload), so the export cannot drift from
  what the mail shows. S1-04 and S1-09 should do the same rather than scrape their own output.

Not in the payload (presentation or bookkeeping): `stale_after` (the stale *state* is a renderer-time
property), `deliveries[]`, `delivery_state`, `model_provenance.provider/model`, `dedupe_key`,
`excerpt_hash`, `captured_at`, formatted dates, labels, badges, colours.

## 2. Rendering obligations each surface signs up to

From brief-schema §6, restated per surface:

| Obligation | Mail (S1-03) | PWA (S1-04) | Menu bar (S1-09) |
|---|---|---|---|
| Builds the payload from the document, renders from the payload | `BriefMailRenderer` → `BriefParityPayload` → `BriefViewModel` → Blade | `BriefPage::fromBrief()` → `BriefParityPayload` → the same `BriefViewModel::build()` → `briefs/show.blade.php` | Swift port of `fromDocument()` |
| Shows every item, in order, title + whole body | done | done (`<li data-brief-item data-position>`) | |
| `due_at` only when present | done | done (`<time data-due-at>`) | |
| Source refs (type · account · title · uri) **or** uncertainty marker (reason + confidence); both when both | done | done (`data-source-ref`, `data-uncertainty`) | |
| `privacy_mode` visible | footer | header `<dd data-privacy-mode>` | |
| Every `action_ids` entry reachable as an approval affordance | link per action id | badge per action id (`data-action-id`); links once `/approvals` exists | |
| Exports the payload as JCS | `RenderedBrief::parityJson()`; `lea:brief:preview` writes `*.parity.json` | `GET /briefs/{brief}/parity.json` (`BriefPage::parityJson()`); `data-parity-hash` on the article | |
| Declares the max `schema_version`; refuses a higher major | `BriefMailRenderer::SUPPORTED_SCHEMA_VERSION` | inherits the mapper's `Brief::SCHEMA_VERSION` gate at import | |

## 3. The S1-10 test (to be written)

- One run, one fixture (`brief-canonical.json`): render all three surfaces, export three payloads,
  assert byte-identical JCS and `content_hash` equal to the fixture's.
- Deliberately break it once (add a key on one surface) and record that it failed.
- `version` and `generated_at` asserted identical across all three.
- Runs in CI on every PR touching `app/Briefs/Render/`, `resources/views/briefs/`,
  `resources/views/mail/` or `apps/macos/Sources/MenuBar/`.
- Presentation is guarded separately per surface: mail by
  `tests/Feature/Briefs/Mail/BriefMailSnapshotTest.php` (`tests/__snapshots__/brief-canonical.{html,txt}`).

Open for S1-10: where the Swift export is produced (a `swift test` that writes JSON to a path the
PHP test reads, or a committed Swift snapshot regenerated in CI). The PWA export is the JSON endpoint
`GET /briefs/{brief}/parity.json` (S1-04; auth + `BriefPolicy`, `Cache-Control: no-store`), which
returns `BriefPage::parityJson()` — the payload the page was rendered from — and the page carries
`data-parity-hash` for a DOM-side check (`tests/Feature/Pwa/BriefsPagesTest.php` asserts both equal
the mail snapshot).
