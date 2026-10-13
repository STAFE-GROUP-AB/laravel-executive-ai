# Notarization and stapling (§14) — Sprint 4, not started

The Sprint 4 release gate (backlog row *E4-6 Release engineering and docs completeness*; no GitHub
issue yet). Sprint 1 ships a Developer-ID-signed debug image only (S1-12,
[`../signing/README.md`](../signing/README.md), [`docs/operations/release-build.md`](../../docs/operations/release-build.md)),
which Gatekeeper rejects as *Unnotarized Developer ID* by design.

What lands here when the gate is built:

1. Three more CI secrets — App Store Connect API key id, issuer id and the `.p8` (an *App Store
   Connect* key with the Developer access role; never the Developer ID certificate's key).
2. A `notarize` job after `build-macos` (today present in `.github/workflows/build-macos.yml` with
   `if: false`): `xcrun notarytool submit <dmg> --key <p8> --key-id <id> --issuer <issuer> --wait`,
   `xcrun notarytool log` on failure, `xcrun stapler staple <dmg>`, `xcrun stapler validate`, and a
   hard gate on `spctl --assess --type open --context context:primary-signature` → `accepted`.
3. The clean-machine Gatekeeper test and the stapled image as the release asset (release policy
   §3.3 step 3–4, §5 *Build and signing*).

Preconditions, in order: A12 (identity — **decided**), A23 (the runtime package is in the bundle and
signed — **decided 2026-10-11 and implemented**: `build-runtime.yml` signs and publishes it and
`build-macos` unpacks it before signing, so a notarized image is no longer a notarized shell), the
A24 verdict (**decided**: a helper bundle with an embedded provisioning profile, in place before the
first submission). Note for the submission itself: the notarization service scans every Mach-O in
the bundle, so the ~145 MB `Contents/Resources/runtime/frankenphp` is part of the upload and the
staple must be validated on the image that contains it.
