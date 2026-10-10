SwiftUI Mac app: installer state machine, MenuBarExtra, launchd/SMAppService supervision, IPC client, updater (blueprint §1.1, §2.1, §12).

## Layout (S0-25)

| Path | What |
|---|---|
| `LaravelExecutiveAI.xcodeproj` | App target `LaravelExecutiveAI` + XCTest target `LaravelExecutiveAITests`; one shared scheme `LaravelExecutiveAI`. Folders are Xcode synchronized groups, so new `.swift` files under `LaravelExecutiveAI/` and `LaravelExecutiveAITests/` join the targets without pbxproj edits. |
| `Config/Shared.xcconfig` | Deployment target, arch, Swift 6 / strict concurrency, versions (`MARKETING_VERSION`, `CURRENT_PROJECT_VERSION`), bundle-id prefix `LXAI_BUNDLE_PREFIX` (A12), unsigned-by-default signing. |
| `Config/App.xcconfig`, `Config/Tests.xcconfig` | Per-target settings (hardened runtime on, sandbox off, `TEST_HOST`). |
| `LaravelExecutiveAI/` | App sources and `Info.plist`. `App.swift` shows the installer until the install state is `READY`. |
| `LaravelExecutiveAI/Install/` | S1-05 installer (`docs/architecture/installer.md`): `InstallState` + `InstallStateMachine` (transition table, cancel/resume/rollback), `InstallCheckpoint` (JSON in the user-data dir), `InstallEventLog` (install-measurement §4 NDJSON), `Preflight` + `SystemPreflightProbes` + `PreflightMessages` (the ten S0-22 checks, EN/SV), `PackageVerifier` (manifest + `SHA256SUMS` + `SecStaticCode`, fail closed), `RuntimeInstaller` (S1-06, `docs/architecture/runtime-layout.md`: staged copy, re-hash, `chmod -R a-w`, atomic `current`/`previous` flip, rollback, garbage collection, `data/` + Logs + Caches, Time Machine exclusion; `RuntimeUninstaller`; `RuntimeEnvironment` = the S1-07 env contract), `HealthProbe` / `ServiceBooter` / `OwnerDraft` / `AiSelection` (protocols + stubs for S1-08 / S1-07 / S1-02), `InstallDependencies` (live + DEBUG simulation), `InstallerRootView` + `InstallScreens` (the six §12.3 screens). |
| `LaravelExecutiveAI.entitlements` | Hardened-runtime-only entitlements (ADR-003 D5). |
| `LaravelExecutiveAITests/` | XCTests hosted in the app; `Install/` covers the state machine, preflight, verifier, event log and checkpoint. |
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
Without the variable the live dependencies stop at `INSTALL_CORE`/`BOOT_SERVICES`/`HEALTH_CHECK`
with an actionable error until S1-06/S1-07/S1-08 land.
