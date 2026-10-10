# Brief parity across surfaces (S1-03 payload, S1-10 gate)

**Status:** In force — payload shape frozen by S1-03 (#31); the tri-surface gate (§3) lands with
S1-10 (#38) and runs on every pull request · **Owner:** Workstream I (QA/release) with B (Laravel)
and A (macOS)
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
| Builds the payload from the document, renders from the payload | `BriefMailRenderer` → `BriefParityPayload` → `BriefViewModel` → Blade | `BriefPage::fromBrief()` → `BriefParityPayload` → the same `BriefViewModel::build()` → `briefs/show.blade.php` | `BriefParityPayload.fromDocument()` (Swift port, `MenuBar/BriefParityPayload.swift`) → `PanelModel.brief` → `MenuBarPanelView`; the raw document goes to the cache only |
| Shows every item, in order, title + whole body | done | done (`<li data-brief-item data-position>`) | done — `ForEach(brief.items)` in document order; whole body inside a disclosure |
| `due_at` only when present | done | done (`<time data-due-at>`) | done — "Due …" row in the item's own offset |
| Source refs (type · account · title · uri) **or** uncertainty marker (reason + confidence); both when both | done | done (`data-source-ref`, `data-uncertainty`) | done — `SourceRefRow` (uri as tooltip) and the uncertainty row with `confidence` as a percentage |
| `privacy_mode` visible | footer | header `<dd data-privacy-mode>` | status band "Privacy mode: …" (health's value when reachable, the payload's otherwise) |
| Every `action_ids` entry reachable as an approval affordance | link per action id | badge per action id (`data-action-id`); links once `/approvals` exists | "Review action" button per action id → `<origin>/approvals/<id>`; seal on the item row |
| Exports the payload as JCS | `RenderedBrief::parityJson()`; `lea:brief:preview` writes `*.parity.json` | `GET /briefs/{brief}/parity.json` (`BriefPage::parityJson()`); `data-parity-hash` on the article | `BriefParityPayload.canonicalJSON()` (`MenuBar/JCS.swift`); `BriefParityPayloadTests` asserts byte-identity with the committed snapshot |
| Declares the max `schema_version`; refuses a higher major | `BriefMailRenderer::SUPPORTED_SCHEMA_VERSION` | inherits the mapper's `Brief::SCHEMA_VERSION` gate at import | `BriefParityPayload.supportedSchemaVersion`; a higher major → `error(.updateRequired)`, never a partial brief, cache untouched |

## 3. The S1-10 gate

One harness, every fixture in `packaging/fixtures/briefs/`, three renderers, one comparison: the
RFC 8785 bytes of §1's payload. Markup is never compared, so a layout change cannot break the gate
and a content change cannot pass it.

### 3.1 What runs

| Piece | Surface | What it does |
|---|---|---|
| [`tests/E2E/BriefParityTest.php`](../../tests/E2E/BriefParityTest.php) (Pest, suite `E2E`, `DatabaseTransactions`) | Mail + PWA, and the owner of the comparison | For **every** fixture: imports it (`BriefFixtures::import`), renders the mail from the stored row (`BriefMailRenderer::renderBrief`) and the PWA twice — `BriefPage::fromBrief()` and the real owner route `GET /briefs/{brief}/parity.json` — and asserts: the three PHP exports are byte-identical; they equal the fixture's own projection `BriefParityPayload::fromDocument(fixture)`; `version`, `generated_at` and `content_hash` are identical across them and equal the fixture's; the page's `data-parity-hash` (and the endpoint's `X-LEA-Parity-Hash`) is `Jcs::sha256` of the export. The canonical fixture is additionally held to `tests/__snapshots__/brief-canonical.parity.json`. Then the third surface: every fixture's menu bar export is read (§3.2) and asserted byte-identical to the mail, the page and the endpoint, with the same `version`/`generated_at`/`content_hash`. |
| [`apps/macos/LaravelExecutiveAITests/MenuBar/BriefParitySnapshotTests.swift`](../../apps/macos/LaravelExecutiveAITests/MenuBar/BriefParitySnapshotTests.swift) (XCTest, app-hosted) | Menu bar | For **every** fixture: projects through the Swift port `BriefParityPayload.fromDocument`, asserts `version`, `generated_at` and `content_hash` equal the fixture's, asserts the canonical export equals the committed snapshot, and writes `<fixture>.parity.json` (payload JCS + one `\n`, the snapshot's framing) into the export directory, then asserts that directory holds exactly one file per fixture. |
| [`tests/Support/MenuBarParityExport.php`](../../tests/Support/MenuBarParityExport.php) | — | Decides, once, while Pest collects datasets, which **oracle** this lane has for the menu bar (§3.2) and spells it out in the dataset key, so a run's output always says what it compared against. |

Presentation stays guarded separately per surface (brief-schema §6): mail by
`tests/Feature/Briefs/Mail/BriefMailSnapshotTest.php` (`tests/__snapshots__/brief-canonical.{html,txt}`),
PWA by `tests/Feature/Pwa/BriefsPagesTest.php`, menu bar by `MenuBarRenderingTests`.

### 3.2 The handoff and the oracle per lane

The Swift test writes its exports to **`build/parity/` under the repository root** (git-ignored, next
to the xcresult bundles CI writes). `LXAI_SWIFT_PARITY_EXPORT_DIR` overrides the location when the
test *process* has it (an Xcode scheme environment variable), but not from `xcodebuild`: an
app-hosted XCTest on macOS is launched with a scrubbed environment and never sees
`TEST_RUNNER_*` (verified in S1-10 by dumping `ProcessInfo.processInfo.environment` from the test
host). The PHP side only compares against the Swift export when **it** has
`LXAI_SWIFT_PARITY_EXPORT_DIR` — a stray local export can never silently become the oracle of a
plain `vendor/bin/pest` run.

| CI job (`.github/workflows/ci.yml`) | Runner | Mail + PWA | Menu bar oracle |
|---|---|---|---|
| `php` | ubuntu | every fixture, three PHP exports vs the fixture projection and each other | **committed snapshot**, canonical fixture only. The run prints `Swift export not available in this lane` and the dataset key reads `canonical · oracle: committed snapshot (…)`. This is sound because the `swift` lane proves the Swift port produces exactly those bytes. |
| `swift` | macos-15 | — | `BriefParityPayloadTests` (S1-09) and `BriefParitySnapshotTests`: Swift canonical export == committed snapshot; all fixtures project; `build/parity/` written as a by-product. |
| `parity` | macos-15 | every fixture | **live Swift export**: step (a) runs only `BriefParitySnapshotTests` (same unsigned `xcodebuild` flags as `swift`, `rm -rf build/parity` first) and checks one file per fixture exists; step (b) installs PHP 8.4 + Composer and runs `vendor/bin/pest tests/E2E/BriefParityTest.php` with `LXAI_SWIFT_PARITY_EXPORT_DIR=$GITHUB_WORKSPACE/build/parity`. Dataset keys read `<fixture> · oracle: swift export`. The exports are uploaded as the `parity-exports-<run id>` artifact. |

`parity` runs on **every pull request**, not behind a `paths` filter: in GitHub Actions a filter is
workflow-wide (it would gate `php`, `swift` and `fixtures` too), and a correct filter would have to
enumerate every file a renderer depends on — view models, locale tables, the document mapper,
fixtures, snapshots — where one missed path is a silent hole in the gate. The job costs one macOS
runner for the Swift build plus a Composer install; the job name is stable so S0-27 can add it to
the required checks.

### 3.3 Verified broken

The acceptance "adding a field to one surface and not the others fails the test" was proven once by
adding one key to the PWA payload only (`$payload['surface'] = 'pwa'` in `BriefPage::fromBrief()`,
commit `3b3e551` on PR #77), pushing, and letting CI fail; the following commit reverted it.

- **Verified broken on 2026-10-10:** run
  <https://github.com/STAFE-GROUP-AB/laravel-executive-ai/actions/runs/38077108059> —
  `parity` job [114286321232](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/actions/runs/38077108059/job/114286321232)
  (Swift export step green, 11 files; Pest step red) and `php` job
  [114286321184](https://github.com/STAFE-GROUP-AB/laravel-executive-ai/actions/runs/38077108059/job/114286321184)
  (15 failed, 775 passed). `swift`, `fixtures`, `secrets-scan`, `commitlint` stayed green, as they
  should: the break was content, not presentation, and only the gate sees content.
- **Assertion:** `FAILED Tests\E2E\BriefParityTest > it renders mail, pwa page and pwa endpoint to
  byte-identical JCS equal to the fixture projection with ("canonical")` —
  `pwa BriefPage export of `canonical` differs from the mail export` / `Failed asserting that two
  strings are identical.` with the JCS diff showing the extra `"surface":"pwa"` member. In the
  `php` lane the same assertion failed for all 11 fixtures plus the committed-snapshot test (and
  the S1-04 `BriefsPagesTest` parity test). Locally, with the Swift export present, the run has 23
  failures: those 12 and the 11 menu bar comparisons (`menu bar (swift export) export of … differs
  from the pwa page export`) — in CI run 38077108059 the latter were masked by the side finding
  below.
- **Side finding fixed in the same PR:** on a fresh checkout the `parity` job's first test built the
  SQLite schema *inside* its `DatabaseTransactions` transaction (`Tests\TestCase` migrated after the
  traits had begun it), so the rollback dropped the tables and the remaining 44 tests failed with
  `no such table: organizations` instead of the parity assertion. `TestCase::setUpTraits()` now
  builds the schema before the traits start; the `php` lane had never hit this because a Unit test
  without the trait always ran first.

### 3.4 Adding a fixture, changing the payload

- **New fixture:** drop `brief-<name>.json` into `packaging/fixtures/briefs/` (it must pass
  `node packaging/fixtures/validate-schema.mjs`). Nothing else: both sides glob the directory,
  and the harness fails if the Swift export set and the PHP fixture set differ.
- **Changing the canonical fixture's content** changes the committed snapshot: run
  `UPDATE_SNAPSHOTS=1 vendor/bin/pest tests/Feature/Briefs/Mail/BriefMailSnapshotTest.php`, review
  the diff, and expect `BriefParityPayloadTests` (Swift) to need its byte count updated.
- **Adding or renaming a payload key** is a contract change for all three surfaces: change
  `BriefParityPayload.php` and `BriefParityPayload.swift` in the same PR, update §1, regenerate the
  snapshot — the `parity` job is what tells you if one side was forgotten.

### 3.5 Running it locally

```sh
# menu bar surface → build/parity/*.parity.json
xcodebuild -project apps/macos/LaravelExecutiveAI.xcodeproj -scheme LaravelExecutiveAI \
  -destination 'platform=macOS' -only-testing:LaravelExecutiveAITests/BriefParitySnapshotTests \
  CODE_SIGN_IDENTITY="" CODE_SIGNING_REQUIRED=NO CODE_SIGNING_ALLOWED=NO test

# mail + PWA against it (46 tests: 11 fixtures × 4 + 2)
LXAI_SWIFT_PARITY_EXPORT_DIR="$PWD/build/parity" vendor/bin/pest tests/E2E/BriefParityTest.php

# without the Swift side (what the `php` lane does; 36 tests, committed-snapshot oracle)
vendor/bin/pest tests/E2E/BriefParityTest.php
```
