#!/usr/bin/env bash
# packaging/signing/make-dmg.sh — wraps a built LaravelExecutiveAI.app in a compressed disk image
# (S1-12, blueprint §12.1 "signed, notarized DMG"; Sprint 1 ships the debug stage of it).
#
#   make-dmg.sh --app <LaravelExecutiveAI.app> --out-dir <dir> [options]
#
#   --status unsigned|signed   what the README.txt inside the image says (default: unsigned)
#   --sha <short sha>          build identifier in the file name (default: git rev-parse --short)
#   --version <X.Y.Z>          override CFBundleShortVersionString (default: read from Info.plist)
#   --volname <name>           volume name (default: "Laravel Executive AI")
#   --stage-only               build the staging folder and stop before hdiutil (tests, Linux)
#
# Output: <out-dir>/LaravelExecutiveAI-<version>-<sha>[-unsigned].dmg (UDZO), the staging folder
# next to it, and `dmg_path=` / `dmg_name=` lines appended to $GITHUB_OUTPUT when CI sets it.
# The image holds the app, an /Applications symlink and a README.txt that states the signing
# status and that no runtime package is bundled yet (OPEN-QUESTIONS A23). Nothing here signs
# anything: sign.sh signs the app before this script runs and the image after it.

set -euo pipefail
set +x

APP=''
OUT_DIR=''
STATUS='unsigned'
SHA=''
VERSION=''
VOLNAME='Laravel Executive AI'
STAGE_ONLY=0

log() { printf 'make-dmg.sh: %s\n' "$*" >&2; }
die() { log "$*"; exit 1; }

usage() {
    sed -n '2,17p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
    exit 64
}

while [ $# -gt 0 ]; do
    case "$1" in
        --app) APP="${2:-}"; shift 2 ;;
        --out-dir) OUT_DIR="${2:-}"; shift 2 ;;
        --status) STATUS="${2:-}"; shift 2 ;;
        --sha) SHA="${2:-}"; shift 2 ;;
        --version) VERSION="${2:-}"; shift 2 ;;
        --volname) VOLNAME="${2:-}"; shift 2 ;;
        --stage-only) STAGE_ONLY=1; shift ;;
        -h|--help) usage ;;
        *) log "unknown argument: $1"; usage ;;
    esac
done

[ -n "$APP" ] && [ -n "$OUT_DIR" ] || usage
[ -d "$APP" ] || die "--app $APP is not a directory."
[ -f "$APP/Contents/Info.plist" ] || die "--app $APP has no Contents/Info.plist."
case "$STATUS" in unsigned|signed) ;; *) die "--status must be unsigned or signed." ;; esac

APP_NAME="$(basename "$APP")"
PRODUCT="${APP_NAME%.app}"

if [ -z "$VERSION" ]; then
    command -v /usr/libexec/PlistBuddy >/dev/null 2>&1 || die "PlistBuddy not available; pass --version."
    VERSION="$(/usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$APP/Contents/Info.plist")"
fi
printf '%s' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$' || die "version '$VERSION' is not MAJOR.MINOR.PATCH (release policy §2.1)."

if [ -z "$SHA" ]; then
    SHA="$(git -C "$(dirname "$APP")" rev-parse --short=7 HEAD 2>/dev/null || true)"
    [ -n "$SHA" ] || die "not inside a git checkout; pass --sha."
fi
printf '%s' "$SHA" | grep -Eq '^[0-9a-f]{7,40}$' || die "--sha '$SHA' is not a hex commit id."

SUFFIX=''
[ "$STATUS" = unsigned ] && SUFFIX='-unsigned'
DMG_NAME="${PRODUCT}-${VERSION}-${SHA}${SUFFIX}.dmg"
DMG_PATH="$OUT_DIR/$DMG_NAME"
STAGING="$OUT_DIR/dmg-staging"

mkdir -p "$OUT_DIR"
rm -rf "$STAGING" "$DMG_PATH"
mkdir -p "$STAGING"

if command -v ditto >/dev/null 2>&1; then
    ditto "$APP" "$STAGING/$APP_NAME"
else
    cp -R "$APP" "$STAGING/$APP_NAME"
fi
ln -s /Applications "$STAGING/Applications"

if [ "$STATUS" = unsigned ]; then
    STATUS_TEXT="UNSIGNED debug build. It carries no Developer ID signature and is not notarized.
  macOS Gatekeeper will refuse to open it from Finder. It is meant for the pipeline check
  and for developer Macs only; do not distribute it."
else
    STATUS_TEXT="DEBUG build, Developer ID signed with the hardened runtime, NOT notarized.
  Gatekeeper on macOS 15+ shows \"Apple could not verify …\" until notarization and stapling
  land as the Sprint 4 release gate (blueprint §14, backlog E4-6). Internal use only."
fi

cat > "$STAGING/README.txt" <<EOF
Laravel Executive AI — ${PRODUCT} ${VERSION} (build ${SHA})

Status: ${STATUS_TEXT}

Contents: ${APP_NAME} (the Mac app with the embedded Supervisor launch agent) and a link to
/Applications. Drag the app to Applications to install.

Runtime: this image contains NO PHP runtime package. The curated FrankenPHP binary cannot be
shipped until two licence findings are decided (docs/OPEN-QUESTIONS.md A23: the "nomercure" Go
build tag and GNU libiconv/LGPL via libxml2). The installer therefore stops at PACKAGE_VERIFY
with a clear error. Nothing is installed into ~/Library by opening this image.

How it was built, how to verify the signature and what is still missing before a release:
docs/operations/release-build.md in https://github.com/STAFE-GROUP-AB/laravel-executive-ai

This is not a release. Releases are tagged, signed, notarized and listed under GitHub Releases.
EOF

log "staged $APP_NAME, Applications link and README.txt in $STAGING"

if [ "$STAGE_ONLY" = 1 ]; then
    log "--stage-only: not creating $DMG_NAME"
    printf '%s\n' "$STAGING"
    exit 0
fi

command -v hdiutil >/dev/null 2>&1 || die "hdiutil not available (macOS only)."

# hdiutil occasionally fails with "Resource busy" on busy CI runners; three attempts.
attempt=1
until hdiutil create -volname "$VOLNAME" -srcfolder "$STAGING" -ov -format UDZO -fs HFS+ -imagekey zlib-level=9 "$DMG_PATH" >/dev/null; do
    if [ "$attempt" -ge 3 ]; then
        die "hdiutil create failed after $attempt attempts."
    fi
    attempt=$((attempt + 1))
    log "hdiutil create failed; retrying ($attempt/3) in 5 s"
    sleep 5
done

hdiutil verify "$DMG_PATH" >/dev/null
size="$(stat -f %z "$DMG_PATH" 2>/dev/null || stat -c %s "$DMG_PATH")"
log "created $DMG_NAME ($size bytes)"

if [ -n "${GITHUB_OUTPUT:-}" ]; then
    {
        echo "dmg_path=$DMG_PATH"
        echo "dmg_name=$DMG_NAME"
    } >> "$GITHUB_OUTPUT"
fi

printf '%s\n' "$DMG_PATH"
