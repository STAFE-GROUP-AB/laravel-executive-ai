# Runtime build and assembly (S1-06)

Produces the `runtime/<version>/` directory ADR-001 D4 describes and records it in
`packaging/manifest/runtime.json`. The Mac installer (`RuntimeInstaller.swift`) copies that
directory out of `Contents/Resources/runtime/`, verifies it (`PackageVerifier.swift`) and makes it
read-only. Layout and the environment contract: [`docs/architecture/runtime-layout.md`](../../docs/architecture/runtime-layout.md).

| File | Purpose |
|---|---|
| `build-runtime.sh` | Builds the curated FrankenPHP static binary (ADR-001 D1) with pinned FrankenPHP, PHP and static-php-cli; saves `spc dump-license`, the Go module list, `otool -L` and a build report next to it |
| `assemble-runtime.mjs` | Assembles `runtime/<version>/` from a binary and a Laravel tree, writes `manifest.json` + `SHA256SUMS`, updates `runtime.json` |
| `templates/Caddyfile`, `templates/php.ini` | Copied verbatim into every runtime; static by design (no port, no path, no secret) |
| `lib/manifest.mjs` | The directory-manifest rules, identical to `PackageVerifier`: schema 1, safe paths, `sha256sum` format, no symlinks, no unlisted files |
| `test/` | `node --test 'packaging/runtime/test/*.test.mjs'` — builds a tiny runtime from a stub binary and proves the verifier's rules hold |
| `../manifest/runtime.json`, `runtime.schema.json` | The release manifest and its JSON Schema 2020-12; validated by `node packaging/fixtures/validate-schema.mjs` |

Everything under `packaging/build/` is git-ignored.

## 1. Build the binary

```sh
packaging/runtime/build-runtime.sh                       # reports missing build tools and stops
LXAI_BUILD_AUTOFIX=1 packaging/runtime/build-runtime.sh  # lets `spc doctor` brew-install them (CI runner)
```

Pins are read from `runtime.json` (`components.*`); `FRANKENPHP_TAG`, `PHP_VERSION`, `SPC_TAG`,
`PHP_EXTENSIONS`, `PHP_EXTENSION_LIBS` and `CADDY_MODULES` override them for experiments only. The
script mirrors FrankenPHP's `build-static.sh` with three differences:

1. FrankenPHP and static-php-cli are shallow-cloned at their tags and checked against the tag's
   commit; upstream clones static-php-cli `main`.
2. Mercure and Vulcain (AGPL-3.0) are never passed to xcaddy **and** the `nomercure` Go build tag
   is applied. The tag is necessary: FrankenPHP's `caddy/mercure.go` imports Mercure unless it is
   set, and the first build without it still linked `github.com/dunglas/mercure`. static-php-cli
   2.8.5 hard-codes the tag list, so the script patches the pinned source in place behind an
   exact-string guard and then checks `go version -m` for the tag.
3. The `spc dump-license` output is diffed against ADR-007 D2: any GPL/LGPL/AGPL text fails the
   build. Today that gate fires on **GNU libiconv** (spc 2.8.5 builds libxml2 against it on macOS);
   `LXAI_ALLOW_COPYLEFT_FOR_MEASUREMENT=1` produces a measurement-only binary that must not be
   signed or shipped. Resolution is an ADR-007/L-1 decision (runtime-layout.md §5).

Output in `packaging/build/runtime-bin/`: `frankenphp`, `frankenphp-version.txt`,
`frankenphp-build-info.txt`, `go-modules.txt`, `otool-L.txt`, `licenses/` (notice set for
`THIRD-PARTY-NOTICES.txt`), `build-report.json` (size, SHA-256, pins). Measured 2026-10-10 on an
M4 Max: about 12 minutes cold, 146 227 560 bytes.

## 2. Prepare the Laravel tree

From a clean checkout of the commit being released:

```sh
composer install --no-dev --optimize-autoloader --no-interaction
php artisan package:discover
```

Do **not** run `config:cache`, `route:cache` or `event:cache`: the assembler refuses those files
because they bake environment values (paths, and potentially secrets, ADR-003) into the runtime.
Do not create a `.env`. The tree is used in place; the assembler never modifies it.

## 3. Assemble the directory

```sh
node packaging/runtime/assemble-runtime.mjs \
  --binary packaging/build/runtime-bin/frankenphp \
  --app . \
  --version 0.1.0 \
  --source-commit "$(git rev-parse HEAD)"          # CI: $GITHUB_SHA
# → packaging/build/runtime/0.1.0/{frankenphp,Caddyfile,php.ini,app/,manifest.json,SHA256SUMS}
# → packaging/manifest/runtime.json: status "built", built_at, source_commit, package
```

What goes into `app/`: everything except `.git`, `.github`, `.claude`, `node_modules`, `tests`,
`storage`, `apps`, `packaging`, `docs`, `Mission` and editor directories. What aborts the build:
any `.env*`, `bootstrap/cache/{config,routes-v7,events}.php`, `*.sqlite*`, key/certificate/model
files, `auth.json`, a symlink anywhere, a dev dependency under `vendor/`, or a tree without
`artisan`, `bootstrap/app.php`, `public/index.php` and `vendor/autoload.php`. After writing, the
assembler re-reads the directory with the verifier's rules so a bug in it is caught by it.

`--status signed` is for S1-12: after `codesign` of `frankenphp` (Developer ID, hardened runtime,
`com.apple.security.cs.allow-jit` only), run the assembler again on the signed binary so
`manifest.json` hashes the signed bytes, then commit the updated `runtime.json`.

## 4. How `runtime.json` and `manifest.json` relate

`runtime.json` is the release record: component pins, licences, the signing identity, the port plan,
`built_at`, `source_commit`, and `package`. `package` **is** `manifest.json` — the same JSON object,
schema 1: `{schema_version, runtime_version, executable, files[{path, sha256, bytes}]}`. The
installer only ever reads `manifest.json` + `SHA256SUMS` from the bundled directory; `runtime.json`
is what CI validates, what the release checklist signs off, and the single source the Supervisor's
boot-time check ultimately traces back to. Never edit `package` by hand; `validate-schema.mjs`
re-applies the verifier's rules to it, and `status: unbuilt` requires it to be `null`.

## 5. Into the app bundle (S1-12)

The assembled directory is copied to `Contents/Resources/runtime/` by the signed build — a Copy
Files phase or an `xcodebuild` post-action pointing at `packaging/build/runtime/<version>/`.
`InstallDependencies.live` resolves it with `Bundle.main.url(forResource: "runtime", withExtension: nil)`
and `SystemPreflightProbes` checks the signature of `runtime/frankenphp` in preflight 6.10. Nothing
in the bundle is committed to git; CI (`swift` lane) stays green without a runtime, because the
installer fails closed at `PACKAGE_VERIFY` when the directory is missing.

## 6. Bumping the version

Any change to a pinned component, the extension list, the templates, the app code or `vendor/`
is a new runtime version (ADR-001 D4):

1. Edit `runtime.json`: bump `runtime_version` (semver), change the pins, set `status: "unbuilt"`,
   `built_at: null`, `source_commit: null`, `package: null`. A new extension or Caddy module also
   needs `docs/compliance/third-party-licenses.md` and, if its licence is not on ADR-007 D2.1, a
   legal review before it is added.
2. `node packaging/fixtures/validate-schema.mjs` — the schema and the forbidden-extension list
   (`gmp`, `gettext`, `readline`, `imagick`, …) are enforced there.
3. Build (§1), assemble (§3) with the new `--version`; the assembler refuses a version
   `runtime.json` does not pin.
4. Sign, assemble again with `--status signed`, commit `runtime.json`.

Installing the new version on a Mac that has the old one leaves the old directory as `previous`
(runtime-layout.md §1); `rollbackToPrevious` flips back without touching `data/`.
