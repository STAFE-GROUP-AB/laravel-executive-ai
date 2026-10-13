# Release build: the CI-built Mac disk image (S1-12)

> Blueprint §16 step 5 (signed internal build produced by CI), §12.1 (signed, notarized DMG), §14
> (release checklist). Status: **debug stage** — the pipeline, the unsigned image and the signed
> image are built; notarization is Sprint 4 (E4-6); the runtime package is not bundled (A23).
> Scripts and the signing procedure: [`packaging/signing/README.md`](../../packaging/signing/README.md).

## 1. What is produced, when

Workflow `.github/workflows/build-macos.yml`, job `build-macos`, on a `macos-15` runner with the
same pinned Xcode as `ci.yml`:

| Trigger | Mode | Artifact |
|---|---|---|
| pull request touching `apps/macos/**`, `packaging/signing/**` or the workflow | always **unsigned** (proves the pipeline; never uses the identity) | `LaravelExecutiveAI-<version>-<sha>-unsigned.dmg` |
| push to `main`, manual run | **signed** when `APPLE_CERTIFICATE_P12`, `APPLE_CERTIFICATE_PASSWORD` and `APPLE_TEAM_ID` are set; otherwise unsigned with a warning | `LaravelExecutiveAI-<version>-<sha>.dmg` (or `-unsigned`) |

Every run also uploads `signing-report-<mode>-<run id>` (the `codesign` / `spctl` record, §4).
Artifacts are kept **14 days**; nothing is published to GitHub Releases (release policy §3.3 —
debug builds run steps 3 and 4 of the procedure only).

`<version>` is `CFBundleShortVersionString` read from the built `Info.plist` (= `MARKETING_VERSION`
in `apps/macos/Config/Shared.xcconfig`, `0.0.0` today); `<sha>` is the first seven characters of the
commit. The job summary shows the report's header, including the one-word verdict.

Steps, in order: `xcodebuild … -configuration Release … CODE_SIGNING_ALLOWED=NO build` (one build,
never signed by Xcode) → bundle check (app,
`Contents/Helpers/LaravelExecutiveAISupervisor.app` with its executable and `Info.plist`,
`Contents/Library/LaunchAgents/se.stafe.executive-ai.supervisor.plist` must exist) →
`sign.sh app` *(signed mode)* → `make-dmg.sh` → `sign.sh dmg` *(signed mode)* → `sign.sh report`
*(always)* → upload *(always)* → delete the temporary keychain *(always)*.

## 2. Getting the image

```sh
gh run list --workflow build-macos --limit 5
gh run download <run id> --dir build/ci-artifacts        # the dmg and the signing report
```

The image contains the app, an `/Applications` link and a `README.txt` that states the signing
status and the runtime caveat. The DMG itself is signed in signed mode (verifiable with
`codesign --verify --verbose=2 <dmg>`); it is never notarized in Sprint 1.

## 3. Verifying on a clean Mac with Gatekeeper on

"Clean" per install-measurement §1: never saw the source, Gatekeeper enabled (`spctl --status` →
*assessments enabled*), no developer certificate installed. Download through a browser or `gh` so
the quarantine attribute is set like a real download.

```sh
hdiutil attach LaravelExecutiveAI-<version>-<sha>.dmg
APP="/Volumes/Laravel Executive AI/LaravelExecutiveAI.app"

codesign --verify --deep --strict --verbose=2 "$APP"           # signed: "valid on disk" + "satisfies its Designated Requirement"
codesign -dvvv "$APP" 2>&1 | grep -E 'Authority|TeamIdentifier|flags'
#   Authority=Developer ID Application: <name> (<TEAM ID>) … TeamIdentifier=<TEAM ID> … flags=0x10000(runtime)
SUPERVISOR="$APP/Contents/Helpers/LaravelExecutiveAISupervisor.app"
codesign -d --entitlements - --xml "$SUPERVISOR"
#   keychain-access-groups = [<TEAM ID>.se.stafe.executive-ai.secrets]
#   com.apple.application-identifier = <TEAM ID>.se.stafe.executive-ai.supervisor
#   the app itself has neither — it needs no profile (ADR-003 D5)
security cms -D -i "$SUPERVISOR/Contents/embedded.provisionprofile" | plutil -p - | grep -A6 Entitlements
#   the Developer ID profile that authorises both of those; without it macOS SIGKILLs the
#   Supervisor on launch (A24, verified 2026-10-11)
spctl --assess --type execute --verbose "$APP"
#   Sprint 1: "rejected  source=Unnotarized Developer ID"   ← expected until E4-6
#   Sprint 4: "accepted  source=Notarized Developer ID"
hdiutil detach "/Volumes/Laravel Executive AI"
```

What each verdict means:

| Verdict | Meaning | Acceptable in |
|---|---|---|
| `codesign --verify` passes, `TeamIdentifier=<TEAM ID>`, `flags … (runtime)` on the app and the Supervisor helper bundle, and the helper carries `Contents/embedded.provisionprofile` | Developer ID signed with the hardened runtime, and the Supervisor's restricted entitlements are authorised so it can actually launch; the bits are the ones CI built | Sprint 1 signed build — **the Sprint 1 success criterion** |
| `spctl … rejected, source=Unnotarized Developer ID` | signed but not notarized; macOS 15 will show "Apple could not verify" and require *System Settings → Privacy & Security → Open Anyway* | Sprint 1 only (internal testers) |
| `spctl … rejected`, `codesign` says *code has no resources but signature indicates they must be present* | **unsigned** CI build (the linker's ad-hoc signature, nothing sealed); will not open on a clean Mac | PR runs and `main` without secrets; developer Macs only |
| `spctl … accepted, source=Notarized Developer ID`, `xcrun stapler validate` passes | release quality | Sprint 4 gate |

Do **not** "verify" by stripping the quarantine attribute (`xattr -d com.apple.quarantine`) or by
turning Gatekeeper off: that hides exactly the failures this build exists to surface. Installing
and walking the installer is S1-14's measurement run, not this check.

## 4. What "debug-signed" means here

- The app, the embedded Supervisor helper bundle and the image carry a **Developer ID Application**
  signature from the team Andreas designates (OPEN-QUESTIONS A12), with `--options runtime`
  (Hardened Runtime) and a secure timestamp; the Supervisor is signed first — its Developer ID
  provisioning profile written into `Contents/embedded.provisionprofile`, then its own entitlements
  (`keychain-access-groups` + `com.apple.application-identifier`, Team ID prefix expanded) — and the
  app last, with hardened-runtime-only entitlements (ADR-003 D5). Order and flags:
  `packaging/signing/README.md` §1.
- It is **not notarized** and **not stapled**, so Gatekeeper rejects it with *Unnotarized Developer
  ID* and `release.json.mac_app.notarized` would be `false` (release policy §2.4) — which is why it
  never becomes a GitHub Release.
- `CFBundleVersion` is still `1`; the monotonic build number across signed builds (release policy
  §2.3) is a release-workflow concern, not this debug pipeline's.
- It is the build S1-14 installs on the lab Mac to verify launchd registration, kill/restart and
  uninstall (§16 step 5). The first one already answered A24: the bare Supervisor tool was
  SIGKILLed (exit 137) on 2026-10-11, so it is now a helper bundle with an embedded provisioning
  profile and the re-test is in `packaging/signing/README.md` §5. Those paths cannot be exercised on
  an unsigned build: the
  Supervisor's XPC and broker peer checks, `SMAppService.register()` and the data-protection
  keychain all fail closed without a Team ID.
- The report's `codesign --verify` is proof of integrity and identity, **not** proof that restricted
  entitlements are honoured: `codesign` does not check entitlements against a provisioning profile.
  Only the lab launch does.

## 5. No runtime in this image (A23)

The image is **app + Supervisor only**. `Contents/Resources/runtime/` is absent and
`packaging/manifest/runtime.json` stays `status: unbuilt, package: null`. The curated FrankenPHP
binary built by `packaging/runtime/build-runtime.sh` carries two findings the ADRs do not yet cover
([`docs/architecture/runtime-layout.md`](../architecture/runtime-layout.md) §5, OPEN-QUESTIONS
**A23**): Mercure (AGPL) is linked unless the `nomercure` Go build tag is applied (ADR-001 D1 must
name it), and GNU libiconv (LGPL-2.1) is statically linked through libxml2 (ADR-007 D2.2 forbids
that without the L-1 opinion). Until Andreas decides, no runtime may be signed or shipped. Consequence
for anyone installing this build: the installer stops at `PACKAGE_VERIFY` with a clear error and
installs nothing into `~/Library`. The `README.txt` inside the image says the same.

When A23 is decided, the steps are listed in `packaging/signing/README.md` §4 and
`packaging/runtime/README.md` §5: sign `frankenphp` (Developer ID, hardened runtime,
`com.apple.security.cs.allow-jit` only — ADR-001 D2), re-assemble with `--status signed`, copy the
directory into the bundle, commit `runtime.json`.

## 6. The notarization gap (§14)

Still missing before the §14 checklist's *Build and signing* rows can be ticked:

| Item | Status | Where |
|---|---|---|
| Apple Team ID + Developer ID certificate as CI secrets | **Andreas** — A12 | `packaging/signing/README.md` §2 |
| Runtime package signed and bundled | blocked on A23 | §5 above |
| App Store Connect API key (id, issuer, `.p8`) as CI secrets | not created | Sprint 4 |
| `xcrun notarytool submit --wait` + `xcrun stapler staple` + `spctl … accepted` gate | disabled `notarize` job in the workflow | Sprint 4, backlog row **E4-6 Release engineering and docs completeness** — no GitHub issue exists yet; open one when Sprint 4 is planned |
| A24 verdict and the ADR amendment | **done** 2026-10-11 (helper bundle + embedded profile; ADR-001 D3 amended, ADR-003 D5 confirmed) | `packaging/signing/README.md` §5 |
| `APPLE_SUPERVISOR_PROFILE` (Developer ID profile for the Supervisor bundle id) as a CI secret | set 2026-10-11 | `packaging/signing/README.md` §2 |
| `release.json`, `SHA256SUMS`, signed tag, GitHub Release | release workflow | release policy §2.4, §3.3 |
| Monotonic `CFBundleVersion` | release workflow | release policy §2.3 |

## 7. Related

- [`packaging/signing/README.md`](../../packaging/signing/README.md) — scripts, secrets, order, the A24 verdict and its re-test
- [`packaging/notarization/README.md`](../../packaging/notarization/README.md) — the Sprint 4 pipeline outline
- [`release-policy.md`](release-policy.md) §2 (versions), §3.3 (procedure), §3.5 (which signature is which), §5 (§14 checklist)
- [`install-measurement.md`](install-measurement.md) — the clean-machine definition S1-14 uses
- ADR-001 D2/D3, ADR-003 D5, ADR-007 D2 — what must be signed, by whom, with which entitlements
