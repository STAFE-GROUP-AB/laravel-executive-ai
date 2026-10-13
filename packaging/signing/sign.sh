#!/usr/bin/env bash
# packaging/signing/sign.sh — Developer ID signing of the Mac app bundle (S1-12, blueprint §14).
#
#   sign.sh app    <LaravelExecutiveAI.app>                      sign inside-out, hardened runtime
#   sign.sh dmg    <image.dmg>                                   sign the disk image
#   sign.sh report <LaravelExecutiveAI.app> <report.txt> [dmg]   codesign/spctl record, always written
#   sign.sh cleanup                                              delete the temporary keychain
#
# Identity (CI secrets — the values are never printed, `set -x` is never enabled):
#   APPLE_CERTIFICATE_P12        base64 of the "Developer ID Application" certificate + key (.p12)
#   APPLE_CERTIFICATE_PASSWORD   the .p12 password
#   APPLE_TEAM_ID                the 10-character Apple Team ID the certificate belongs to
#   APPLE_SUPERVISOR_PROFILE     base64 of the Developer ID provisioning profile for the Supervisor
#                                helper bundle (.provisionprofile). Required whenever the helper is
#                                signed: `keychain-access-groups` is a restricted entitlement and
#                                macOS SIGKILLs a process that carries one without a profile (A24).
#   APPLE_KEYCHAIN_PASSWORD      optional; a random one is generated when empty
#   APPLE_SIGNING_IDENTITY       optional; a SHA-1 hash or exact name when the default lookup
#                                ("Developer ID Application: … (<APPLE_TEAM_ID>)") is ambiguous
#   LXAI_SIGNING_KEYCHAIN        optional path of the temporary keychain
#                                (default: $RUNNER_TEMP/lxai-signing.keychain-db, else a mktemp dir)
#
# Without APPLE_CERTIFICATE_P12 the script looks for the identity in the default keychain search
# list (a developer Mac with the certificate installed); APPLE_TEAM_ID is still required.
#
# Fail closed: `app` and `dmg` exit 2 when no identity is configured, unless LXAI_ALLOW_UNSIGNED=1
# is set, in which case they skip and say so. `report` never signs anything and records what it
# finds; with LXAI_EXPECT_SIGNED=1 it exits 1 unless the app verifies as Developer ID signed.
#
# Order and entitlements (ADR-003 D5 table): nested frameworks first, then the Supervisor helper
# bundle at Contents/Helpers/LaravelExecutiveAISupervisor.app — its provisioning profile copied to
# Contents/embedded.provisionprofile and signed with LaravelExecutiveAISupervisor.entitlements
# (whose `$(AppIdentifierPrefix)` is expanded to "<TEAM ID>." here — codesign does not expand build
# variables, Xcode does) — then the app with LaravelExecutiveAI.entitlements. Never `--deep` for
# signing; `--deep --strict` only for verification. Details: packaging/signing/README.md.

set -euo pipefail
set +x

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
APP_ENTITLEMENTS="$REPO_ROOT/apps/macos/LaravelExecutiveAI.entitlements"
SUPERVISOR_ENTITLEMENTS="$REPO_ROOT/apps/macos/LaravelExecutiveAISupervisor.entitlements"
SHARED_XCCONFIG="$REPO_ROOT/apps/macos/Config/Shared.xcconfig"
SUPERVISOR_NAME="LaravelExecutiveAISupervisor"
# A24: the Supervisor is a helper application bundle, not a bare tool in Contents/MacOS.
SUPERVISOR_BUNDLE_PATH="Contents/Helpers/$SUPERVISOR_NAME.app"
EMBEDDED_PROFILE_PATH="Contents/embedded.provisionprofile"

: "${APPLE_CERTIFICATE_P12:=}"
: "${APPLE_CERTIFICATE_PASSWORD:=}"
: "${APPLE_SUPERVISOR_PROFILE:=}"
: "${APPLE_TEAM_ID:=}"
: "${APPLE_KEYCHAIN_PASSWORD:=}"
: "${APPLE_SIGNING_IDENTITY:=}"
: "${LXAI_ALLOW_UNSIGNED:=}"
: "${LXAI_EXPECT_SIGNED:=}"

log() { printf 'sign.sh: %s\n' "$*" >&2; }
die() { log "$*"; exit 1; }

keychain_path() {
    if [ -n "${LXAI_SIGNING_KEYCHAIN:-}" ]; then
        printf '%s' "$LXAI_SIGNING_KEYCHAIN"
    elif [ -n "${RUNNER_TEMP:-}" ]; then
        printf '%s/lxai-signing.keychain-db' "$RUNNER_TEMP"
    else
        printf '%s/lxai-signing.keychain-db' "${TMPDIR:-/tmp}"
    fi
}

# The temporary keychain `app` prepares outlives that step (it is deleted by `cleanup`), so a later
# `dmg` step that is given only APPLE_TEAM_ID — the workflow maps the .p12 into exactly one step —
# still has an identity to sign with. The first signed CI run (2026-10-11) failed here without it.
identity_configured() {
    [ -n "$APPLE_TEAM_ID" ] && { [ -n "$APPLE_CERTIFICATE_P12" ] || [ -n "$APPLE_SIGNING_IDENTITY" ] || [ -f "$(keychain_path)" ] || [ -z "${CI:-}" ]; }
}

# Exits 2 (fail closed) or 0 after printing the skip notice; the caller decides what to do next.
require_identity_or_skip() {
    if identity_configured; then
        return 0
    fi

    if [ "$LXAI_ALLOW_UNSIGNED" = 1 ]; then
        log "no signing identity configured (APPLE_TEAM_ID + APPLE_CERTIFICATE_P12 or an installed certificate); LXAI_ALLOW_UNSIGNED=1 so the $1 stays UNSIGNED."
        return 1
    fi

    log "refusing to continue: no signing identity configured. Set APPLE_TEAM_ID and APPLE_CERTIFICATE_P12 (+ APPLE_CERTIFICATE_PASSWORD), or LXAI_ALLOW_UNSIGNED=1 to produce an unsigned $1 on purpose. This script fails closed (packaging/signing/README.md §2)."
    exit 2
}

validate_team_id() {
    if ! printf '%s' "$APPLE_TEAM_ID" | grep -Eq '^[A-Z0-9]{10}$'; then
        die "APPLE_TEAM_ID must be the 10-character alphanumeric Apple Team ID."
    fi
}

random_password() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 24
    else
        head -c 24 /dev/urandom | xxd -p | tr -d '\n'
    fi
}

# Imports the .p12 into a fresh temporary keychain and prepends it to the user search list.
prepare_keychain() {
    local keychain password p12
    keychain="$(keychain_path)"
    password="${APPLE_KEYCHAIN_PASSWORD:-$(random_password)}"

    if [ -f "$keychain" ]; then
        log "temporary keychain already exists; deleting it first."
        security delete-keychain "$keychain" >/dev/null 2>&1 || true
    fi

    mkdir -p "$(dirname "$keychain")"
    security create-keychain -p "$password" "$keychain"
    security set-keychain-settings -lut 1800 "$keychain"
    security unlock-keychain -p "$password" "$keychain"

    p12="$(mktemp "${TMPDIR:-/tmp}/lxai-cert.XXXXXX")"
    chmod 600 "$p12"
    if ! printf '%s' "$APPLE_CERTIFICATE_P12" | base64 --decode > "$p12" 2>/dev/null; then
        rm -f "$p12"
        die "APPLE_CERTIFICATE_P12 is not valid base64."
    fi

    if ! security import "$p12" -k "$keychain" -P "$APPLE_CERTIFICATE_PASSWORD" -f pkcs12 -T /usr/bin/codesign -T /usr/bin/security >/dev/null 2>&1; then
        rm -f "$p12"
        security delete-keychain "$keychain" >/dev/null 2>&1 || true
        die "importing the certificate failed (wrong APPLE_CERTIFICATE_PASSWORD, or not a PKCS#12 file)."
    fi
    rm -f "$p12"

    security set-key-partition-list -S apple-tool:,apple:,codesign: -s -k "$password" "$keychain" >/dev/null 2>&1
    # shellcheck disable=SC2046
    security list-keychains -d user -s "$keychain" $(security list-keychains -d user | tr -d '" ')
    log "temporary keychain ready (certificate imported, partition list set)."
}

# Prints the SHA-1 hash of the Developer ID Application identity for APPLE_TEAM_ID.
resolve_identity() {
    # A plain string, not an array: an empty array expands to "unbound variable" under `set -u`
    # in the runner's bash 3.2. The temporary keychain is searched whenever it exists (the `dmg`
    # step runs after `app` prepared it and is not given the .p12 again).
    local scope="" listing matches
    if [ -n "$APPLE_CERTIFICATE_P12" ] || [ -f "$(keychain_path)" ]; then
        scope="$(keychain_path)"
    fi

    if [ -n "$APPLE_SIGNING_IDENTITY" ]; then
        printf '%s' "$APPLE_SIGNING_IDENTITY"
        return 0
    fi

    listing="$(security find-identity -v -p codesigning ${scope:+"$scope"} 2>/dev/null || true)"
    matches="$(printf '%s\n' "$listing" | grep -F "Developer ID Application:" | grep -F "($APPLE_TEAM_ID)" || true)"

    if [ -z "$matches" ]; then
        die "no valid 'Developer ID Application: … ($APPLE_TEAM_ID)' identity found. Developer ID (not 'Apple Development' or 'Mac App Distribution') is required for Gatekeeper outside the App Store."
    fi

    if [ "$(printf '%s\n' "$matches" | wc -l | tr -d ' ')" != 1 ]; then
        die "more than one Developer ID Application identity for team $APPLE_TEAM_ID; set APPLE_SIGNING_IDENTITY to the SHA-1 hash of the one to use."
    fi

    printf '%s\n' "$matches" | awk '{ print $2 }'
}

bundle_prefix() {
    local prefix
    prefix="$(grep -E '^LXAI_BUNDLE_PREFIX[[:space:]]*=' "$SHARED_XCCONFIG" | head -n 1 | sed -E 's/^[^=]*=[[:space:]]*//; s/[[:space:]]*$//')"
    [ -n "$prefix" ] || die "LXAI_BUNDLE_PREFIX not found in $SHARED_XCCONFIG."
    printf '%s' "$prefix"
}

# Writes APPLE_SUPERVISOR_PROFILE into <helper>.app/Contents/embedded.provisionprofile.
#
# Fail closed, deliberately without an escape hatch: a helper signed with `keychain-access-groups`
# and no profile is what the 2026-10-11 lab run proved macOS kills on sight (exit 137). Producing
# one quietly would ship a build whose Supervisor can never start. The profile is a public
# document (certificates and entitlements, no private key), but it is still never printed.
embed_supervisor_profile() {
    # Separate declarations: a single `local a=… b="$a/…"` is not reliably left-to-right under
    # `set -u` in the runner's bash.
    local helper="$1"
    local profile="$helper/$EMBEDDED_PROFILE_PATH"

    if [ -z "$APPLE_SUPERVISOR_PROFILE" ]; then
        die "refusing to sign the Supervisor helper without APPLE_SUPERVISOR_PROFILE: keychain-access-groups is a restricted entitlement and macOS SIGKILLs a process that carries it with no embedded provisioning profile (OPEN-QUESTIONS A24). Set the secret as packaging/signing/README.md §2 describes."
    fi

    mkdir -p "$(dirname "$profile")"
    if ! printf '%s' "$APPLE_SUPERVISOR_PROFILE" | base64 --decode > "$profile" 2>/dev/null; then
        rm -f "$profile"
        die "APPLE_SUPERVISOR_PROFILE is not valid base64."
    fi

    if [ ! -s "$profile" ] || ! security cms -D -i "$profile" >/dev/null 2>&1; then
        rm -f "$profile"
        die "APPLE_SUPERVISOR_PROFILE does not decode to a provisioning profile (expected a CMS blob readable by 'security cms -D')."
    fi

    chmod 644 "$profile"
    log "embedded provisioning profile written to $SUPERVISOR_BUNDLE_PATH/$EMBEDDED_PROFILE_PATH."
}

# Prints the identifying fields and the entitlements of an embedded profile — never the blob.
profile_summary() {
    local profile="$1" plist key
    [ -f "$profile" ] || { printf 'no %s in the bundle\n' "$EMBEDDED_PROFILE_PATH"; return 0; }

    plist="$(mktemp "${TMPDIR:-/tmp}/lxai-profile.XXXXXX")"
    if ! security cms -D -i "$profile" > "$plist" 2>/dev/null; then
        rm -f "$plist"
        printf 'unreadable provisioning profile\n'
        return 0
    fi

    for key in UUID Name TeamIdentifier ExpirationDate; do
        printf '%s: %s\n' "$key" "$(/usr/libexec/PlistBuddy -c "Print :$key" "$plist" 2>/dev/null | tr '\n' ' ')"
    done
    printf 'Entitlements:\n'
    plutil -extract Entitlements xml1 -o - "$plist" 2>/dev/null || printf '  (none)\n'
    rm -f "$plist"
}

sign_one() {
    # sign_one <identity> <path> [extra codesign args…]
    local identity="$1" target="$2"
    shift 2
    log "codesign $(basename "$target")"
    codesign --force --options runtime --timestamp "$@" --sign "$identity" "$target"
}

sign_app() {
    local app="$1" identity supervisor processed prefix framework
    [ -d "$app" ] || die "$app is not a directory (expected LaravelExecutiveAI.app)."
    [ -f "$app/Contents/Info.plist" ] || die "$app has no Contents/Info.plist."

    if ! require_identity_or_skip "app"; then
        return 0
    fi

    validate_team_id
    [ -f "$APP_ENTITLEMENTS" ] || die "missing $APP_ENTITLEMENTS."

    if [ -n "$APPLE_CERTIFICATE_P12" ]; then
        prepare_keychain
    fi
    identity="$(resolve_identity)"
    prefix="$(bundle_prefix)"

    # 1. Nested frameworks and dylibs (none today; DesignTokens is linked statically).
    if [ -d "$app/Contents/Frameworks" ]; then
        while IFS= read -r framework; do
            [ -n "$framework" ] || continue
            sign_one "$identity" "$framework"
        done < <(find "$app/Contents/Frameworks" -mindepth 1 -maxdepth 1 \( -name '*.framework' -o -name '*.dylib' \) | sort)
    fi

    # 2. The Supervisor helper bundle, with the ONE keychain-access-groups entitlement
    #    (ADR-003 D2/D5) and the Developer ID provisioning profile that authorises it (A24).
    supervisor="$app/$SUPERVISOR_BUNDLE_PATH"
    if [ -d "$supervisor" ]; then
        [ -f "$SUPERVISOR_ENTITLEMENTS" ] || die "the bundle embeds $SUPERVISOR_NAME but $SUPERVISOR_ENTITLEMENTS is missing."
        [ -f "$supervisor/Contents/MacOS/$SUPERVISOR_NAME" ] || die "$SUPERVISOR_BUNDLE_PATH has no Contents/MacOS/$SUPERVISOR_NAME."
        embed_supervisor_profile "$supervisor"
        processed="$(mktemp "${TMPDIR:-/tmp}/lxai-supervisor-entitlements.XXXXXX")"
        sed "s/\$(AppIdentifierPrefix)/${APPLE_TEAM_ID}./g" "$SUPERVISOR_ENTITLEMENTS" > "$processed"
        if grep -Fq '$(' "$processed"; then
            rm -f "$processed"
            die "unexpanded build variable left in the Supervisor entitlements; only \$(AppIdentifierPrefix) is supported."
        fi
        sign_one "$identity" "$supervisor" --identifier "${prefix}.supervisor" --entitlements "$processed"
        rm -f "$processed"
    else
        log "no $SUPERVISOR_BUNDLE_PATH in the bundle (S1-07 not merged into this build?); signing the app alone."
    fi

    # 3. The app itself, last.
    sign_one "$identity" "$app" --entitlements "$APP_ENTITLEMENTS"
    log "signed $(basename "$app") with the Developer ID Application identity of team $APPLE_TEAM_ID."
}

sign_dmg() {
    local dmg="$1" identity
    [ -f "$dmg" ] || die "$dmg is not a file."

    if ! require_identity_or_skip "disk image"; then
        return 0
    fi

    validate_team_id
    identity="$(resolve_identity)"
    log "codesign $(basename "$dmg")"
    codesign --force --timestamp --sign "$identity" "$dmg"
}

# Runs a command, prints its combined output and exit status, never aborts the report.
record() {
    local status=0 output
    printf '$ %s\n' "$*"
    output="$("$@" 2>&1)" || status=$?
    [ -z "$output" ] || printf '%s\n' "$output"
    printf '[exit %s]\n\n' "$status"
    return 0
}

verdict_for() {
    local target="$1" details
    details="$(codesign -dvv "$target" 2>&1 || true)"
    if printf '%s' "$details" | grep -q 'code object is not signed at all'; then
        printf 'unsigned'
    elif printf '%s' "$details" | grep -q '^Signature=adhoc'; then
        printf 'ad-hoc'
    elif printf '%s' "$details" | grep -q '^Authority=Developer ID Application'; then
        printf 'developer-id'
    elif printf '%s' "$details" | grep -q '^Authority='; then
        printf 'signed-other-authority'
    else
        printf 'unknown'
    fi
}

write_report() {
    local app="$1" report="$2" dmg="${3:-}" supervisor profile verdict verify_status=0
    [ -d "$app" ] || die "$app is not a directory (expected LaravelExecutiveAI.app)."
    mkdir -p "$(dirname "$report")"
    supervisor="$app/$SUPERVISOR_BUNDLE_PATH"
    profile="$supervisor/$EMBEDDED_PROFILE_PATH"
    verdict="$(verdict_for "$app")"
    codesign --verify --deep --strict "$app" >/dev/null 2>&1 || verify_status=$?

    {
        echo "Laravel Executive AI — signing report (S1-12)"
        echo "generated: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
        echo "bundle:    $(basename "$app")"
        echo "verdict:   $verdict (codesign --verify --deep --strict exit $verify_status)"
        if [ -d "$supervisor" ]; then
            echo "supervisor: helper bundle present, verdict $(verdict_for "$supervisor")"
            echo "profile:    $([ -f "$profile" ] && echo 'embedded.provisionprofile present' || echo 'MISSING — a restricted entitlement will be refused at launch (A24)')"
        else
            echo "supervisor: not embedded in this bundle"
            echo "profile:    n/a"
        fi
        echo "expectation: $([ "$LXAI_EXPECT_SIGNED" = 1 ] && echo 'Developer ID signed' || echo 'unsigned debug build; rejections below are expected')"
        echo "notarization: not performed (Sprint 4 gate E4-6, blueprint §14); spctl is expected to reject until then"
        echo
        echo "## codesign --verify --deep --strict --verbose=2 (app)"
        record codesign --verify --deep --strict --verbose=2 "$app"
        echo "## codesign -dvvv (app)"
        record codesign -dvvv "$app"
        echo "## entitlements (app)"
        record codesign -d --entitlements - --xml "$app"
        if [ -d "$supervisor" ]; then
            echo "## codesign --verify --deep --strict --verbose=2 (Supervisor helper bundle)"
            record codesign --verify --deep --strict --verbose=2 "$supervisor"
            echo "## codesign -dvvv (Supervisor helper bundle)"
            record codesign -dvvv "$supervisor"
            echo "## entitlements (Supervisor helper bundle)"
            record codesign -d --entitlements - --xml "$supervisor"
            # A24: the entitlements above are only honoured if the profile below authorises them.
            echo "## embedded provisioning profile (Supervisor helper bundle)"
            record profile_summary "$profile"
            echo "## helper bundle contents"
            record find "$supervisor" -maxdepth 3
        fi
        echo "## spctl --assess --type execute --verbose (app)"
        record spctl --assess --type execute --verbose "$app"
        if [ -n "$dmg" ] && [ -f "$dmg" ]; then
            echo "## codesign --verify --verbose=2 (dmg)"
            record codesign --verify --verbose=2 "$dmg"
            echo "## spctl --assess --type open --context context:primary-signature --verbose (dmg)"
            record spctl --assess --type open --context context:primary-signature --verbose "$dmg"
        fi
        echo "## bundle contents"
        record find "$app/Contents" -maxdepth 3 -not -path '*/Resources/*.lproj/*' -not -name '*.nib' -not -name '*.car'
    } > "$report"

    cat "$report"
    log "report written to $report"

    if [ "$LXAI_EXPECT_SIGNED" = 1 ]; then
        if [ "$verdict" != developer-id ] || [ "$verify_status" -ne 0 ]; then
            die "LXAI_EXPECT_SIGNED=1 but the app is '$verdict' (verify exit $verify_status). A signed build must verify."
        fi
        if [ -d "$supervisor" ]; then
            if [ "$(verdict_for "$supervisor")" != developer-id ]; then
                die "LXAI_EXPECT_SIGNED=1 but the embedded Supervisor helper bundle is not Developer ID signed."
            fi
            if [ ! -f "$profile" ]; then
                die "LXAI_EXPECT_SIGNED=1 but the Supervisor helper bundle carries no $EMBEDDED_PROFILE_PATH; macOS would SIGKILL it over keychain-access-groups (OPEN-QUESTIONS A24)."
            fi
        fi
    fi
}

cleanup() {
    local keychain
    keychain="$(keychain_path)"
    if [ -f "$keychain" ]; then
        security delete-keychain "$keychain" >/dev/null 2>&1 || log "could not delete $keychain"
        log "temporary keychain deleted."
    else
        log "no temporary keychain to delete."
    fi
}

usage() {
    sed -n '2,8p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
    exit 64
}

main() {
    local command="${1:-}"
    case "$command" in
        app)
            [ $# -eq 2 ] || usage
            sign_app "$2"
            ;;
        dmg)
            [ $# -eq 2 ] || usage
            sign_dmg "$2"
            ;;
        report)
            [ $# -ge 3 ] && [ $# -le 4 ] || usage
            write_report "$2" "$3" "${4:-}"
            ;;
        cleanup)
            [ $# -eq 1 ] || usage
            cleanup
            ;;
        *)
            usage
            ;;
    esac
}

main "$@"
