# Code signing (S1-12)

Developer ID signing of the Mac app, driven by `.github/workflows/build-macos.yml`
(blueprint §16 step 5, §12.1, §14; backlog S1-12, issue #40). Credentials live only in CI secrets
(§11); this directory holds the scripts, their tests and the procedure. Operator view — how a DMG
is produced, downloaded and checked on a clean Mac: [`docs/operations/release-build.md`](../../docs/operations/release-build.md).
Notarization and stapling (Sprint 4) are described in [`../notarization/README.md`](../notarization/README.md).

| File | Purpose |
|---|---|
| `sign.sh` | `app` signs the bundle inside-out (Supervisor tool, then app); `dmg` signs the image; `report` writes the codesign/spctl record; `cleanup` deletes the temporary keychain. Fails closed without an identity unless `LXAI_ALLOW_UNSIGNED=1`. |
| `make-dmg.sh` | Wraps the app, an `/Applications` link and an honest `README.txt` in a UDZO image named `LaravelExecutiveAI-<version>-<sha>[-unsigned].dmg`. |
| `test/` | `node --test 'packaging/signing/test/*.test.mjs'` — shape tests for the workflow and both scripts (`fixtures` lane in `ci.yml`). |
| `release-keys/` | *(not yet)* public keys for `git tag -s` and the detached manifest signatures, release policy §3.5 — a different signature from the Apple one, never confused with it. |

## 1. What is signed, in which order, with which entitlements

One Release build, unsigned (`CODE_SIGNING_ALLOWED=NO`, the `Shared.xcconfig` default), then
`sign.sh app` re-signs the finished bundle so the signed and the unsigned image come from the same
bytes. Inside-out, as Apple requires — nested code before the code that contains it:

| # | Target | Identifier | Entitlements | Flags |
|---|---|---|---|---|
| 1 | `Contents/Frameworks/*` (none today — DesignTokens is linked statically) | inferred | none | `--force --options runtime --timestamp` |
| 2 | `Contents/MacOS/LaravelExecutiveAISupervisor` (the ADR-001 D3 launch agent tool) | `<LXAI_BUNDLE_PREFIX>.supervisor` (= `PRODUCT_BUNDLE_IDENTIFIER` in `Supervisor.xcconfig`) | `apps/macos/LaravelExecutiveAISupervisor.entitlements` — `app-sandbox: false`, the ONE `keychain-access-groups` entry `$(AppIdentifierPrefix)se.stafe.executive-ai.secrets` (ADR-003 D2/D5), with `$(AppIdentifierPrefix)` expanded to `<APPLE_TEAM_ID>.` by `sign.sh` because `codesign` does not expand build variables (Xcode does, into an `.xcent`) | same |
| 3 | `LaravelExecutiveAI.app` | `CFBundleIdentifier` (`<LXAI_BUNDLE_PREFIX>.app`) | `apps/macos/LaravelExecutiveAI.entitlements` — hardened runtime only, no sandbox, no keychain group (ADR-003 D5) | same |
| 4 | the `.dmg` (after `make-dmg.sh`) | — | — | `--force --timestamp` |

`--options runtime` = Hardened Runtime, mandatory for notarization. `--timestamp` = secure
timestamp from Apple's server (needs network; an offline local run fails here, by design).
`--force` replaces the ad-hoc signature the linker puts on every arm64 binary. **Never `--deep` to
sign** (Apple: it signs nested code with the wrong entitlements); `--deep --strict` only to verify.
The LaunchAgent plist in `Contents/Library/LaunchAgents/` is a resource sealed by step 3.

Verification, always recorded in `build/signing-report.txt` (uploaded as an artifact even for an
unsigned image): `codesign --verify --deep --strict --verbose=2`, `codesign -dvvv`, the embedded
entitlements of both executables, `spctl --assess --type execute --verbose`, and for the image
`codesign --verify` plus `spctl --assess --type open --context context:primary-signature`. The report
starts with a one-word verdict: `unsigned`, `ad-hoc` (what an unsigned CI build is — the linker's
signature, no Team ID, no sealed resources), `developer-id`. With `LXAI_EXPECT_SIGNED=1` (set by the
workflow in signed mode) the report step fails unless both executables are `developer-id` and the
bundle verifies. An spctl rejection does **not** fail Sprint 1: see §3.

## 2. CI secrets — what Andreas creates, and where

Repository → Settings → Secrets and variables → Actions → *Repository secrets* (or
`gh secret set NAME < file`). Four names, three required:

| Secret | Required | Value | How to produce it |
|---|---|---|---|
| `APPLE_TEAM_ID` | yes | the 10-character Team ID of the team that owns the app (OPEN-QUESTIONS A12: STAFE GROUP AB or personal — **undecided**) | developer.apple.com → Membership details |
| `APPLE_CERTIFICATE_P12` | yes | base64 of a PKCS#12 export of the **Developer ID Application** certificate *with its private key* | 1. developer.apple.com → Certificates → `+` → *Developer ID Application* (only the Account Holder can create one; use a fresh CSR from Keychain Access on the Mac that will hold the key). 2. Install it, then in Keychain Access select the certificate **and** its private key → *Export 2 items* → `.p12` with a strong password. 3. `base64 -i developer-id.p12 \| gh secret set APPLE_CERTIFICATE_P12`. 4. Delete the `.p12` from disk; the Keychain copy stays on that Mac only. |
| `APPLE_CERTIFICATE_PASSWORD` | yes | the password chosen at export | `gh secret set APPLE_CERTIFICATE_PASSWORD` (typed, never in shell history: use `read -s`) |
| `APPLE_KEYCHAIN_PASSWORD` | no | password of the temporary CI keychain | leave unset — `sign.sh` generates a random one per run |

Not "Apple Development", not "Mac App Distribution", not "Developer ID Installer": only a
*Developer ID Application* certificate satisfies Gatekeeper outside the App Store. Its expiry
(five years) is a release-checklist item (release policy §3.5), never silent.

Rules the workflow enforces:

- **Pull requests always build unsigned.** The identity is never used to validate a contribution;
  a fork cannot read the secrets anyway. The PR run proves the pipeline and nothing else.
- On `push` to `main` and `workflow_dispatch`, signing happens only when all three required
  secrets are non-empty; otherwise an `*-unsigned.dmg` is produced with a `::warning`.
- Secret values are mapped into `env:` of exactly two steps, both gated on the signing mode; the
  mode step sees only `secrets.X != ''` booleans. Nothing echoes them, no script enables `set -x`,
  GitHub masks them in logs anyway. The test suite in `test/` pins this shape.
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

So in Sprint 1 the signed build's success criterion is **codesign verifies + both executables are
Developer ID + the image mounts**; `spctl` is recorded, and expected to say *rejected* until
notarization. `make-dmg.sh` writes exactly that into the image's `README.txt`.

## 4. Not in this image, and not before notarization (§14)

- **No runtime package.** `Contents/Resources/runtime/` is absent and `packaging/manifest/runtime.json`
  stays `status: unbuilt`. The curated FrankenPHP binary cannot be shipped until OPEN-QUESTIONS
  **A23** is decided (the `nomercure` Go tag for ADR-001 D1; GNU libiconv/LGPL via libxml2 for
  ADR-007 D2.2 / L-1). When it is: build (`packaging/runtime/build-runtime.sh`), sign `frankenphp`
  with the Developer ID identity, `--options runtime` and exactly `com.apple.security.cs.allow-jit`
  (ADR-001 D2), assemble with `--status signed` so `manifest.json` hashes the signed bytes, copy to
  `Contents/Resources/runtime/`, and add the directory to step 1 of the table in §1 (every Mach-O in
  it must be signed before the app is). The installer fails closed at `PACKAGE_VERIFY` until then.
- **No notarization, no staple.** Needs three more secrets that do not exist yet (App Store Connect
  API key id, issuer id, the `.p8`), `xcrun notarytool submit --wait`, `xcrun stapler staple`, and an
  `spctl … accepted` gate. Backlog row *E4-6 Release engineering and docs completeness* (Sprint 4);
  there is no GitHub issue for it yet. The `notarize` job in the workflow is present and disabled
  (`if: false`) so the gap is visible where the work happens.
- **No `release.json`, `SHA256SUMS`, signed tag or GitHub Release** (release policy §2.4, §3.3):
  Sprint 1 debug builds run steps 3–4 of the release procedure only and never reach the updater.
- `CURRENT_PROJECT_VERSION` is still `1`: release policy §2.3 wants it monotonic across every
  signed build. Bumping it per CI run belongs to the release workflow, not to this debug pipeline.

## 5. A24 — what the first signed run must check (bare tool vs. helper bundle)

ADR-001 D3 builds the Supervisor as a bare Mach-O tool at `Contents/MacOS/`; ADR-003 D5 says the
`keychain-access-groups` entitlement is *restricted* and therefore needs a provisioning profile,
which only a bundle can embed (`Contents/embedded.provisionprofile`). This pipeline is the prototype
that decides it, and two things are already known from building it:

1. `codesign` signs a bare tool with the entitlement without complaint — it does not validate
   restricted entitlements against a profile. A green `codesign --verify` in CI is therefore **not**
   evidence that the entitlement will be honoured at runtime. Only a launch on a real Mac is.
2. The entitlement's `$(AppIdentifierPrefix)` is an Xcode-time variable. `sign.sh` expands it to
   `<APPLE_TEAM_ID>.` itself; if the Supervisor ever becomes a bundle signed by `xcodebuild` with
   a profile, that expansion moves back to Xcode and `sign.sh` step 2 goes away.

Checklist for the first run with real secrets (S1-14 lab Mac, Gatekeeper on, macOS 15):

- [ ] Download the signed image, mount it, copy the app to `/Applications`.
- [ ] `codesign -d --entitlements - --xml /Applications/LaravelExecutiveAI.app/Contents/MacOS/LaravelExecutiveAISupervisor`
      shows `keychain-access-groups` = `[<TEAM ID>.se.stafe.executive-ai.secrets]` and nothing else.
- [ ] Launch the tool directly from a terminal. **`Killed: 9`** together with
      `log show --last 2m --predicate 'process == "amfid" OR process == "kernel"' | grep -i entitle`
      reporting *restricted entitlements not validated* means AMFI refuses a bare tool with a
      restricted entitlement → **ADR-003 D5 is right**: change the Supervisor target's product type
      to an application bundle (`Contents/Helpers/LaravelExecutiveAISupervisor.app`), embed a
      *Developer ID* provisioning profile (developer.apple.com → Profiles → macOS → Developer ID;
      these carry `keychain-access-groups: [<TEAM ID>.*]`), point the agent plist's `BundleProgram`
      at `…app/Contents/MacOS/LaravelExecutiveAISupervisor`, and amend **ADR-001 D3**.
- [ ] If it launches: register the agent from the installer (`BOOT_SERVICES`), then confirm the
      first-boot `APP_KEY`/`K` generation lands in the **data-protection** keychain under the access
      group (`security dump-keychain` will *not* show it; use the Supervisor's own health/diagnostic
      output, or `SecItemCopyMatching` with `kSecUseDataProtectionKeychain` + the group from a
      test). `errSecMissingEntitlement (-34018)` means the entitlement was ignored → same fix as
      above. Success → **ADR-003 D5 is amended** to say a bare tool is sufficient on macOS, and the
      "must be packaged as a bundle" sentence goes.
- [ ] Either way: record the verdict under A24 in `docs/OPEN-QUESTIONS.md` and in
      `docs/architecture/services.md` §1 in the same PR as the ADR amendment.

Until then A24 stays open; the pipeline is structurally correct for both outcomes (a bundle is
"nested code" and slots into step 1 of §1 with its own entitlements file).

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
