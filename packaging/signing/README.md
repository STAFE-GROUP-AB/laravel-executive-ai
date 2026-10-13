# Code signing (S1-12)

Developer ID signing of the Mac app, driven by `.github/workflows/build-macos.yml`
(blueprint §16 step 5, §12.1, §14; backlog S1-12, issue #40). Credentials live only in CI secrets
(§11); this directory holds the scripts, their tests and the procedure. Operator view — how a DMG
is produced, downloaded and checked on a clean Mac: [`docs/operations/release-build.md`](../../docs/operations/release-build.md).
Notarization and stapling (Sprint 4) are described in [`../notarization/README.md`](../notarization/README.md).

| File | Purpose |
|---|---|
| `sign.sh` | `app` signs the bundle inside-out (the Supervisor helper bundle — profile embedded first — then the app); `runtime` signs the bundled FrankenPHP binary (ADR-001 D2, called by `build-runtime.yml` before the package is assembled); `dmg` signs the image; `report` writes the codesign/spctl record; `cleanup` deletes the temporary keychain. Fails closed without an identity unless `LXAI_ALLOW_UNSIGNED=1`, and always fails closed without `APPLE_SUPERVISOR_PROFILE` when a helper bundle is present. |
| `frankenphp.entitlements` | The runtime binary's entitlements: exactly `com.apple.security.cs.allow-jit` (ADR-001 D2). `sign.sh runtime` refuses the file if it ever grants a second key. |
| `make-dmg.sh` | Wraps the app, an `/Applications` link and an honest `README.txt` in a UDZO image named `LaravelExecutiveAI-<version>-<sha>[-unsigned].dmg`; the README states whether a runtime package is inside, read from the bundle. |
| `test/` | `node --test 'packaging/signing/test/*.test.mjs'` — shape tests for the workflow and both scripts (`fixtures` lane in `ci.yml`). |
| `release-keys/` | *(not yet)* public keys for `git tag -s` and the detached manifest signatures, release policy §3.5 — a different signature from the Apple one, never confused with it. |

## 1. What is signed, in which order, with which entitlements

One Release build, unsigned (`CODE_SIGNING_ALLOWED=NO`, the `Shared.xcconfig` default), then
`sign.sh app` re-signs the finished bundle so the signed and the unsigned image come from the same
bytes. Inside-out, as Apple requires — nested code before the code that contains it:

| # | Target | Identifier | Entitlements | Flags |
|---|---|---|---|---|
| 0 | `Contents/Resources/runtime/frankenphp` — **already signed** when it reaches the bundle (`sign.sh runtime`, in `build-runtime.yml`, before the package was assembled and published); `build-macos.yml` only unpacks and verifies it, and `sign.sh app` never touches it | `se.stafe.executive-ai.frankenphp` (`runtime.json#signing.identifier`) | `packaging/signing/frankenphp.entitlements` — exactly `com.apple.security.cs.allow-jit` (ADR-001 D2), nothing else, no sandbox, no keychain group | `--force --options runtime --timestamp` |
| 1 | `Contents/Frameworks/*` (none today — DesignTokens is linked statically) | inferred | none | `--force --options runtime --timestamp` |
| 2 | `Contents/Helpers/LaravelExecutiveAISupervisor.app` (the launch-agent **helper bundle**, ADR-001 D3 as amended by A24 + ADR-003 D5) — with `APPLE_SUPERVISOR_PROFILE` decoded into its `Contents/embedded.provisionprofile` **before** `codesign` runs | `<LXAI_BUNDLE_PREFIX>.supervisor` (= `PRODUCT_BUNDLE_IDENTIFIER` in `Supervisor.xcconfig`) | `apps/macos/LaravelExecutiveAISupervisor.entitlements` — `app-sandbox: false` plus the two *restricted* entitlements the profile authorises: `com.apple.application-identifier` = `$(AppIdentifierPrefix)se.stafe.executive-ai.supervisor` and the ONE `keychain-access-groups` entry `$(AppIdentifierPrefix)se.stafe.executive-ai.secrets` (ADR-003 D2/D5), with `$(AppIdentifierPrefix)` expanded to `<APPLE_TEAM_ID>.` by `sign.sh` because `codesign` does not expand build variables (Xcode does, into an `.xcent`) | same |
| 3 | `LaravelExecutiveAI.app` | `CFBundleIdentifier` (`<LXAI_BUNDLE_PREFIX>.app`) | `apps/macos/LaravelExecutiveAI.entitlements` — hardened runtime only, no sandbox, no keychain group (ADR-003 D5) | same |
| 4 | the `.dmg` (after `make-dmg.sh`) | — | — | `--force --timestamp` |

`--options runtime` = Hardened Runtime, mandatory for notarization. `--timestamp` = secure
timestamp from Apple's server (needs network; an offline local run fails here, by design).
`--force` replaces the ad-hoc signature the linker puts on every arm64 binary. **Never `--deep` to
sign** (Apple: it signs nested code with the wrong entitlements); `--deep --strict` only to verify.
The LaunchAgent plist in `Contents/Library/LaunchAgents/` is a resource sealed by step 3; the
helper's `embedded.provisionprofile` is a resource sealed by step 2.

**Why step 2 is a bundle, and why the profile is not optional** (A24, decided 2026-10-11):
`keychain-access-groups` and `com.apple.application-identifier` are *restricted* entitlements.
`codesign` applies them to anything, but macOS validates them at launch against a provisioning
profile embedded in the signed bundle — and only a bundle can carry one. The first
Developer-ID-signed build (7a74ef5, CI run 38120770614) was launched on the owner's Mac on
2026-10-11 as a bare `Contents/MacOS/LaravelExecutiveAISupervisor`: it died immediately with
**exit 137 (SIGKILL)**. So `sign.sh` refuses to sign a helper bundle without
`APPLE_SUPERVISOR_PROFILE` — there is no `LXAI_ALLOW_*` override for it, because the only thing
such an override could produce is an image whose Supervisor can never start.

Verification, always recorded in `build/signing-report.txt` (uploaded as an artifact even for an
unsigned image): `codesign --verify --deep --strict --verbose=2`, `codesign -dvvv`, the embedded
entitlements of the app and of the helper bundle, the helper's profile summary (UUID, name, team,
expiry and the entitlements it authorises — never the blob), `spctl --assess --type execute
--verbose`, and for the image `codesign --verify` plus `spctl --assess --type open --context
context:primary-signature`. The report starts with a one-word verdict: `unsigned`, `ad-hoc` (what an
unsigned CI build is — the linker's signature, no Team ID, no sealed resources), `developer-id`, and
a `profile:` line saying whether `embedded.provisionprofile` is present. With `LXAI_EXPECT_SIGNED=1`
(set by the workflow in signed mode) the report step fails unless the app and the helper bundle are
both `developer-id`, the profile is embedded, and the bundle verifies. An spctl rejection does
**not** fail Sprint 1: see §3.

## 2. CI secrets — what Andreas creates, and where

Repository → Settings → Secrets and variables → Actions → *Repository secrets* (or
`gh secret set NAME < file`). Five names, four required:

| Secret | Required | Value | How to produce it |
|---|---|---|---|
| `APPLE_TEAM_ID` | yes | the 10-character Team ID of the team that owns the app (OPEN-QUESTIONS A12: STAFE GROUP AB or personal — **undecided**) | developer.apple.com → Membership details |
| `APPLE_CERTIFICATE_P12` | yes | base64 of a PKCS#12 export of the **Developer ID Application** certificate *with its private key* | 1. developer.apple.com → Certificates → `+` → *Developer ID Application* (only the Account Holder can create one; use a fresh CSR from Keychain Access on the Mac that will hold the key). 2. Install it, then in Keychain Access select the certificate **and** its private key → *Export 2 items* → `.p12` with a strong password. 3. `base64 -i developer-id.p12 \| gh secret set APPLE_CERTIFICATE_P12`. 4. Delete the `.p12` from disk; the Keychain copy stays on that Mac only. |
| `APPLE_CERTIFICATE_PASSWORD` | yes | the password chosen at export | `gh secret set APPLE_CERTIFICATE_PASSWORD` (typed, never in shell history: use `read -s`) |
| `APPLE_SUPERVISOR_PROFILE` | yes, whenever the Supervisor is signed | base64 of the **Developer ID provisioning profile** (`.provisionprofile`) for bundle id `se.stafe.executive-ai.supervisor`, issued to the same Developer ID Application certificate. Its entitlements must contain `keychain-access-groups` = `[<TEAM ID>.*]` and `com.apple.application-identifier` = `<TEAM ID>.se.stafe.executive-ai.supervisor` | developer.apple.com → Profiles → `+` → macOS → *Developer ID* → the Supervisor bundle id + that certificate, **or** through the App Store Connect API (`POST /v1/profiles`, `profileType: MAC_APP_DIRECT`, relationships `bundleId` + `certificates`; `attributes.profileContent` is already base64). Then `base64 -i supervisor.provisionprofile \| gh secret set APPLE_SUPERVISOR_PROFILE` and delete the local file. A profile is not a credential (public certificates and entitlements, no private key) but it is still never committed and never printed. |
| `APPLE_KEYCHAIN_PASSWORD` | no | password of the temporary CI keychain | leave unset — `sign.sh` generates a random one per run |

Not "Apple Development", not "Mac App Distribution", not "Developer ID Installer": only a
*Developer ID Application* certificate satisfies Gatekeeper outside the App Store. Its expiry
(five years) is a release-checklist item (release policy §3.5), never silent.

Rules the workflow enforces:

- **Pull requests always build unsigned.** The identity is never used to validate a contribution;
  a fork cannot read the secrets anyway. The PR run proves the pipeline and nothing else.
- On `push` to `main` and `workflow_dispatch`, signing happens only when the three identity secrets
  (`APPLE_CERTIFICATE_P12`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_TEAM_ID`) are non-empty; otherwise
  an `*-unsigned.dmg` is produced with a `::warning`. Signing mode additionally **requires**
  `APPLE_SUPERVISOR_PROFILE`: it is not part of the mode decision, so a missing profile fails the
  signing step loudly instead of silently producing a build whose Supervisor macOS would kill.
- Secret values are mapped into `env:` of exactly two steps, both gated on the signing mode (the
  profile only into the app-signing step); the mode step sees only `secrets.X != ''` booleans.
  Nothing echoes them, no script enables `set -x`, GitHub masks them in logs anyway. The test suite
  in `test/` pins this shape.
- The certificate is imported into a **temporary keychain** under `$RUNNER_TEMP` (never the login
  keychain, never inside the checkout) with a 30-minute auto-lock and the `codesign` partition
  list set; an `always()` step deletes it. The `.p12` is decoded to a `0600` temp file that is
  removed right after `security import`.
- `sign.sh` fails closed: no identity → exit 2 with the reason, unless the caller says
  `LXAI_ALLOW_UNSIGNED=1` explicitly. The workflow never sets that variable; it skips the signing
  steps instead, so an unsigned image is always a deliberate mode, never a silent fallback.

Rotation or compromise: replace `APPLE_CERTIFICATE_P12` + `APPLE_CERTIFICATE_PASSWORD`, revoke the
old certificate at developer.apple.com (which invalidates nothing already notarized, but no new
build can be signed with it), note it under *Security* in the changelog.

## 3. Gatekeeper expectations by stage

| Build | `codesign --verify --deep --strict` | `spctl --assess --type execute` | What the user sees when opening the app |
|---|---|---|---|
| unsigned CI build (PR, or `main` without secrets) | fails: *code has no resources but signature indicates they must be present* (linker ad-hoc signature, nothing sealed) | rejected | "cannot be opened" / "Apple could not verify" — there is no path through System Settings for an unsigned app on macOS 15; developer Macs only |
| **Sprint 1 debug-signed** (Developer ID + hardened runtime, not notarized) | passes | **rejected**, `source=Unnotarized Developer ID` | "Apple could not verify *LaravelExecutiveAI* is free of malware"; opening requires System Settings → Privacy & Security → *Open Anyway* once (macOS 15 removed the right-click bypass). Internal testers only. |
| notarized + stapled (Sprint 4, E4-6) | passes | accepted, `source=Notarized Developer ID` | opens after the standard "downloaded from the internet" confirmation |

So in Sprint 1 the signed build's success criterion is **codesign verifies + the app and the
Supervisor helper bundle are Developer ID + the helper carries its provisioning profile + the image
mounts**; `spctl` is recorded, and expected to say *rejected* until notarization. `make-dmg.sh`
writes exactly that into the image's `README.txt`.

## 4. Not in this image, and not before notarization (§14)

- **The runtime package is in the image — but it is signed somewhere else** (OPEN-QUESTIONS **A23**,
  decided 2026-10-11; row 0 of §1). `.github/workflows/build-runtime.yml` builds the curated
  FrankenPHP binary, passes the ADR-007 D2 licence gate with no override, signs it with
  `sign.sh runtime` (Developer ID, hardened runtime, exactly `com.apple.security.cs.allow-jit`),
  assembles `runtime/<version>/` with `--status signed` so `manifest.json` hashes the signed bytes,
  and publishes the directory as a tarball on a pre-release tag. `build-macos.yml` downloads the
  asset `packaging/manifest/runtime.json#artifact` pins, checks its sha256, unpacks it into
  `Contents/Resources/runtime/` and refuses a binary signed by another team — all before step 2, so
  the app seals nested code that is already signed. While `runtime.json` has no published artifact
  the step skips with a notice and the installer stops at `PACKAGE_VERIFY`, as it did before A23.
  Details: `packaging/runtime/README.md` §5 and §7.
- **No notarization, no staple.** Needs three more secrets that do not exist yet (App Store Connect
  API key id, issuer id, the `.p8`), `xcrun notarytool submit --wait`, `xcrun stapler staple`, and an
  `spctl … accepted` gate. Backlog row *E4-6 Release engineering and docs completeness* (Sprint 4);
  there is no GitHub issue for it yet. The `notarize` job in the workflow is present and disabled
  (`if: false`) so the gap is visible where the work happens.
- **No `release.json`, `SHA256SUMS`, signed tag or GitHub Release** (release policy §2.4, §3.3):
  Sprint 1 debug builds run steps 3–4 of the release procedure only and never reach the updater.
- `CURRENT_PROJECT_VERSION` is still `1`: release policy §2.3 wants it monotonic across every
  signed build. Bumping it per CI run belongs to the release workflow, not to this debug pipeline.

## 5. A24 — decided: the Supervisor is a helper bundle with an embedded profile

**Verdict (2026-10-11, owner's Mac, macOS 15, Gatekeeper on).** The first Developer-ID-signed image
(build 7a74ef5, CI run 38120770614) was mounted and the Supervisor launched directly as a bare
`Contents/MacOS/LaravelExecutiveAISupervisor`. It terminated immediately with **exit 137
(SIGKILL)**: AMFI refuses a restricted entitlement (`keychain-access-groups`) on a Mach-O that
carries no embedded provisioning profile, and a bare Mach-O cannot carry one. **ADR-003 D5 was
right; ADR-001 D3 is amended.** What changed, in this PR:

| Before | Now |
|---|---|
| `com.apple.product-type.tool`, product `LaravelExecutiveAISupervisor` | `com.apple.product-type.application`, product `LaravelExecutiveAISupervisor.app` with its own `Info.plist` (`LSUIElement`, `LSBackgroundOnly`, `CFBundlePackageType APPL`) |
| embedded at `Contents/MacOS/` (copy phase, Executables) | embedded at `Contents/Helpers/` (copy phase, Wrapper + `Contents/Helpers`) |
| `BundleProgram` = `Contents/MacOS/LaravelExecutiveAISupervisor` | `BundleProgram` = `Contents/Helpers/LaravelExecutiveAISupervisor.app/Contents/MacOS/LaravelExecutiveAISupervisor` |
| no provisioning profile anywhere | `APPLE_SUPERVISOR_PROFILE` → `…app/Contents/embedded.provisionprofile`, written by `sign.sh` before it signs the helper; required, no override |
| entitlements: sandbox + `keychain-access-groups` | also `com.apple.application-identifier`, mirroring the profile |

Two facts from building the pipeline still hold and are worth keeping in mind:

1. `codesign` applies a restricted entitlement to anything and `codesign --verify` passes either
   way — it does not validate entitlements against a profile. A green signing report is **not**
   evidence that an entitlement will be honoured; only a launch on a real Mac is. That is exactly
   why this question could not be closed from CI.
2. The entitlement's `$(AppIdentifierPrefix)` is an Xcode-time variable, and `sign.sh` expands it to
   `<APPLE_TEAM_ID>.` itself. If the helper is ever signed by `xcodebuild` with a profile selected
   through `PROVISIONING_PROFILE_SPECIFIER`, that expansion moves back to Xcode and step 2 of §1
   shrinks to "embed the profile".

Re-test on the next signed image (S1-14 lab Mac, Gatekeeper on):

- [ ] Download the signed image, mount it, copy the app to `/Applications`.
- [ ] `codesign -d --entitlements - --xml "/Applications/LaravelExecutiveAI.app/Contents/Helpers/LaravelExecutiveAISupervisor.app"`
      shows `keychain-access-groups` = `[<TEAM ID>.se.stafe.executive-ai.secrets]` and
      `com.apple.application-identifier` = `<TEAM ID>.se.stafe.executive-ai.supervisor`.
- [ ] `security cms -D -i "/Applications/LaravelExecutiveAI.app/Contents/Helpers/LaravelExecutiveAISupervisor.app/Contents/embedded.provisionprofile" | plutil -p -`
      shows the profile's `Entitlements` covering both of those (`keychain-access-groups` =
      `[<TEAM ID>.*]`).
- [ ] Launch the helper's inner executable directly. It must now **run and exit 78** (`EX_CONFIG`,
      "the installer has not finished": nothing is installed in Application Support until the
      installer has copied the bundled runtime there) instead of being killed.
      Exit 137 again, or `log show --last 2m --predicate 'process == "amfid" OR process == "kernel"' | grep -i entitle`
      still reporting *restricted entitlements not validated*, means the profile is not being
      honoured — check the bundle id in the profile against `CFBundleIdentifier`, and that the
      profile was issued to the same certificate that signed the helper.
- [ ] Then register the agent from the installer (`BOOT_SERVICES`) and confirm the first-boot
      `APP_KEY`/`K` generation lands in the **data-protection** keychain under the access group
      (`security dump-keychain` will *not* show it; use the Supervisor's own health/diagnostic
      output, or `SecItemCopyMatching` with `kSecUseDataProtectionKeychain` + the group from a
      test). `errSecMissingEntitlement (-34018)` means the entitlement was still ignored.

## 6. Running it locally

```sh
# Unsigned, exactly what a PR run does
xcodebuild -project apps/macos/LaravelExecutiveAI.xcodeproj -scheme LaravelExecutiveAI \
  -configuration Release -destination 'platform=macOS' -derivedDataPath build/dd \
  -skipPackagePluginValidation -skipMacroValidation \
  CODE_SIGN_IDENTITY="" CODE_SIGNING_REQUIRED=NO CODE_SIGNING_ALLOWED=NO build
APP=build/dd/Build/Products/Release/LaravelExecutiveAI.app
packaging/signing/make-dmg.sh --app "$APP" --out-dir build/dist --status unsigned
packaging/signing/sign.sh report "$APP" build/signing-report.txt build/dist/*.dmg

# Signed, with the Developer ID certificate installed in your login keychain (no .p12 needed)
APPLE_TEAM_ID=<your team id> packaging/signing/sign.sh app "$APP"
DMG=$(packaging/signing/make-dmg.sh --app "$APP" --out-dir build/dist --status signed)
APPLE_TEAM_ID=<your team id> packaging/signing/sign.sh dmg "$DMG"
LXAI_EXPECT_SIGNED=1 packaging/signing/sign.sh report "$APP" build/signing-report.txt "$DMG"

# Signed, CI-style, from a .p12 (creates and deletes a temporary keychain)
export APPLE_TEAM_ID=… APPLE_CERTIFICATE_P12="$(base64 -i developer-id.p12)"; read -rs APPLE_CERTIFICATE_PASSWORD; export APPLE_CERTIFICATE_PASSWORD
packaging/signing/sign.sh app "$APP" && packaging/signing/sign.sh cleanup
```

`sign.sh` picks the single `Developer ID Application: … (<APPLE_TEAM_ID>)` identity it finds; with
more than one, set `APPLE_SIGNING_IDENTITY` to the SHA-1 hash from `security find-identity -v -p codesigning`.
Everything under `build/` and every `*.dmg` is git-ignored; `*.p12` too — never commit one.
