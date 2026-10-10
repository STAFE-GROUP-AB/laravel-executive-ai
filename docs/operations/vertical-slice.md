# The vertical slice (§16 step 8) — the gate before Sprint 3

> **Blueprint refs:** §16 step 8 (the slice), §16 step 9 (nothing external starts until the slice is
> stable), §13.1 (Sprint 1 exit: "no external integration"; Sprint 3 only after), §1.3 and §17
> ("en versionsmärkt brief visas identiskt i PWA, menyrad och e-post").
> **Owner:** I (QA/release) · **Backlog:** S1-15 (#43) · **Depends on:** S1-10 (parity gate),
> S1-11 (AI setup required), S1-13 (data-preserving reinstall, whose second-boot mechanics this reuses).
> **Status:** Binding from Sprint 1. The slice runs on every pull request; the `parity` job that
> carries it must be a **required** check before any Sprint 3 workstream issue is opened (§5).

§16 step 8 names one path through the product — *Install → Create owner → Open PWA → Generate
seeded brief → Show same brief in menu bar → Preview exact email* — and step 9 makes it the gate:
external integrations, Peekaboo and MCP may only start once this slice is **stable**. This page
defines "stable" as *a green automated run on a freshly installed core, on every pull request*, plus
one documented run on a clean Mac that CI cannot simulate.

---

## 1. What the slice proves

One Pest test, [`tests/E2E/VerticalSliceTest.php`](../../tests/E2E/VerticalSliceTest.php), walks
the six steps **in order, in one process, against a freshly installed core**:

| # | §16 step | What runs | What is asserted |
|---|---|---|---|
| 1 | Install | A second application is booted through the real `bootstrap/app.php` against a throwaway `LEA_DATA_DIR` in the temp directory (installer-shaped subdirectories, `tests/Support/FreshInstance.php`) and `migrate` runs on the empty SQLite file — what the Supervisor's first core start does. | The connection is `<LEA_DATA_DIR>/database/executive.sqlite`; the install marker reads `not_installed`; no owner, no brief. `GET /health/ready` (signed, `supervisor` scope) is **503** with the contract's body shape: `auth_login` fails with "no owner account", `brief_placeholder` fails, `brief_state: none`. `/install/owner` is 200, `/login` redirects to it, `/home` redirects to `/login`. |
| 2 | Create owner | The §12.3 "Create account" form (`CreateOwnerForm`, Livewire) creates organization, user and owner membership and signs the installer in. Then the rest of the install: `lea:brief:placeholder`, `queue:work --stop-when-empty` (one real worker loop), `health:selftest`, one signed `POST /ipc/v1/heartbeat` as the menu bar, `lea:install-state core_ready --reason=HEALTH_CHECK`. | Exactly one owner; `/install/owner` is **404** afterwards and `lea:owner:create` exits with `OWNER_EXISTS` (T-72). Between the owner and the placeholder the PWA shows its empty state ("No briefs yet" / "No brief yet"). After BOOT_SERVICES, `/health/ready` is **200** with all seven checks `ok`, `ai_ready: false`, `ai_state: not_configured`, `latest_brief` = the placeholder. The marker history is `CREATE_OWNER → installing`, `HEALTH_CHECK → core_ready`. |
| 3 | Open PWA | The installer's session ends (`POST /logout`). The owner signs in with the real one-time-code flow: `Login::sendCode` mails one `OtpMail` through the `array` mailer, the six-digit code is read out of that message's text part, `Login::verify` accepts it. | `/home` redirects guests to `/login`; the login page has no password field; exactly one code mail; `login_count` becomes 1. Signed in, `/home` is 200 and shows the placeholder (`data-latest-brief`), `/briefs` lists exactly one version, no "AI on your phone" claim (§1.2). `/offline` is 200 with **no** `Set-Cookie` (the one page the service worker precaches). |
| 4 | Generate seeded brief | The clock moves to the fixture clock (`PwaFixtures::FIXTURE_NOW`). The frozen canonical fixture `packaging/fixtures/briefs/brief-canonical.json` — the "single JSON source" of the Sprint 1 exit (§13.1) — is imported through `BriefDocumentMapper::import` with `Provenance::job('VerticalSlice', Import)`. | Deterministic by construction: the stored brief's `content_hash` equals the fixture's and `BriefContentHash::compute(export)` reproduces it. `GET /briefs/{brief}` renders with `data-brief-version="1"`, `data-brief-content-hash` = the fixture's, `data-parity-hash` = `Jcs::sha256` of the payload; `GET /briefs/{brief}/parity.json` returns byte-for-byte `BriefParityPayload::fromDocument(fixture)` with the same `X-LEA-Parity-Hash`. `/home` now shows this brief as the latest. |
| 5 | Show same brief in menu bar | The menu bar's 30 s refresh loop (menubar.md §3): heartbeats again, `GET /health/ready`, then `GET /ipc/v1/briefs/latest` (signed, `menubar` scope). | `latest_brief` is the seeded brief v1, `brief_state: fresh`; the IPC `ETag` is `"<id>:1"` and `If-None-Match` yields **304**. **(a) In-process:** the Swift port's input — the document the menu bar actually fetched — projected through `BriefParityPayload::fromDocument` is byte-identical to the PWA export. **(b) The real SwiftUI port:** the menu bar export of the same fixture (`tests/Support/MenuBarParityExport.php`; committed snapshot in the `php` lane, live `BriefParitySnapshotTests` export in the `parity` lane) is byte-identical to the PWA export of the brief this fresh core served. |
| 6 | Preview exact email | The default mailer is switched to a transport whose `send()` throws (`tests/Support/FailingMailTransport.php`). `GET /briefs/{brief}/preview/mail` and `/preview/text` as the owner, `lea:brief:preview <id> --out`, and the exact `BriefMailable` built through the `preview` mailer (array transport). | Both routes are 200 with `X-LEA-Brief-Preview: nothing-sent` and bodies **identical** to `BriefMailRenderer::renderBrief()`; the HTML carries the version label and the content-hash prefix; `RenderedBrief::parityJson()` equals the PWA export; the files `lea:brief:preview` writes are the same bytes; the captured message's HTML and text parts are the renderer's bytes; the failing transport was reached **0** times; the `array` mailer holds exactly the one sign-in code. |

After step 6 the run re-reads `/health/ready` and asserts the §13.1 assurances: `ai_ready: false`,
`ai_provider: null`, no `AiCompletionSuccessMarker`, `Http::preventStrayRequests()` armed for the
whole run with nothing recorded, no mail through any transport but `array` (one OTP) and `preview`
(one captured brief), install marker still `core_ready`.

Finally the report (§3) is held to itself: six steps passed, and `surfaces.pwa`,
`surfaces.menu_bar` and `surfaces.email` carry **one `version` and one `content_hash`**, equal to
the fixture's.

### 1.1 What it deliberately does not do

- It does not run `artisan serve` or a browser: ADR-001 forbids the former in the product, and §14
  only *permits* Playwright. The real HTTP kernel, the real Livewire components, the real signed IPC
  middleware and the real console commands are driven in-process.
- It does not click the Mac installer. The Swift state machine is proven by
  `apps/macos/LaravelExecutiveAITests/Install/`; what the slice reproduces is **what the installer
  asks the core to do** at each state (migrate, create owner, placeholder, boot services, health
  check, marker). The one run that exercises both together is the clean-machine run (§4).
- It does not generate a brief with a model (Sprint 2). The seeded brief is the frozen fixture, so
  the expected `version` and `content_hash` are known before the run starts.

## 2. Running it locally

```sh
# the slice alone (menu bar oracle: the committed snapshot) — ~0.5 s
php artisan test --testsuite=E2E --filter=VerticalSlice

# the driver: runs the slice, validates the report, prints the summary
node packaging/fixtures/e2e/vertical-slice.mjs

# with the live SwiftUI export as the menu bar oracle (what the `parity` CI job does; needs Xcode)
node packaging/fixtures/e2e/vertical-slice.mjs --swift
#   = xcodebuild … -only-testing:LaravelExecutiveAITests/BriefParitySnapshotTests test  (→ build/parity)
#     then LXAI_SWIFT_PARITY_EXPORT_DIR=$PWD/build/parity vendor/bin/pest tests/E2E/VerticalSliceTest.php

# validate an existing report without re-running
node packaging/fixtures/e2e/vertical-slice.mjs --check

# the driver's own shape tests (no PHP needed)
node --test 'packaging/fixtures/e2e/*.test.mjs'
```

The run writes `build/e2e/vertical-slice.json` (git-ignored; override with `LXAI_SLICE_REPORT`)
whatever the outcome, so a red run still says which step failed and why. Nothing is left behind:
the temp data directory is deleted in `finally`, and the suite's own `database/testing.sqlite` is
never touched (the test uses no database trait at all; the fresh core has its own file).

## 3. Reading the report

`build/e2e/vertical-slice.json` (schema `1.0.0`, validated by `vertical-slice.mjs`):

| Field | Meaning |
|---|---|
| `passed`, `failure` | Green only when all six steps passed; otherwise `failure` names the step and the exception. |
| `menu_bar_oracle` | `committed snapshot` (no Swift export in reach; the `php` lane, a plain local run) or `swift export` (`LXAI_SWIFT_PARITY_EXPORT_DIR` set; the `parity` lane). A run never guesses: the oracle is decided before the first step. |
| `steps[]` | The six §16 steps in order — `index`, `id`, `title` (the blueprint's wording), `status`, `duration_ms`, `evidence` (the facts that step established, e.g. `health_status: 503`, `second_owner_exit_code: 3`, `ipc_etag`). A failed step carries `failure`. |
| `brief` | The seeded brief: `document_id`, `version`, `generated_at`, `content_hash`, and `source` (the fixture file). |
| `surfaces.pwa` / `surfaces.menu_bar` / `surfaces.email` | The `version` and `content_hash` **each surface reported**, plus where it was read (`route`, `oracle` + `ipc_etag`, `preview_route`). The validator refuses a green report whose three entries differ from each other or from `brief`. |
| `assurances` | §13.1: `outbound_http`, `ai_ready`, `ai_state`, `ai_provider`, `ai_completion_marker`, `mailers_used`, `failing_transport_attempts`, `external_accounts`, `install_state`. |
| `driver` | Added by `vertical-slice.mjs`: Pest exit code, Node version, platform, whether the Swift export was the oracle. |

The CI job summary of the `parity` job shows the same thing as one page: a step table and a
surface table with the triple `version` / `content_hash` per surface. The report is uploaded as the
`vertical-slice-<run id>` artifact.

## 4. The clean-machine run CI cannot simulate

CI proves the core's half of the slice on a fresh *data directory*, not on a fresh *Mac*: no
Gatekeeper prompt, no Login Items approval, no Keychain, no real Supervisor spawning the real
runtime, no real browser opening the PWA. §16 step 9 asks for a slice that is stable, and the
acceptance of S1-15 asks for one documented clean-machine run. It is recorded with the install
measurement protocol, which already defines "clean" — [`install-measurement.md`](install-measurement.md)
§2: a fresh macOS user account on a machine profile from the support matrix, no previous install, no
cached runtime, Gatekeeper on, no developer tools on the path, run with `--clean yes`.

Checklist for the run (one line each in the measurement record's `notes`, or in the S1-14 lab log):

- [ ] **Install** — DMG opened, Gatekeeper accepted the signature, `WELCOME → … → HEALTH_CHECK → READY`
      on the Done screen; the installer's event log (`install-measurement.md` §4) shows every state once.
- [ ] **Create owner** — screen 4 accepted the owner; no Terminal, no password prompt; a second
      launch of the installer does not offer "Create account" again.
- [ ] **Open PWA** — "Open" on the Done screen opens `http://127.0.0.1:<port>/home` in the default
      browser; sign out and sign back in with the emailed code (local mail catcher or the `log`
      mailer; read the code from there — never from a production mailbox).
- [ ] **Generate seeded brief** — `php artisan lea:brief:preview --fixture=packaging/fixtures/briefs/brief-canonical.json`
      is *not* the seeded brief (it only renders); import the fixture with the Sprint 2 import
      command once it exists, or until then record the placeholder brief as the brief under test and
      say so. Note `version` and `content_hash` from `/briefs/{brief}/parity.json`.
- [ ] **Show same brief in menu bar** — the menu bar panel shows the same subject and version;
      `Caches/<bundle id>/menubar/latest-brief.json` holds the document with `content_hash` equal to the
      PWA's; opening the panel makes no request (menubar.md §3).
- [ ] **Preview exact email** — `/briefs/{brief}/preview/mail` in the browser shows the version label
      and the content-hash prefix; the mail catcher received **nothing**.
- [ ] Attach the measurement record (`install-measurement.md` §6) and the `build/e2e/vertical-slice.json`
      of the same commit, and note the commit SHA.

The first such run is S1-14's; it is recorded there, not here. Until a record exists this page
says so rather than implying one.

## 5. Making it required

The slice lives in the `parity` job (`.github/workflows/ci.yml`, step "Vertical slice (§16 step 8)")
so it shares the checkout and the Swift export with the S1-10 gate. `parity` is **not yet** in the
S0-27 required-check list (`docs/contributing/rulesets/main-now.json`: `php`, `swift`,
`secrets-scan`, `fixtures`, `commitlint`). §16 step 9 requires that it is before any Sprint 3
workstream issue is opened: add `{ "context": "parity", "integration_id": 15368 }` to
`required_status_checks` in both ruleset files and re-apply the ruleset (branch-protection.md §1.1).
That is a repository-admin action for Andreas; it is tracked in `docs/OPEN-QUESTIONS.md` A14 and
nothing in this repository calls a settings API.

Until then the `php` lane still runs the slice on every pull request (committed-snapshot oracle), so
a content regression on any of the three surfaces fails a required check today; what only `parity`
proves is the live Swift export.

## 6. Changing the slice

- A new §16 step cannot appear: the driver reads step 8 out of the blueprint and fails if its list
  and `VerticalSliceReport::STEPS` disagree (`vertical-slice.test.mjs`).
- Changing the seeded brief means changing the canonical fixture (brief-parity.md §3.4); the slice
  follows automatically, as do the snapshot and the Swift export.
- A new surface (Sprint 2's delivered mail, Sprint 3's push text) is a new entry in `surfaces` and a
  new line in `SURFACES` of the driver, with the same `version` / `content_hash` rule.
