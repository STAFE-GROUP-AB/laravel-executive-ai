# ADR-001 prototype — FrankenPHP static binary on macOS arm64

Reproduces the measurements recorded in `docs/adrs/001-macos-runtime-packaging.md` (Evidence
section). Nothing in this directory is product code; it exists so the numbers can be re-run on
another Mac (S0-22 support matrix, S1-06 runtime installer).

## Contents

| File | Purpose |
|---|---|
| `measure-cold-start.sh` | Spawns `frankenphp php-server` on `127.0.0.1:<random>`, polls until the first HTTP 200, records cold start, RSS, warm request latency and shutdown time as JSON |
| `www/index.php` | Trivial probe mirroring `/health/live` |
| `www/probe.php` | Reports loaded extensions against Laravel 13 server requirements, OPcache/JIT state, SQLite version and FTS5 availability |
| `entitlements-runtime.plist` | The single Hardened Runtime exception (`com.apple.security.cs.allow-jit`) the ADR decides on |
| `bin/` | **git-ignored.** Downloaded and re-signed binaries live here |

## Reproduce

```sh
cd packaging/fixtures/prototypes/adr-001
mkdir -p bin

# 1. Download the upstream release (v1.13.0, 2026-10-04) and verify its build provenance.
curl -sSL -o bin/frankenphp-mac-arm64 \
  https://github.com/php/frankenphp/releases/download/v1.13.0/frankenphp-mac-arm64
shasum -a 256 bin/frankenphp-mac-arm64
#   expected 99b139ca8326ad00dad412677dce6b5fceb717a9a04329d730c3cdb89205ee05
gh attestation verify bin/frankenphp-mac-arm64 --repo php/frankenphp
chmod +x bin/frankenphp-mac-arm64

# 2. Signature state as shipped (expect: adhoc, linker-signed; spctl: rejected).
codesign -dv --verbose=2 bin/frankenphp-mac-arm64
spctl --assess --type execute -v bin/frankenphp-mac-arm64

# 3. Cold start, 5 runs.
./measure-cold-start.sh bin/frankenphp-mac-arm64 5

# 4. Hardened Runtime feasibility (ad-hoc identity; a Developer ID identity changes nothing
#    about the runtime restrictions being tested).
cp bin/frankenphp-mac-arm64 bin/frankenphp-hardened-jit
codesign --force -s - --options runtime --entitlements entitlements-runtime.plist \
  --identifier dev.laravel-executive-ai.frankenphp bin/frankenphp-hardened-jit
./measure-cold-start.sh bin/frankenphp-hardened-jit 3
bin/frankenphp-hardened-jit php-cli -r 'var_dump(preg_match("/(a+)b/", str_repeat("a", 2000) . "b"));'

# 5. Extension / SQLite probe.
bin/frankenphp-mac-arm64 php-server --listen 127.0.0.1:18999 --root ./www &
curl -s http://127.0.0.1:18999/probe.php; kill %1
```

Release upstream has no checksum asset; provenance comes from the GitHub artifact attestation
(Sigstore bundle, workflow `php/frankenphp/.github/workflows/static.yaml`), which is why step 1
runs `gh attestation verify`. The SHA-256 above is what this prototype observed on 2026-10-10 and
is the value `packaging/manifest/runtime.json` would pin.
