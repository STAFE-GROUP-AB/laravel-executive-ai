# Installer state machine (S1-05)

| | |
|---|---|
| **Status** | Implemented in `apps/macos/LaravelExecutiveAI/Install/` (S1-05, #33); runtime install (S1-06), supervision (S1-07), IPC/health probe (S1-08) and owner creation (S1-02) plug in behind protocols |
| **Blueprint** | §12.1 (states, real checkpoints, cancel/resume/rollback, no shell commands), §12.2 (no admin password until an OS-protected operation needs one), §12.3 (six screens, ≤ 6 mandatory interactions), §6 (verify before execute), §0 (what is async and never inside core-ready) |
| **Contracts** | [support-matrix.md](support-matrix.md) §6 (preflight), [`docs/operations/install-measurement.md`](../operations/install-measurement.md) §4 (event log), [health-contract.md](health-contract.md) (`READY`), [ipc-contract.md](ipc-contract.md) §2/§6.1 (the `supervisor`-scoped token the probe needs), ADR-001 D3/D4, ADR-003 D5, threat model T-47/T-71/T-72 |

## 1. States and transitions

`InstallState` has the nine core states and the five optional ones. The transition table is
explicit (`InstallState.allowedTransitions`); anything not listed throws
`InstallTransitionError`.

| From | To | Trigger | Waits for the person |
|---|---|---|---|
| `WELCOME` | `PREFLIGHT` | Welcome CTA ("Install" / "Continue installation") | yes — screen 1 |
| `PREFLIGHT` | `PACKAGE_VERIFY` | all ten checks ran, no blocker | no |
| `PACKAGE_VERIFY` | `INSTALL_CORE` | manifest, `SHA256SUMS`, every file digest and the runtime signature verified | no |
| `INSTALL_CORE` | `CREATE_OWNER` | runtime staged, re-hashed, activated (`current` flipped), data directory prepared | no |
| `CREATE_OWNER` | `SELECT_AI` | owner form submitted and accepted by the core | yes — screen 4 |
| `SELECT_AI` | `BOOT_SERVICES` | AI choice submitted (skip / detected local runtime / own key) | yes — screen 5 |
| `BOOT_SERVICES` | `HEALTH_CHECK` | Supervisor LaunchAgent registered and started | no (a Login Items approval is a click, not a password) |
| `HEALTH_CHECK` | `READY` | `/health/ready` returned `ready: true` — never on a timer, never on `/health/live` | no |
| `READY` | any optional step | Done-screen checklist (later sprints) | yes — screen 6 |
| optional step | next optional step, or `READY` | the step finishes with an outcome | — |

Rules the machine enforces:

- **Optional path only after `READY`.** `enterOptional` throws `optionalBeforeReady` in every core
  state; the table has no edge from a core state to an optional one.
- **Three waiting screens** (`WELCOME`, `CREATE_OWNER`, `SELECT_AI`) plus "Open" on Done: four
  mandatory interactions on the happy path (§12.3 allows six).
- **Cancel** works in every state before `READY`. Running work is a cancellable `Task`; the
  automated states check cancellation between checkpoints and the runtime installer between
  files. A partial `INSTALL_CORE` is rolled back before the status becomes `cancelled`.
- **Retry** re-runs the current state (re-entry is logged, which ends the measured run per
  install-measurement §4). For a waiting state the form is shown again.
- **Rollback** removes the staging directory and any version directory `current` does not point
  at. Offered on every `INSTALL_CORE` failure next to Retry and Cancel.
- **Resume** continues from `InstallCheckpoint.resumeState` (section 3). A partial runtime found
  in the checkpoint is removed first.
- **Verify before execute.** `INSTALL_CORE` installs only a `VerifiedPackage` produced by
  `PackageVerifier` in this process; a resumed or retried run verifies again before copying.
- **No shell command is ever shown.** The support matrix's shell-parity commands live in
  `PreflightCheckID.shellParity` as contributor documentation; a test asserts no blocker text
  contains `$`, `sudo`, `codesign` or `Terminal`.
- **No admin password.** Nothing in this item calls Authorization Services. `BOOT_SERVICES`
  may ask the person to approve the login item in System Settings; that is surfaced as
  `loginItemApprovalRequired` with an "Open Login Items" action.

### Status, separately from state

`InstallStatus` is `idle`, `running`, `waitingForPerson`, `failed(InstallFailure)`, `cancelled`
or `rolledBack`. `InstallFailure` carries a title, a sanitised detail and the actions the error
screen offers (`retry`, `cancel`, `rollback`, `openLoginItems`). A preflight blocker is
`failed(.preflightBlocked([PreflightBlocker]))` and renders as screen 2 with blockers only.

## 2. Preflight

`Preflight` runs the ten `PreflightCheck` values of support-matrix §6 in order, all of them even
after a blocker, through the `PreflightProbes` protocol (`SystemPreflightProbes` is the macOS
implementation; tests inject `StubPreflightProbes`).

| # | `PreflightCheckID` | macOS API used | Blocker | Warning |
|---|---|---|---|---|
| 1 | `macos_version` | `ProcessInfo.operatingSystemVersion`, `sysctl kern.osversion` | < 15.0 | major > 26 |
| 2 | `apple_silicon` | `sysctlbyname("hw.optional.arm64")`, `sysctl.proc_translated` | not arm64 or translated | — |
| 3 | `memory` | `ProcessInfo.physicalMemory` | < 8 GiB | — |
| 4 | `free_disk` | `volumeAvailableCapacityForImportantUsage` on the volume holding Application Support | < 2 GB | < 5 GB |
| 5 | `permissions` | temporary directory under Application Support, `SCDynamicStoreCopyConsoleUser`, `NSUserName`, `getuid` | not writable, Guest or root | not the console user |
| 6 | `port_collision` | `socket`/`bind` on `127.0.0.1:8787`, fallbacks `8788–8799`, no `SO_REUSEADDR` | all taken | preferred taken (fallback recorded) |
| 7 | `network` | `NWPathMonitor`, first update within 2 s, no traffic | never | offline, expensive or constrained |
| 8 | `existing_install` | `runtime/current` symlink + its `manifest.json`, `data/` owner, `SMAppService.agent(plistName:).status` | newer version installed; `data/` owned by another user | existing install (routing: data-preserving reinstall) |
| 9 | `filevault` | `fdesetup status` via `Process`, fallback `diskutil apfs list` | never | off (recommend) / on (login note) |
| 10 | `developer_signature` | `SecStaticCodeCheckValidityWithErrors` with strict flags against `anchor apple generic and certificate leaf[subject.OU] = "<TEAMID>"`, Team ID read from the app's own signature | any executable invalid, or no Team ID | — |

Blocker texts are the matrix's EN/SV strings verbatim (`PreflightMessages`); Swedish is shown
when the first preferred language is `sv`. Warnings go to the checkpoint (`preflight.warnings`)
and the Done-screen checklist (FileVault), never to screen 2.

**Deviation to know about (6.8).** The matrix also asks whether something on the chosen port
answers `/health/live`. That is a loopback HTTP request and belongs to the IPC client; until
S1-08 lands, an existing install is recognised by its runtime link and data directory only.

## 3. Checkpoint

`InstallCheckpoint` is persisted by `FileInstallCheckpointStore` as
`~/Library/Application Support/<bundle id>/install/checkpoint.json` (0600, atomic write) after
every completed state. Shape (snake_case, ISO 8601 dates):

```json
{
  "schema_version": 1,
  "run_id": "6E1C…",
  "last_completed_state": "INSTALL_CORE",
  "updated_at": "2026-10-09T10:01:12Z",
  "preflight": { "port": 8787, "language": "en", "existing_install": "fresh", "warnings": ["filevault"], "filevault_enabled": false },
  "runtime": { "version": "0.1.0", "staging_directory": null, "activated": true },
  "owner_created": false,
  "ai_selection": null
}
```

- `ai_selection` is `{"kind": "skip"}`, `{"kind": "local_runtime", "runtime": {...}}` or
  `{"kind": "own_api_key", "provider": "openai"}`. **The key is never stored**; it travels once
  in a `TransientSecret` to the `AiSelectionApplying` implementation.
- The owner's name and address are not stored; only `owner_created`.
- `runtime.activated == false` with a `staging_directory` means a half-done `INSTALL_CORE`;
  `resume()`, `retry()` and `cancel()` all roll it back first.
- **Resume point.** `last_completed_state` before `INSTALL_CORE` → resume at `PREFLIGHT`
  (preflight is cheap and verification is never trusted across launches); `INSTALL_CORE` or
  later → the next state. `READY` → the app opens straight to the main window.
- `run_id` names the event log file, so a resumed run appends to the same log.

## 4. Event log

`InstallEventLog` writes `~/Library/Logs/<bundle id>/install-events-<run_id>.ndjson` (0600) in
exactly the install-measurement §4 shape; `packaging/fixtures/e2e/installer-events.ndjson` is a
byte-identical sample pinned by `InstallEventLogTests` and consumed by
`packaging/manifest/install-timer/test/installer-events.test.mjs`:

```json
{"state":"WELCOME","at":"2026-10-09T12:00:00.000+02:00"}
{"state":"human_interaction_start","at":"2026-10-09T12:00:01.500+02:00"}
{"state":"human_interaction_end","at":"2026-10-09T12:00:03.000+02:00"}
{"state":"PREFLIGHT","at":"2026-10-09T12:00:04.500+02:00"}
…
{"state":"READY","at":"2026-10-09T12:00:21.000+02:00"}
{"state":"CONNECT_EMAIL","marker":"start","at":"2026-10-09T12:00:22.500+02:00"}
{"state":"CONNECT_EMAIL","marker":"end","outcome":"skipped","at":"2026-10-09T12:00:24.000+02:00"}
```

- One entry per core state, at entry. `human_interaction_start/_end` around the Welcome CTA,
  the owner form, the AI choice and the Login Items approval sheet; starts never nest.
- `READY` is written when the machine transitions to it, which happens only after the probe
  returned `ready: true`.
- Optional steps are `marker: start/end` with an `outcome`.
- Timestamps are RFC 3339 with milliseconds from `MonotonicWallClock` (wall-clock anchor advanced
  by `ContinuousClock`), one clock per run.
- The API accepts only enum values, so the log cannot contain a token, a name, an address or
  mail content.

## 5. Package verification

`PackageVerifier.verify(runtimeDirectory:)` fails closed, in this order: `manifest.json` parses
and validates (schema 1, `runtime_version`, `executable`, `files[{path, sha256, bytes}]`, safe
relative paths, no duplicates) → `SHA256SUMS` (`sha256sum` format) lists exactly the manifest's
files with the same digests → every file is a regular file with the stated size and SHA-256 →
no unlisted regular file or symlink exists anywhere in the directory →
`SecStaticCodeCheckValidityWithErrors(kSecCSCheckAllArchitectures | kSecCSStrictValidate |
kSecCSCheckNestedCode)` on the executable against the Team ID requirement. The binary is never
launched. Hashing runs off the main actor so the UI stays cancellable.

`SecStaticCodeSignatureChecker.fromRunningApp()` derives the requirement from the app's own Team
ID; an ad-hoc or unsigned build (local dev, CI) has none and gets `FailClosedSignatureChecker`,
so no runtime can be installed from an unsigned app.

## 6. What plugs in where

| Item | Protocol / type here | Stand-in in `InstallDependencies.live` | What the item must deliver |
|---|---|---|---|
| **S1-06** runtime install | `RuntimeInstalling` (`install(_:into:started:progress:)`, `rollback(_:in:)`), `RuntimeManifest`, `InstallPaths` | `StagingRuntimeInstaller` — copies a verified package to `runtime/.staging-<v>/`, re-hashes, renames to `runtime/<v>/`, flips `current`/`previous` atomically, creates `data/` | `packaging/manifest/runtime.json` and the bundled `Contents/Resources/runtime/` directory (`manifest.json` + `SHA256SUMS` in the shape of section 5); Caddyfile/php.ini generation with the preflight port; `chmod -R a-w`; Time Machine exclusion (A21); data-preserving upgrade |
| **S1-07** supervision | `ServiceBooting` (`boot(runtime:port:) -> started \| requiresLoginItemApproval`, `openLoginItemsSettings()`) | `UnavailableServiceBooter` (fails `BOOT_SERVICES` with an actionable error) | `SMAppService.agent(plistName:).register()`, `openSystemSettingsLoginItems()`, and the `AiSelectionApplying` implementation that hands an API key to the secrets broker |
| **S1-08** IPC / health | `HealthProbe` (`readiness() -> HealthReadiness{ready, failingChecks, aiState}`) | `UnavailableHealthProbe` (fails `HEALTH_CHECK`; `READY` is unreachable) | `GET /health/ready` with the `supervisor`-scoped token from pairing (ipc-contract §2/§3.2/§6.1); the `/health/live` part of preflight 6.8 |
| **S1-02** owner | `OwnerCreating` (`createOwner(OwnerDraft) -> OwnerHandoff`) | `UnavailableOwnerCreator` | account creation through the core, credential/passkey, the T-72 repeated-install guarantee |

`InstallDependencies.simulated` (DEBUG, `LXAI_INSTALL_SIMULATE=1`) walks the whole flow against
doubles under a temporary directory so the six screens can be exercised before those items land.

## 7. Screens

`InstallerRootView` is the window's content while the state is not `READY`. All six screens use
`DesignTokens` only: Welcome (CTA, resume notice), Check (spinner, or blockers only in EN/SV),
Install (the four automated states as a checklist with real `InstallCoreCheckpoint` substeps and
a byte-based progress bar; Cancel), Create account (name + e-mail, inline validation), Choose AI
(skip / each detected local runtime / own key with provider picker, nothing preselected), Done
("AI setup required" banner unless `ai_state == ready`, optional-connections checklist,
FileVault note, "Open"). Error, cancelled and rolled-back views carry the actions from
`InstallFailure.actions`.

Captured from the DEBUG simulation (`images/installer/`): [Welcome](images/installer/01-welcome.png),
[Install with a real checkpoint](images/installer/03-install-progress.png),
[Create account](images/installer/04-create-owner.png)
([inline validation](images/installer/04-create-owner-validation.png)),
[Choose AI](images/installer/05-choose-ai.png) ([selected](images/installer/05-choose-ai-selected.png)),
[Done with "AI setup required"](images/installer/06-done.png). Screen 2 is not pictured: with a
supported Mac it is a spinner for well under a second, and a blocker needs a Mac that fails a
check (S1-14 forces each one).

## 8. Not decided here

Whether the app flips `LSUIElement`/activation policy after `READY` (S1-09); the uninstaller
(S1-13); the per-user directory name — this item uses `<bundle id>` as the task requires, while
ADR-001 D4 writes `LaravelExecutiveAI`; one of the two must change when A12 settles.
