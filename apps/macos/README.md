SwiftUI Mac app: installer state machine, MenuBarExtra, launchd/SMAppService supervision, IPC client, updater (blueprint §1.1, §2.1, §12).

## Layout (S0-25)

| Path | What |
|---|---|
| `LaravelExecutiveAI.xcodeproj` | App target `LaravelExecutiveAI` + XCTest target `LaravelExecutiveAITests`; one shared scheme `LaravelExecutiveAI`. Folders are Xcode synchronized groups, so new `.swift` files under `LaravelExecutiveAI/` and `LaravelExecutiveAITests/` join the targets without pbxproj edits. |
| `Config/Shared.xcconfig` | Deployment target, arch, Swift 6 / strict concurrency, versions (`MARKETING_VERSION`, `CURRENT_PROJECT_VERSION`), bundle-id prefix `LXAI_BUNDLE_PREFIX` (A12), unsigned-by-default signing. |
| `Config/App.xcconfig`, `Config/Tests.xcconfig` | Per-target settings (hardened runtime on, sandbox off, `TEST_HOST`). |
| `LaravelExecutiveAI/` | App sources and `Info.plist`. |
| `LaravelExecutiveAI.entitlements` | Hardened-runtime-only entitlements (ADR-003 D5). |
| `LaravelExecutiveAITests/` | XCTests hosted in the app. |
| `DesignTokens/` | Local SwiftPM package (S0-24), linked by both targets. |

Build and test exactly as CI does (unsigned):

```sh
xcodebuild -project apps/macos/LaravelExecutiveAI.xcodeproj -scheme LaravelExecutiveAI \
  -destination 'platform=macOS' -skipPackagePluginValidation -skipMacroValidation \
  CODE_SIGN_IDENTITY="" CODE_SIGNING_REQUIRED=NO CODE_SIGNING_ALLOWED=NO build test
```

Package-only tests: `cd apps/macos/DesignTokens && swift test`.
