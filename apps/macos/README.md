SwiftUI Mac app: installer state machine, MenuBarExtra, launchd/SMAppService supervision, IPC client, updater (blueprint §1.1, §2.1, §12).

## Layout (S0-25)

| Path | What |
|---|---|
| `LaravelExecutiveAI.xcodeproj` | App target `LaravelExecutiveAI` + tool target `LaravelExecutiveAISupervisor` (embedded in the app, S1-07) + XCTest target `LaravelExecutiveAITests`; one shared scheme `LaravelExecutiveAI`. Folders are Xcode synchronized groups, so new `.swift` files under `LaravelExecutiveAI/`, `LaravelExecutiveAISupervisor/` and `LaravelExecutiveAITests/` join the targets without pbxproj edits. The app target also copies `packaging/manifest/launchd/se.stafe.executive-ai.supervisor.plist` to `Contents/Library/LaunchAgents/`. |
| `Config/Shared.xcconfig` | Deployment target, arch, Swift 6 / strict concurrency, versions (`MARKETING_VERSION`, `CURRENT_PROJECT_VERSION`), bundle-id prefix `LXAI_BUNDLE_PREFIX` (A12), unsigned-by-default signing. |
| `Config/App.xcconfig`, `Config/Tests.xcconfig` | Per-target settings (hardened runtime on, sandbox off, `TEST_HOST`). |
| `LaravelExecutiveAI/` | App sources and `Info.plist`. `App.swift` shows the installer until the install state is `READY`. |
| `LaravelExecutiveAI/Install/` | S1-05 installer (`docs/architecture/installer.md`): `InstallState` + `InstallStateMachine` (transition table, cancel/resume/rollback), `InstallCheckpoint` (JSON in the user-data dir), `InstallEventLog` (install-measurement §4 NDJSON), `Preflight` + `SystemPreflightProbes` + `PreflightMessages` (the ten S0-22 checks, EN/SV), `PackageVerifier` (manifest + `SHA256SUMS` + `SecStaticCode`, fail closed), `RuntimeInstaller` (S1-06, `docs/architecture/runtime-layout.md`: staged copy, re-hash, `chmod -R a-w`, atomic `current`/`previous` flip, rollback, garbage collection, `data/` + Logs + Caches, Time Machine exclusion; `RuntimeUninstaller`; `RuntimeEnvironment` = the S1-07 env contract), `HealthProbe` / `ServiceBooter` / `OwnerDraft` / `AiSelection` (protocols + stubs for S1-08 / S1-07 / S1-02), `InstallDependencies` (live + DEBUG simulation), `InstallerRootView` + `InstallScreens` (the six §12.3 screens). |
| `LaravelExecutiveAI/MenuBar/` | S1-09 menu bar panel (`docs/architecture/menubar.md`): `MenuBarScene` + `MenuBarPanelView` (§1.1), `PanelModel` (state table, cache-only open, scheduled refresh), `BriefCache` (`~/Library/Caches/<bundle id>/menubar/latest-brief.json`, 0600), `CoreClient` protocol + `FixtureCoreClient` / `UnreachableCoreClient` (S1-08 part 2 ships the live one), `HealthDocument`, `BriefParityPayload` + `JCS` (the Swift parity port), `MenuBarCopy`, the action ports (`MenuBarPorts`). |
| `LaravelExecutiveAI/Services/` | S1-07 GUI side (`docs/architecture/services.md`): `LaunchAgentServiceBooter` (the live `ServiceBooting`), `SupervisorAiSelectionApplier` + `PendingSecretHandoff`, `XPCSupervisorControlClient` + `SupervisorControlClientFactory`, `SupervisorServiceControl` (the menu bar's `ServiceControlling`), `ServicesUninstaller` (the order S1-13 calls). |
| `LaravelExecutiveAI/IPC/` | `IpcSigning.swift` — ipc-contract §3.3/§4.2/§4.5/§7.3 token, signing-key, request/response signature and elevation arithmetic, `PairingGrant`, `ULID`, `SecureRandom`; shared with the Supervisor and the S1-08 part 2 client, proven against `packaging/fixtures/ipc/` by `IPCSigningFixtureTests`. |
| `LaravelExecutiveAISupervisor/` | The Supervisor LaunchAgent (S1-07, ADR-001 D3): a `com.apple.product-type.tool` target embedded at `Contents/MacOS/LaravelExecutiveAISupervisor`. `main.swift` is the tool's entry; every other file is also compiled into the app module (second synchronized group, `main.swift` excepted) so tests can `@testable import` it: `ServiceSupervisor`, `ChildProcess`, `RestartPolicy`, `RotatingLogFile`, `HealthMonitor` + `LoopbackHealthProbe`, `LaunchAgentRegistrar`, `KeychainStore`, `SupervisorSecrets`, `SecretsBroker`, `SupervisorControl` (+ XPC), `SupervisorRuntime`, `SupervisorIdentity`. The tool also compiles seven app files by explicit reference (`Install/{PackageVerifier,InstallPaths,RuntimeInstaller,ServiceBooter,AiSelection,HealthProbe}.swift`, `MenuBar/HealthDocument.swift`, `IPC/IpcSigning.swift`) — keep them Foundation-only. |
| `LaravelExecutiveAI.entitlements` | Hardened-runtime-only entitlements (ADR-003 D5). |
| `LaravelExecutiveAISupervisor.entitlements` | The one `keychain-access-groups` entitlement in the product (ADR-003 D2/D5); applied only when S1-12 signs. |
| `LaravelExecutiveAITests/` | XCTests hosted in the app; `Install/` covers the state machine, preflight, verifier, event log and checkpoint; `MenuBar/` covers the panel model, cache, copy, JCS and parity export (fixtures read from `packaging/fixtures/` via `#filePath`). |
| `DesignTokens/` | Local SwiftPM package (S0-24), linked by both targets. |

Build and test exactly as CI does (unsigned):

```sh
xcodebuild -project apps/macos/LaravelExecutiveAI.xcodeproj -scheme LaravelExecutiveAI \
  -destination 'platform=macOS' -skipPackagePluginValidation -skipMacroValidation \
  CODE_SIGN_IDENTITY="" CODE_SIGNING_REQUIRED=NO CODE_SIGNING_ALLOWED=NO build test
```

Package-only tests: `cd apps/macos/DesignTokens && swift test`.

Walk the six installer screens against doubles (DEBUG build, temporary directory, nothing real is
touched): `LXAI_INSTALL_SIMULATE=1 open <DerivedData>/Build/Products/Debug/LaravelExecutiveAI.app`.
Without the variable the live dependencies run `INSTALL_CORE` and `BOOT_SERVICES` for real
(S1-06, S1-07 — on an unsigned build `BOOT_SERVICES` fails closed because the Supervisor's XPC
listener accepts no unsigned peer) and stop at `HEALTH_CHECK` with an actionable error until the
S1-08 part 2 client lands. Nothing in the test suite registers a launch agent, touches the real
Keychain or opens a Mach service; the real paths are S1-14's lab run.
