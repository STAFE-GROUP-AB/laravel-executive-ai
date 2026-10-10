# Menu bar panel (S1-09)

| | |
|---|---|
| **Status** | Implemented in `apps/macos/LaravelExecutiveAI/MenuBar/` (S1-09, #37) against the fixture pack; the live IPC client (`LoopbackIpcClient`, S1-08 part 2, [ipc-client.md](ipc-client.md)) runs behind `CoreClient` once the install checkpoint names a port; S1-11 adds the AI-state details and localisation |
| **Blueprint** | §1.1 (panel contents, "Quit UI" ≠ "Stop server", stale/offline/error visible, badge only for real action items), §14 ("native menu opens instantly from cached brief", "no brief generation on every menu open"), §11 (no tokens in any surface), §1.3 (brief parity) |
| **Contracts** | [health-contract.md](health-contract.md) §4/§7 (state mapping), [ipc-contract.md](ipc-contract.md) §6.1–6.5 (operations) and §6.2 (ETag, cache file), [brief-schema.md](brief-schema.md) §4/§6, [brief-parity.md](brief-parity.md) §1 (the payload), ADR-005 D1.6 (approvals never from the menu bar), threat model T-33/T-34 |

## 1. What the panel shows

`MenuBarScene` is a `MenuBarExtra` in `.window` style plus the standard `Settings` scene.
`MenuBarPanelView` renders, top to bottom, every element §1.1 lists:

| §1.1 element | Where | Source |
|---|---|---|
| Latest brief's date and generation time | header of the brief section: `generated_at` in the brief's own offset, `v<version>` | `health.latest_brief` when present, else the cached document |
| System status | status band: state title + detail, AI row, connectivity note, privacy mode | derived per §2 |
| Tasks awaiting approval | "Awaiting your approval (n)" — title, kind, sanitised summary, **Open** (deep link) | `approvals?state=pending`; count from `health.pending_approvals` |
| Action shortcuts | brief items that carry `action_ids`, marked with a seal; "Review action" inside the item opens `/approvals/<action id>` | the parity payload |
| Open full brief | `<origin>/briefs/<latest_brief.id>` (the PWA accepts a document id, pwa.md §3) | `CoreClient.origin` + health |
| Pause AI actions | toggles to "Resume AI actions"; `POST /ipc/v1/ai/pause` / `resume` | ipc §6.4 |
| Open PWA | `<origin>/home` | ipc §1.1 origin |
| Settings | the `Settings` scene (⌘,): `SettingsView` — the §1.1 sections still to come, and **Uninstall** (S1-13): "Uninstall…" opens `UninstallConfirmationView` with Keep my data / Delete everything / Cancel, wired to `ServiceControlling.uninstall(keepingData:)` ([`docs/operations/uninstall.md`](../operations/uninstall.md)) | — |
| Quit UI | terminates this process only (section 5) | — |

The brief body follows brief-parity.md §2: every item in document order, title and the whole
body behind a disclosure, `due_at` when present, source refs (type · account · title, uri as
tooltip) or the uncertainty marker (reason + confidence), one affordance per `action_ids` entry,
`privacy_mode` always visible. Every colour, font and spacing is a `DesignTokens` value.

Screenshots, rendered from the fixture client (`MenuBarRenderingTests` with
`TEST_RUNNER_LXAI_MENUBAR_SCREENSHOT_DIR`), one per state:

| Fresh | Stale + offline | Error (not ready) | Core unreachable | AI setup required |
|---|---|---|---|---|
| [01](images/menubar/01-fresh.png) | [02](images/menubar/02-stale-offline.png) | [03](images/menubar/03-error-not-ready.png) | [04](images/menubar/04-core-unreachable.png) | [05](images/menubar/05-ai-setup-required.png) |

Settings → Uninstall (S1-13), rendered by `UninstallViewTests` from the same fixture data:
[Settings](images/menubar/06-settings.png), [the sheet](images/menubar/07-uninstall-choose.png)
([Swedish](images/menubar/07-uninstall-choose-sv.png)),
[done with data kept](images/menubar/08-uninstall-done-kept.png),
[stopped half-way](images/menubar/09-uninstall-failed.png).

## 2. State table (health fields → panel state → icon / badge / copy)

`PanelModel.state` is derived from the last `/ipc/v1/health` answer and the reachability of the
core, exactly as health-contract §7 says. Precedence, highest first:

| Panel state | Driven by | Menu bar icon | Band | Title / detail | Badge |
|---|---|---|---|---|---|
| `coreUnreachable` | `health()` threw (`CoreClientError.unreachable`, non-JSON body) | `bolt.slash` | danger | "Core unreachable" / cached brief shown; **Open diagnostics**, **Restart services** | **0** (cleared) |
| `error(.notReady)` | `ready == false` | `exclamationmark.triangle` | danger | "Core not ready" / failing `checks[].name`s (never `detail` text); same two buttons | `pending_approvals` |
| `error(.updateRequired)` | the latest brief's `schema_version` major > `BriefParityPayload.supportedSchemaVersion` | `exclamationmark.triangle` | danger | "Update required" / no partial brief, cache untouched | `pending_approvals` |
| `error(.brief)` | `brief_state == error` | `exclamationmark.triangle` | danger | "Brief generation failed" / last good brief shown | `pending_approvals` |
| `stale` | `brief_state ∈ {stale, none}` (also: cache present, no health answer yet) | `clock.badge.exclamationmark` | warning | "Stale brief" / cached brief with a stale marker next to `generated_at` | `pending_approvals` |
| `error(.ai)` | `ai_state == error` | `exclamationmark.triangle` | danger | "AI error" / "Reconfigure AI in Settings" | `pending_approvals` |
| `aiSetupRequired` | `ai_state ∈ {not_configured, configured_untested}` **or** `ai_ready == false` | `gearshape` | warning | "AI setup required" / CRM, previous brief and admin keep working | `pending_approvals` |
| `fresh` | `ready`, `brief_state == fresh`, `ai_ready == true` | `brain` | neutral | "Up to date" | `pending_approvals` |
| `loading` | no health answer and nothing cached | `brain` | neutral | "Waiting for the core" / "No brief yet" | 0 |

Independent of the state:

- **AI row** — "AI ready" **only** when `ai_ready == true` (and `ai_state == ready`); otherwise
  "AI setup required" or "AI error"; "AI actions paused" while paused (§0, health §3).
- **Connectivity** — `offline` → "Offline — local features work, sync is queued"; `degraded` →
  "Some sources unreachable"; `online` → nothing. Informational, never a badge.
- **Privacy mode** — always visible as text.
- **Badge** — `pending_approvals` and nothing else (`PanelModel.badgeCount`). Stale, offline,
  error and AI states never add a badge; a stale brief with one pending approval badges `1`
  (`PanelModelTests.testAStaleBriefWithPendingApprovalsStillBadgesTheCountOnly`). An unreachable
  core clears it: nothing can be acted on.

`HealthDocument` decodes the full 1.0.0 document (the installer's `HealthReadiness` is the
installer's subset; `HealthDocument.readiness` bridges to it so S1-08 part 2 decodes once).
Unknown keys are ignored and unknown enum values decode to `.unknown`, which the table treats
conservatively (`stale` for the brief, `aiSetupRequired` for AI). `ai_actions` is decoded when a
later health minor adds it; until then the pause toggle keeps the last `ai/pause|resume` answer.

## 3. Cache policy (§14, ipc-contract §6.2, §13)

| | |
|---|---|
| **What** | Exactly **one** document — the latest frozen brief the core served — plus its ETag `"<id>:<version>"` and a `stored_at` timestamp. Never a health body, never a token, never the pairing grant (`BriefCacheTests.testCacheFileWrittenByARefreshNeverContainsATokenOrAHealthBody`). |
| **Where** | `InstallPaths.caches/menubar/latest-brief.json` = `~/Library/Caches/<bundle id>/menubar/latest-brief.json` (bundle id `$(LXAI_BUNDLE_PREFIX).app`, A12 placeholder `se.stafe.executive-ai.app`). File mode `0600`, directory `0700`, `isExcludedFromBackup`. ipc-contract §6.2 named `~/Library/Application Support/…/menubar/brief-cache.json`; S1-09 settles on the Caches directory because the file is reproducible from the core and ADR-001 D4 reserves Application Support for runtime and user data. |
| **Open path** | `PanelModel.openPanel()` reads the cache and nothing else. `PanelModelTests.testOpeningThePanelPerformsNoClientCall` opens the panel against a counting `CoreClient` and asserts **zero** calls; the same holds with an empty cache. |
| **Refresh path** | `PanelModel.refresh()` runs on a 30 s loop (`startRefreshing`) and is never awaited by rendering: `health` → `briefs/latest` only when `latest_brief.{id,version}` ≠ the cached ETag (`If-None-Match` sent; `304` keeps the cache) → `approvals?state=pending` only when `pending_approvals > 0` → `heartbeat`. |
| **Eviction** | A newer version replaces the file (one entry, ever). A document refused for a newer schema major is **not** written; the previous cache stays. A corrupt or foreign file is ignored. |
| **Invalidation** | `BriefCache.clear()` — called by "Reset security pairing" (ipc §3.4, `SupervisorServiceControl.resetPairing()`) and by the uninstaller's step 5 (S1-13, services.md §8); nothing else deletes it. |
| **Portability note** | Written with `.atomic` (rename into place) and then chmod'ed. macOS 15 refuses moves into a read-only directory while macOS 26 allows it; the cache directory is never made read-only, only the file, and the file is replaced, not moved. |

## 4. The `CoreClient` protocol S1-08 part 2 implements

```swift
protocol CoreClient: Sendable {
    /// http://127.0.0.1:<port> from the pairing grant (ipc §1.1); the PWA origin for deep links.
    var origin: URL { get }
    func health() async throws -> HealthDocument                                    // §6.1
    func latestBrief(ifNoneMatch etag: String?) async throws -> LatestBriefResponse  // §6.2 (.document(Data, etag:) | .notModified(etag:))
    func pendingApprovals() async throws -> PendingApprovals                        // §6.3 (read-only)
    func pauseAI() async throws -> AiActionsState                                   // §6.4
    func resumeAI() async throws -> AiActionsState                                  // §6.4
    func heartbeat(panelOpen: Bool) async throws -> HeartbeatAck                    // §6.5
}
```

Errors are `CoreClientError`: `unreachable(String)` (no answer, non-JSON body — the panel shows
"Core unreachable"), `notFound` (`404` on `briefs/latest` when `brief_state == none`), `forbidden`
(`403 forbidden_scope`, or `resumeAi` denied while the broker is locked), `versionUnsupported(serverMajor:)`
(`426`, the panel shows "Update required" and stops calling mutating operations), `rateLimited`,
`invalidResponse(String)`. The live client owns the token, request signing, the nonce, origin and
version checks; **none of that reaches the model or the view** — `PanelModel` never sees a header.

Shipped implementations: `LoopbackIpcClient` (S1-08 part 2, [ipc-client.md](ipc-client.md) — what
`PanelModel.live()` runs once `install/checkpoint.json` names `preflight.port`; signed requests to the
literal `127.0.0.1:<port>`, response signature verified before any body is parsed, the `menubar`
grant from the Supervisor in the GUI's own Keychain item), `UnreachableCoreClient` (what the app runs
before that; every call throws `unreachable`, so the panel stays cache-only) and `FixtureCoreClient`
(reads `packaging/fixtures/health/<state>.json`, `briefs/<name>.json`, `ipc/approvals-pending.json`,
`ipc/ai-pause|resume.json`, `ipc/heartbeat.json`; only `response.body.data` of an IPC fixture is
decoded, the recorded request headers are never read). `LXAI_MENUBAR_FIXTURE=<health fixture name>`
swaps the fixture client in for a DEBUG run. A `426` / higher wire major from the live client is the
"Update required" row of §2 (`PanelModel.ipcVersionRefused`): no brief, approvals or heartbeat until a
proper answer clears it (`IpcPanelIntegrationTests`).

**There is no approve/reject operation.** `MenuBarCopyTests.testMenuBarSourcesDefineNoApproveOrRejectPath`
greps every file under `MenuBar/` for `approve(` / `reject(` and friends, and the protocol above
has no such requirement; the panel lists approvals and opens `open_url` in the PWA, where the
owner re-authenticates (§11, ADR-005 D1.6).

## 5. "Quit UI" is not "Stop server"

`PanelModel.quitUI()` cancels the refresh loop and calls `AppTerminating.terminateUI()` — in the
app, `NSApplication.terminate`. The GUI ↔ Supervisor control surface (`ServiceControlling`:
`restartServices`, `stopServices`, `uninstall(keepingData:)`, implemented by S1-07/S1-13 over
XPC) is a separate port, and `PanelModelTests.testQuitUITerminatesTheGuiOnlyAndNeverTouchesServiceControl`
proves the quit path never invokes it and sends nothing to the core. The panel's only caller of
`ServiceControlling` is "Restart services" in the unreachable / not-ready band; `uninstall` is
reached from the Settings window alone, behind the confirmation sheet
(`UninstallViewTests.testTheChosenDataOptionReachesServiceControlAndNothingRunsBeforeConfirm`).
After Quit UI the heartbeat stops, so
`menubar_integration` fails 120 s later by design; the Supervisor does not restart on `ready`
dropping (ipc §6.5).

`LSUIElement` stays `false` (the installer window and Dock icon remain; a pure menu-bar agent
mode is an S1-11/S2 decision).

## 6. Brief parity: the third surface

`BriefParityPayload.fromDocument()` is the Swift port of the PHP reference (brief-parity.md §1):
fixed key set, absent optionals `null`, lists always lists, document order, raw RFC 3339 strings,
resolved `source_refs` (`account_label` / `title` / `uri`), a bare claim refused, a newer schema
major refused. `JCS.canonicalize` is the Swift RFC 8785 port (UTF-16 member order, `JSON.stringify`
escapes, ES6 `Number::toString` — `0.4` stays `0.4`, `1.0` prints `1`, `1e21` prints `1e+21`).
`BriefParityPayloadTests.testCanonicalFixtureExportIsByteIdenticalToThePHPSnapshot` asserts the
Swift export of `brief-canonical.json` equals `tests/__snapshots__/brief-canonical.parity.json`
byte for byte (4 794 bytes, the payload plus the one trailing newline the PHP snapshot writes);
every other brief fixture is projected and re-canonicalised stably. The view renders from the
payload, never from the raw document.

## 7. Tests

`apps/macos/LaravelExecutiveAITests/MenuBar/`: `JCSTests`, `BriefParityPayloadTests`,
`HealthDocumentTests`, `BriefCacheTests`, `PanelModelTests` (state table, badge rule, open-reads-cache,
ETag flow, actions, Quit UI), `MenuBarCopyTests` (secret markers, no approve/reject, no machine
paths, SF Symbols resolve, RFC 3339 offsets), `MenuBarRenderingTests` (every state renders; writes
the screenshots on demand). Fixtures are read from `packaging/fixtures/` relative to `#filePath`,
which resolves unsigned on CI because the whole repository is checked out.

## 8. What S1-11 added, and what is still open

Added (see [ai-state.md](ai-state.md) §3 for the cross-surface table):

- The AI row's details: `PanelModel.aiProviderText` (the catalogue display name of `ai_provider`,
  the raw id when unknown), `aiProviderNote` ("no completion has succeeded yet" while
  `configured_untested`), `aiLastSuccessText` (`ai_last_success_at`, shown **only** while
  `ai_ready == true`) and `aiAction` — "Set up AI" whenever AI is not ready, "Reconfigure AI" on
  `ai_state == error`, nothing while ready. `reconfigureAI()` lands in Settings until the Local
  Models pane exists; it selects nothing and stores nothing.
- Swedish copy: `MenuBarStrings.en` / `.sv`, chosen by `Locale.current`'s language
  (`MenuBarLanguage.preferred`), pinned with `MenuBarCopy.use(_:)` in tests. `MenuBarCopy.allStrings`
  covers both tables for the secrets grep.
- `MenuBarAiReadyGateTests`: no copy path renders "AI ready" / "AI redo" unless
  `ai_state == ready && ai_ready == true`, over every fixture and a synthetic document per
  `ai_state × ai_ready` pair, in both languages.

Still open:

- Visible-session state for Peekaboo automation on the icon (ADR-005 D1.10).
- Whether the app becomes a pure menu-bar agent after READY (`LSUIElement`).
- Whether "Quit UI" sends the ipc-contract §6.8 final heartbeat (`quitting: true`): the live client
  offers `heartbeat(panelOpen:quitting:)`; §5's "sends nothing to the core" test still stands.

Delivered by S1-08 part 2: the "Update required" flow for the IPC version header (§4).

## 9. Not decided here

- Where "Reset security pairing" is surfaced in Settings (its port, `resetPairing()`, exists and now
  replaces the session grant — ipc-client.md §2) — S1-08 follow-up.
