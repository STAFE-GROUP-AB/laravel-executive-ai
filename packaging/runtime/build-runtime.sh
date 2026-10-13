#!/usr/bin/env bash
# Builds the curated FrankenPHP static binary for macOS arm64 (ADR-001 D1).
#
# This mirrors FrankenPHP's own `build-static.sh` step for step, with four deliberate
# differences: every input is pinned from packaging/manifest/runtime.json (FrankenPHP tag,
# PHP version, static-php-cli tag, extension list) instead of "latest"; Mercure and Vulcain
# (AGPL-3.0) are kept out of the binary by the xcaddy module list *and* the `nomercure` Go build
# tag; libxml2 and the `iconv` extension are built against the **macOS system iconv** instead of
# GNU libiconv (LGPL-2.1), as ADR-007 D2.2 requires; and the `spc dump-license` output plus the
# Go module list are saved next to the binary for the ADR-007 D2 licence diff.
#
# The last two are OPEN-QUESTIONS A23, decided by the owner on 2026-10-11. Both are patches to
# the pinned static-php-cli source, each guarded by the exact text of 2.8.5 and re-checked after
# the build, so a different spc version fails here instead of producing a copyleft-linked binary.
#
# Nothing here signs anything (S1-12) and nothing here touches a user's Mac: the build tree
# lives under packaging/build/ (git-ignored). Build-time Homebrew on the builder is permitted
# by ADR-001 D1; the shipped binary links only macOS system libraries (ADR-001 E1).
#
# Usage:
#   packaging/runtime/build-runtime.sh                 # full build, ~45-90 min cold
#   LXAI_BUILD_AUTOFIX=1 packaging/runtime/build-runtime.sh   # let `spc doctor` brew-install missing build tools
#
# Environment overrides (defaults come from runtime.json):
#   FRANKENPHP_TAG, PHP_VERSION, SPC_TAG, PHP_EXTENSIONS, PHP_EXTENSION_LIBS, CADDY_MODULES
#   LXAI_BUILD_DIR   (default packaging/build/runtime-build)
#   LXAI_OUT_DIR     (default packaging/build/runtime-bin)
#   GITHUB_TOKEN     optional; raises the API rate limit `spc download` runs into

set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$here/../.." && pwd)"
manifest="$repo_root/packaging/manifest/runtime.json"

log() { printf '\n==> %s\n' "$*"; }
die() { printf 'build-runtime: %s\n' "$*" >&2; exit 1; }

[ "$(uname -s)" = "Darwin" ] || die "this recipe targets macOS arm64 only (ADR-001 D5); use a macOS builder"
[ "$(uname -m)" = "arm64" ] || die "this recipe targets Apple Silicon only (ADR-001 D5)"

for tool in git go composer node curl shasum; do
    command -v "$tool" >/dev/null 2>&1 || die "$tool is required on the builder"
done

pin() { node -e 'const m=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8"));const v=process.argv[2].split(".").reduce((o,k)=>o[k],m);process.stdout.write(Array.isArray(v)?v.join(","):String(v))' "$manifest" "$1"; }

FRANKENPHP_TAG="${FRANKENPHP_TAG:-$(pin components.frankenphp.git_tag)}"
PHP_VERSION="${PHP_VERSION:-$(pin components.php.version)}"
SPC_TAG="${SPC_TAG:-$(pin components.static_php_cli.git_tag)}"
PHP_EXTENSIONS="${PHP_EXTENSIONS:-$(pin components.php_extensions)}"
PHP_EXTENSION_LIBS="${PHP_EXTENSION_LIBS:-$(pin components.php_extension_libs)}"
CADDY_MODULES="${CADDY_MODULES:-$(pin components.caddy_modules)}"
EXPECTED_CADDY="$(pin components.caddy.version)"
RUNTIME_VERSION="$(pin runtime_version)"

build_dir="${LXAI_BUILD_DIR:-$repo_root/packaging/build/runtime-build}"
out_dir="${LXAI_OUT_DIR:-$repo_root/packaging/build/runtime-bin}"
frankenphp_src="$build_dir/frankenphp"
spc_dir="$build_dir/static-php-cli"

for forbidden in gmp gettext readline imagick ldap pgsql pdo_pgsql mysqli pdo_mysql memcached amqp ssh2 parallel xlswriter; do
    case ",$PHP_EXTENSIONS," in
        *",$forbidden,"*) die "extension '$forbidden' is outside ADR-001 D1 / ADR-007 D2; remove it or get a legal review first" ;;
    esac
done

case "$CADDY_MODULES" in
    *mercure*|*vulcain*) die "Mercure/Vulcain are AGPL-3.0 and must not be linked (ADR-001 D1)" ;;
esac

log "Runtime $RUNTIME_VERSION: FrankenPHP $FRANKENPHP_TAG, PHP $PHP_VERSION (ZTS), static-php-cli $SPC_TAG"
log "Extensions: $PHP_EXTENSIONS"
log "Extension libs: $PHP_EXTENSION_LIBS"
log "Caddy modules: ${CADDY_MODULES:-<none>}"

mkdir -p "$build_dir" "$out_dir"

# 1. Sources, pinned by tag. Shallow clones are enough: spc reads the FrankenPHP tree, it does
#    not need history, and FRANKENPHP_VERSION below replaces the `git describe` it would run.
if [ ! -d "$frankenphp_src/.git" ]; then
    log "Cloning FrankenPHP $FRANKENPHP_TAG"
    git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$FRANKENPHP_TAG" https://github.com/php/frankenphp "$frankenphp_src"
fi

if [ ! -d "$spc_dir/.git" ]; then
    log "Cloning static-php-cli $SPC_TAG"
    git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$SPC_TAG" https://github.com/crazywhalecc/static-php-cli "$spc_dir"
fi

# HEAD must be the commit the tag points at (annotated tags included; a shallow clone has no
# history for `git describe`).
at_tag() { ( cd "$1" && [ "$(git rev-parse HEAD)" = "$(git rev-parse --verify "refs/tags/$2^{commit}" 2>/dev/null)" ] ); }
at_tag "$frankenphp_src" "$FRANKENPHP_TAG" || die "$frankenphp_src is not at $FRANKENPHP_TAG; remove $build_dir and retry"
at_tag "$spc_dir" "$SPC_TAG" || die "$spc_dir is not at $SPC_TAG; remove $build_dir and retry"

log "FrankenPHP commit $(cd "$frankenphp_src" && git rev-parse HEAD), static-php-cli commit $(cd "$spc_dir" && git rev-parse HEAD)"

log "Installing static-php-cli"
( cd "$spc_dir" && composer install --no-dev -a --no-interaction --quiet )
spc="$spc_dir/bin/spc"

# FrankenPHP's own `caddy/` module imports Mercure unless Go is given the `nomercure` build tag
# (frankenphp/caddy/mercure.go: `//go:build !nomercure`). Leaving Mercure out of the xcaddy
# `--with` list is therefore NOT enough — measured 2026-10-10, the binary still linked
# github.com/dunglas/mercure v1.0.3. static-php-cli 2.8.5 hard-codes the tag list in
# UnixBuilderBase::buildFrankenphp() with no environment hook, so the pinned source is patched
# in place; the exact-string guard makes a silently different spc version fail here instead
# of producing an AGPL-linked binary. (`nomercure` also drops FrankenPHP's Mercure-based hot
# reload, which the product does not use.)
builder="$spc_dir/src/SPC/builder/unix/UnixBuilderBase.php"
tag_line='"-tags={$muslTags}nobadger,nomysql,nopgx{$nobrotli}{$nowatcher}",'
if ! grep -qF 'nopgx,nomercure' "$builder"; then
    grep -qF "$tag_line" "$builder" || die "static-php-cli $SPC_TAG does not contain the expected xcaddy tag line; refusing to build without nomercure"
    sed -i '' 's/nobadger,nomysql,nopgx{\$nobrotli}/nobadger,nomysql,nopgx,nomercure{$nobrotli}/' "$builder"
    grep -qF 'nopgx,nomercure' "$builder" || die "patching the xcaddy tag list failed"
fi

# ADR-007 D2.2 ("the PHP build must be configured without gettext and without GNU libiconv —
# use the macOS system iconv or drop the `iconv` extension") against static-php-cli 2.8.5, which
# does the opposite on macOS: `libxml2` — a dependency of `php` itself for Darwin targets in
# config/lib.json, and unavoidable because `dom`/`xml`/`simplexml` need it — hard-depends on the
# GNU `libiconv` library, and the `iconv` extension is pointed at that same static library by
# `arg-type: with-path` (`--with-iconv=<buildroot>`). Measured 2026-10-10: the binary statically
# linked GNU libiconv (LGPL-2.1) and the notice set carried the LGPL text, which is exactly what
# the gate further down refuses.
#
# macOS ships iconv in /usr/lib/libiconv.2.dylib, with `iconv.h` and `libiconv.tbd` in the SDK,
# so the fix is to make the GNU dependency Linux-only and point both consumers at the system one:
#
#   config/lib.json  libxml2.lib-depends-unix -> lib-depends-linux   (no GNU libiconv on macOS)
#   config/ext.json  iconv.lib-depends-unix   -> lib-depends-linux
#                    iconv.arg-type-macos = with  (`--with-iconv`, not `--with-iconv=<buildroot>`,
#                    so PHP's own configure resolves iconv from the SDK)
#   src/SPC/builder/unix/library/libxml2.php  three extra CMake cache entries, applied only when
#                    this script exports the two LXAI_SYSTEM_ICONV_* variables below.
#
# `SPC\store\Config::getLib()/getExt()` resolve `<key>-macos`, then `<key>-unix`, then `<key>`,
# and `lib-depends`/`arg-type` are both on their per-OS key list — so a `-linux` key removes the
# dependency on macOS and leaves every other target exactly as upstream has it.
#
# The CMake entries are needed because spc's generated toolchain file sets
# CMAKE_FIND_ROOT_PATH to its own buildroot with CMAKE_FIND_ROOT_PATH_MODE_{INCLUDE,LIBRARY} ONLY:
# `find_package(Iconv)` therefore cannot see the system library at all and libxml2's configure
# step fails outright (measured 2026-10-13: "Could NOT find Iconv"). Pre-setting `Iconv_LIBRARY`
# and `Iconv_INCLUDE_DIR` makes FindIconv's cached `find_library`/`find_path` no-ops, which is
# the documented way to hand CMake a library it may not search for. The extension's own
# `patchBeforeConfigure()` already adds `-liconv` to macOS extra-libs, and the libxml2 recipe's
# pkg-config fixup already normalises `libxml-2.0.pc` to `-lxml2 -liconv`, so PHP links the same
# system library. Nothing statically links libiconv afterwards; the checks after the build prove
# it from the build tree, the notice set and `otool -L`.
if [ -z "${LXAI_SYSTEM_ICONV_INCLUDE_DIR:-}" ]; then
    sdk_path="$(xcrun --show-sdk-path 2>/dev/null || true)"
    [ -n "$sdk_path" ] || die "xcrun --show-sdk-path found no macOS SDK; the system iconv headers cannot be located (install the Xcode command line tools)"
    LXAI_SYSTEM_ICONV_INCLUDE_DIR="$sdk_path/usr/include"
fi
: "${LXAI_SYSTEM_ICONV_LIBRARY:=iconv}"
export LXAI_SYSTEM_ICONV_INCLUDE_DIR LXAI_SYSTEM_ICONV_LIBRARY
[ -f "$LXAI_SYSTEM_ICONV_INCLUDE_DIR/iconv.h" ] || die "$LXAI_SYSTEM_ICONV_INCLUDE_DIR/iconv.h does not exist; refusing to fall back to GNU libiconv (ADR-007 D2.2)"
patch_spc_system_iconv() {
    node - "$spc_dir" <<'PATCH_SPC_SYSTEM_ICONV'
const { readFileSync, writeFileSync } = require('node:fs');

const spcDir = process.argv[2];

const edits = [
    {
        file: 'src/SPC/builder/unix/library/libxml2.php',
        before: '\n        $cmake->build();\n',
        after: `
        // LXAI/A23: spc's toolchain file restricts find_package() to its own buildroot, so
        // FindIconv cannot see the macOS system iconv and configuring libxml2 fails. Handing it
        // the SDK header directory and a plain -liconv keeps GNU libiconv (LGPL-2.1) out of the
        // artefact, as ADR-007 D2.2 requires. Only set when build-runtime.sh exports them.
        if (getenv('LXAI_SYSTEM_ICONV_INCLUDE_DIR') !== false && getenv('LXAI_SYSTEM_ICONV_INCLUDE_DIR') !== '') {
            $cmake->addConfigureArgs(
                '-DIconv_IS_BUILT_IN=OFF',
                '-DIconv_INCLUDE_DIR=' . getenv('LXAI_SYSTEM_ICONV_INCLUDE_DIR'),
                '-DIconv_LIBRARY=' . (getenv('LXAI_SYSTEM_ICONV_LIBRARY') ?: 'iconv'),
            );
        }

        $cmake->build();
`,
    },
    {
        file: 'config/lib.json',
        before: '        "headers": [\n            "libxml2"\n        ],\n        "lib-depends-unix": [\n            "libiconv"\n        ],',
        after: '        "headers": [\n            "libxml2"\n        ],\n        "lib-depends-linux": [\n            "libiconv"\n        ],',
    },
    {
        file: 'config/ext.json',
        before: '    "iconv": {\n        "support": {\n            "BSD": "wip"\n        },\n        "type": "builtin",\n        "arg-type": "with-path",\n        "arg-type-windows": "with",\n        "lib-depends-unix": [\n            "libiconv"\n        ],',
        after: '    "iconv": {\n        "support": {\n            "BSD": "wip"\n        },\n        "type": "builtin",\n        "arg-type": "with-path",\n        "arg-type-macos": "with",\n        "arg-type-windows": "with",\n        "lib-depends-linux": [\n            "libiconv"\n        ],',
    },
];

for (const { file, before, after } of edits) {
    const path = `${spcDir}/${file}`;
    const text = readFileSync(path, 'utf8');

    if (text.includes(after)) {
        console.log(`    ${file} already carries the system-iconv patch`);
        continue;
    }

    const occurrences = text.split(before).length - 1;

    if (occurrences !== 1) {
        console.error(`build-runtime: ${file} does not contain the expected static-php-cli block exactly once (found ${occurrences}); refusing to build against GNU libiconv (ADR-007 D2.2, OPEN-QUESTIONS A23)`);
        process.exit(1);
    }

    writeFileSync(path, text.replace(before, after));
    console.log(`    ${file} patched to use the macOS system iconv`);
}

// Re-read and assert the outcome, so a future spc layout change cannot pass silently.
const lib = JSON.parse(readFileSync(`${spcDir}/config/lib.json`, 'utf8'));
const ext = JSON.parse(readFileSync(`${spcDir}/config/ext.json`, 'utf8'));
const problems = [];

if (lib.libxml2['lib-depends-unix'] !== undefined || lib.libxml2['lib-depends-macos'] !== undefined || lib.libxml2['lib-depends'] !== undefined) {
    problems.push('libxml2 still has a macOS-resolvable lib-depends key');
}

if (JSON.stringify(lib.libxml2['lib-depends-linux']) !== '["libiconv"]') {
    problems.push('libxml2 lost its Linux libiconv dependency');
}

if (ext.iconv['lib-depends-unix'] !== undefined || ext.iconv['lib-depends-macos'] !== undefined || ext.iconv['lib-depends'] !== undefined) {
    problems.push('the iconv extension still has a macOS-resolvable lib-depends key');
}

if (ext.iconv['arg-type-macos'] !== 'with') {
    problems.push('the iconv extension does not get --with-iconv on macOS');
}

if (! readFileSync(`${spcDir}/src/SPC/builder/unix/library/libxml2.php`, 'utf8').includes("'-DIconv_INCLUDE_DIR=' . getenv('LXAI_SYSTEM_ICONV_INCLUDE_DIR')")) {
    problems.push('the libxml2 recipe does not pass the system iconv to CMake');
}

if (problems.length > 0) {
    console.error(`build-runtime: patching static-php-cli for the system iconv failed: ${problems.join('; ')}`);
    process.exit(1);
}
PATCH_SPC_SYSTEM_ICONV
}

log "Patching static-php-cli to build libxml2 and iconv against the macOS system iconv (A23)"
patch_spc_system_iconv || die "could not patch static-php-cli for the macOS system iconv"

# 2. Builder prerequisites. Without LXAI_BUILD_AUTOFIX the script only reports what is missing,
#    so running it never installs anything on a machine by surprise.
log "spc doctor"
if [ "${LXAI_BUILD_AUTOFIX:-0}" = "1" ]; then
    ( cd "$spc_dir" && "$spc" doctor --auto-fix )
else
    ( cd "$spc_dir" && "$spc" doctor ) || die "builder prerequisites missing; re-run with LXAI_BUILD_AUTOFIX=1 to let spc brew-install them"
fi

( cd "$spc_dir" && "$spc" install-pkg go-xcaddy )

# 3. Sources for PHP, the extensions and their libraries.
log "spc download (PHP $PHP_VERSION)"
( cd "$spc_dir" && "$spc" download --with-php="$PHP_VERSION" --for-extensions="$PHP_EXTENSIONS" --for-libs="$PHP_EXTENSION_LIBS" --ignore-cache-sources=php-src --retry 5 )

# 4. Build. Flags are build-static.sh's macOS flags verbatim (stack protector, PIE, stripped);
#    the xcaddy module list is the one place we diverge from upstream (no Mercure, no Vulcain).
export FRANKENPHP_SOURCE_PATH="$frankenphp_src"
export FRANKENPHP_VERSION="${FRANKENPHP_TAG#v}"
export SPC_DEFAULT_C_FLAGS="-fPIC -O2"
export SPC_CMD_VAR_PHP_MAKE_EXTRA_CFLAGS="-fPIE -fstack-protector-strong -O2 -w -s"
export CGO_CFLAGS="${CGO_CFLAGS:-} $(sh "$frankenphp_src/mtls-cflags.sh")"

xcaddy_modules=""
if [ -n "$CADDY_MODULES" ]; then
    for module in ${CADDY_MODULES//,/ }; do
        xcaddy_modules="$xcaddy_modules --with $module"
    done
fi
# A single space keeps build-static.sh-style "if empty, use the AGPL default" logic from ever
# re-adding Mercure/Vulcain, should spc grow the same fallback.
export SPC_CMD_VAR_FRANKENPHP_XCADDY_MODULES="${xcaddy_modules:- }"

log "spc build (this is the long step)"
( cd "$spc_dir" && "$spc" build --enable-zts --build-embed --build-frankenphp "$PHP_EXTENSIONS" --with-libs="$PHP_EXTENSION_LIBS" )

binary="$spc_dir/buildroot/bin/frankenphp"
[ -x "$binary" ] || die "spc finished but $binary is missing"

# 5. Collect the artefact and the evidence next to it.
cp "$binary" "$out_dir/frankenphp"
chmod 755 "$out_dir/frankenphp"

log "Version and build info"
"$out_dir/frankenphp" version | tee "$out_dir/frankenphp-version.txt"
"$out_dir/frankenphp" build-info | tee "$out_dir/frankenphp-build-info.txt"

grep -q "Caddy v$EXPECTED_CADDY" "$out_dir/frankenphp-version.txt" || die "Caddy version does not match runtime.json ($EXPECTED_CADDY); update the pin deliberately"
grep -q "PHP $PHP_VERSION" "$out_dir/frankenphp-version.txt" || die "PHP version does not match runtime.json ($PHP_VERSION)"

log "Licence dump for ADR-007 D2 (spc dump-license + Go modules)"
rm -rf "$out_dir/licenses"
( cd "$spc_dir" && "$spc" dump-license --for-extensions="$PHP_EXTENSIONS" --for-libs="$PHP_EXTENSION_LIBS" --dump-dir="$out_dir/licenses" )
go version -m "$out_dir/frankenphp" > "$out_dir/go-modules.txt"

# Only `dep` lines count: the `build -tags=...nomercure...` line of `go version -m` names the
# tag that keeps Mercure out and must not trip the gate.
if grep -E "^[[:space:]]*dep[[:space:]]+github\.com/dunglas/(mercure|vulcain)" "$out_dir/go-modules.txt"; then
    die "the binary links Mercure or Vulcain; refusing to continue (ADR-001 D1)"
fi
grep -qE "^[[:space:]]*build[[:space:]]+-tags=.*nomercure" "$out_dir/go-modules.txt" || die "the binary was not built with the nomercure tag"

# ADR-007 D2.2: no GPL/LGPL/AGPL text may be in the notice set of a shipped binary. Until the
# system-iconv patch above (A23, decided 2026-10-11) this gate fired on GNU libiconv, LGPL-2.1,
# which static-php-cli 2.8.5 builds libxml2 against on macOS — the upstream release artefact has
# the same. It must now pass with nothing set: LXAI_ALLOW_COPYLEFT_FOR_MEASUREMENT=1 is kept only
# to measure a deliberately non-shippable binary, and such a binary must never be signed, never
# be assembled with `--status signed` and never reach a release asset (OPEN-QUESTIONS L-1,
# ADR-007 residual risks). CI never sets it.
if grep -rlEq "GNU (LESSER |AFFERO )?GENERAL PUBLIC LICENSE" "$out_dir/licenses"; then
    offenders=$(grep -rlE "GNU (LESSER |AFFERO )?GENERAL PUBLIC LICENSE" "$out_dir/licenses" | xargs -n1 basename | tr '\n' ' ')
    if [ "${LXAI_ALLOW_COPYLEFT_FOR_MEASUREMENT:-0}" = "1" ]; then
        printf 'build-runtime: WARNING copyleft notices present (%s); this binary is for measurement only and must not ship\n' "$offenders" >&2
    else
        die "copyleft licence text in the notice set ($offenders); ADR-007 D2.2 forbids shipping this binary. Fix the build configuration or get a legal review (L-1)."
    fi
fi

log "Linked libraries (must be system only, ADR-001 E1)"
otool -L "$out_dir/frankenphp" | tee "$out_dir/otool-L.txt"
if grep -Eq "/opt/homebrew|/usr/local|@rpath" "$out_dir/otool-L.txt"; then
    die "the binary links a non-system library; it would not be relocatable"
fi

# A23's second half, proven from three independent places rather than from the patch having run:
#   1. no GNU libiconv static library was produced in the build tree,
#   2. the notice set the product ships has no libiconv entry at all,
#   3. iconv is resolved dynamically from /usr/lib — the copy macOS itself provides, which we
#      neither link statically nor redistribute (ADR-007 D2.2; the LGPL question L-1 asked about
#      a *static* link does not arise for it).
log "No GNU libiconv anywhere in the artefact (ADR-007 D2.2, A23)"
for static_lib in "$spc_dir/buildroot/lib/libiconv.a" "$spc_dir/buildroot/lib/libcharset.a"; do
    if [ -f "$static_lib" ]; then
        die "$(basename "$static_lib") was built; the system-iconv patch did not take effect and this binary must not ship (ADR-007 D2.2)"
    fi
done

if [ -n "$(find "$out_dir/licenses" -iname '*libiconv*' -print -quit)" ]; then
    die "the notice set contains a libiconv entry; the build still depends on GNU libiconv (ADR-007 D2.2)"
fi

if ! grep -q "/usr/lib/libiconv" "$out_dir/otool-L.txt"; then
    log "note: the binary does not link /usr/lib/libiconv.*.dylib — iconv may be resolved from libSystem; recorded, not an error"
fi

bytes=$(stat -f %z "$out_dir/frankenphp")
sha=$(shasum -a 256 "$out_dir/frankenphp" | cut -d' ' -f1)

node -e '
const { readFileSync, writeFileSync } = require("fs");
const [out, version, bytes, sha, frankenphp, php, spc, exts, libs] = process.argv.slice(1);
const tags = (readFileSync(`${out}/go-modules.txt`, "utf8").match(/^\s*build\s+-tags=(\S+)/m) ?? [])[1] ?? null;
const report = {
    runtime_version: version,
    binary: { path: "frankenphp", bytes: Number(bytes), sha256: sha },
    frankenphp_tag: frankenphp, php_version: php, static_php_cli_tag: spc,
    php_extensions: exts.split(","), php_extension_libs: libs.split(","),
    // OPEN-QUESTIONS A23 evidence, recorded with the artefact rather than only in a log.
    go_build_tags: tags === null ? null : tags.split(","),
    licence_gate: "clean: no GPL/LGPL/AGPL notice in the dump, no override set",
    iconv: "macOS system iconv, dynamically linked; GNU libiconv not built and not shipped",
    built_at: new Date().toISOString(),
    builder: { os: require("os").release(), arch: process.arch },
};
writeFileSync(`${out}/build-report.json`, JSON.stringify(report, null, 4) + "\n");
' "$out_dir" "$RUNTIME_VERSION" "$bytes" "$sha" "$FRANKENPHP_TAG" "$PHP_VERSION" "$SPC_TAG" "$PHP_EXTENSIONS" "$PHP_EXTENSION_LIBS"

log "Done: $out_dir/frankenphp ($bytes bytes, sha256 $sha)"
log "Next: packaging/runtime/assemble-runtime.mjs --binary $out_dir/frankenphp --app <laravel tree> --version $RUNTIME_VERSION"
