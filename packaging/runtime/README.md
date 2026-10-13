# Runtime build and assembly (S1-06)

Produces the `runtime/<version>/` directory ADR-001 D4 describes and records it in
`packaging/manifest/runtime.json`. The Mac installer (`RuntimeInstaller.swift`) copies that
directory out of `Contents/Resources/runtime/`, verifies it (`PackageVerifier.swift`) and makes it
read-only. Layout and the environment contract: [`docs/architecture/runtime-layout.md`](../../docs/architecture/runtime-layout.md).

| File | Purpose |
|---|---|
| `build-runtime.sh` | Builds the curated FrankenPHP static binary (ADR-001 D1) with pinned FrankenPHP, PHP and static-php-cli; saves `spc dump-license`, the Go module list, `otool -L` and a build report next to it |
| `assemble-runtime.mjs` | Assembles `runtime/<version>/` from a binary and a Laravel tree, writes `manifest.json` + `SHA256SUMS`, updates `runtime.json`; also `--record-artifact` (where the package was published) and `--verify <dir>` (the installer's rules on an existing directory) |
| `../../.github/workflows/build-runtime.yml` | The dispatch-only workflow that does §1–§5 in CI, signs the binary and publishes the package as a pre-release asset (§7) |
| `../signing/frankenphp.entitlements` | The one entitlement the runtime binary carries, `com.apple.security.cs.allow-jit` (ADR-001 D2) |
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
script mirrors FrankenPHP's `build-static.sh` with four differences:

1. FrankenPHP and static-php-cli are shallow-cloned at their tags and checked against the tag's
   commit; upstream clones static-php-cli `main`.
2. Mercure and Vulcain (AGPL-3.0) are never passed to xcaddy **and** the `nomercure` Go build tag
   is applied. The tag is necessary: FrankenPHP's `caddy/mercure.go` imports Mercure unless it is
   set, and the first build without it still linked `github.com/dunglas/mercure`. static-php-cli
   2.8.5 hard-codes the tag list, so the script patches the pinned source in place behind an
   exact-string guard and then checks `go version -m` for the tag.
3. **libxml2 and `iconv` are built against the macOS system iconv**, never GNU libiconv (LGPL-2.1).
   ADR-007 D2.2 requires it and OPEN-QUESTIONS **A23** decided it on 2026-10-11. Three guarded
   edits to the pinned spc source, all re-checked before the build starts:

   | File | Edit | Why |
   |---|---|---|
   | `config/lib.json` | `libxml2.lib-depends-unix` → `lib-depends-linux` | `Config::getLib()` resolves `-macos`, `-unix`, then the bare key, so a Linux-only key drops the GNU dependency on macOS and changes nothing elsewhere. libxml2 cannot be dropped: `php` depends on it for Darwin targets and `dom`/`xml`/`simplexml` need it |
   | `config/ext.json` | `iconv.lib-depends-unix` → `lib-depends-linux`, plus `arg-type-macos: with` | PHP then configures `--with-iconv` instead of `--with-iconv=<buildroot>`, i.e. the SDK's iconv instead of the GNU static library |
   | `src/SPC/builder/unix/library/libxml2.php` | pass `Iconv_IS_BUILT_IN=OFF`, `Iconv_INCLUDE_DIR`, `Iconv_LIBRARY` to CMake when `LXAI_SYSTEM_ICONV_*` are exported | spc's generated toolchain file sets `CMAKE_FIND_ROOT_PATH_MODE_{INCLUDE,LIBRARY} ONLY` against its own buildroot, so `find_package(Iconv)` cannot see a system library and configuring libxml2 fails with "Could NOT find Iconv" |

   The script exports `LXAI_SYSTEM_ICONV_INCLUDE_DIR` from `xcrun --show-sdk-path` (override it to
   build against a different SDK) and refuses to continue if that header is missing, rather than
   silently falling back to GNU libiconv.
4. The `spc dump-license` output is diffed against ADR-007 D2: any GPL/LGPL/AGPL text fails the
   build, and the build additionally fails if `libiconv.a`/`libcharset.a` was produced or a
   libiconv notice appears. Since the fix in (3) the gate **passes with nothing set**;
   `LXAI_ALLOW_COPYLEFT_FOR_MEASUREMENT=1` only exists to measure a deliberately non-shippable
   binary, which must never be signed, assembled `--status signed` or published. CI never sets it.

Output in `packaging/build/runtime-bin/`: `frankenphp`, `frankenphp-version.txt`,
`frankenphp-build-info.txt`, `go-modules.txt`, `otool-L.txt`, `licenses/` (notice set for
`THIRD-PARTY-NOTICES.txt`), `build-report.json` (size, SHA-256, pins, Go build tags, the licence-gate
and iconv verdicts). Measured 2026-10-13 on an M4 Max: about 16 minutes cold, **145 073 048 bytes**,
sha256 `eaf81d68af7969382a637155bad52db4fa6c2c96f5fe9f61faafc8f72954da40` (runtime-layout.md §5).

## 2. Prepare the Laravel tree

From a clean checkout of the commit being released:

```sh
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build        # public/build/** is git-ignored; without it the PWA ships with no CSS or JS
php artisan package:discover
```

Do **not** run `config:cache`, `route:cache` or `event:cache`: the assembler refuses those files
because they bake environment values (paths, and potentially secrets, ADR-003) into the runtime.
Do not create a `.env`. The tree is used in place; the assembler never modifies it (`node_modules`
is excluded whether or not it is still there).

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
`storage`, `apps`, `packaging`, `docs`, `Mission`, the root `.env.example` and editor directories.
What aborts the build: any other `.env*`, `bootstrap/cache/{config,routes-v7,events}.php`,
`*.sqlite*`, key/certificate/model files, `auth.json`, a symlink anywhere (so never run
`php artisan storage:link` on a release tree), a dev dependency under `vendor/`, or a tree without
`artisan`, `bootstrap/app.php`, `public/index.php` and `vendor/autoload.php`. After writing, the
assembler re-reads the directory with the verifier's rules so a bug in it is caught by it.

`--status signed` is the S1-12 order: `codesign` the binary first (`packaging/signing/sign.sh
runtime <binary>` — Developer ID, hardened runtime, `com.apple.security.cs.allow-jit` only), then
assemble, so `manifest.json` and `SHA256SUMS` hash the **signed** bytes. Assembling first and
signing afterwards would make every digest wrong.

`--verify <directory>` re-applies the `PackageVerifier` rules (everything but the code signature) to
a directory that already exists — used on the unpacked tarball in `build-runtime.yml` and on
`Contents/Resources/runtime/` in `build-macos.yml`.

## 4. How `runtime.json`, `manifest.json` and the published asset relate

`runtime.json` is the release record: component pins, licences, the signing identity, the port plan,
`built_at`, `source_commit`, `package` and `artifact`. `package` **is** `manifest.json` — the same
JSON object, schema 1: `{schema_version, runtime_version, executable, files[{path, sha256, bytes}]}`.
`artifact` is `{tag, asset, url, sha256, bytes}`: where that exact directory is published, which is
what `build-macos.yml` downloads and checks. The installer only ever reads `manifest.json` +
`SHA256SUMS` from the bundled directory; `runtime.json` is what CI validates, what the release
checklist signs off, and the single source the Supervisor's boot-time check ultimately traces back
to.

Never edit either member by hand. `validate-schema.mjs` re-applies the verifier's rules to `package`
and refuses an `artifact` that is not a release-asset URL matching its own tag and asset, or one on
a document that is not `status: signed` with a package. An assembly **clears** `artifact`, because a
re-assembled directory is not the one behind the published tarball; `--record-artifact` sets it
again after publishing.

| `status` | `package` | `artifact` | What it means |
|---|---|---|---|
| `unbuilt` | `null` | `null` | nothing built for this version; `build-macos` bundles no runtime and the installer stops at `PACKAGE_VERIFY` |
| `built` | manifest | `null` | a local, unsigned build — proof only, never shipped |
| `signed` | manifest | `null` | signed and assembled, not yet published (the state between two steps of `build-runtime.yml`) |
| `signed` | manifest | asset | the shipping state: `build-macos` downloads, verifies and bundles it |

## 5. Into the app bundle (S1-12)

The package reaches `Contents/Resources/runtime/` in CI, from the release asset `artifact` pins —
not from a developer's build tree, and not from git (nothing under `packaging/build/` is committed):

```sh
gh workflow run build-runtime.yml --ref <branch> -f revision=1    # §7: build, sign, publish
gh release download <tag> --pattern runtime.json \
  --output packaging/manifest/runtime.json --clobber               # then commit it
```

`build-macos.yml` reads `runtime.json`, downloads `artifact.url`, checks `artifact.sha256` and the
byte count, unpacks the tarball (`--strip-components=1`) into `Contents/Resources/runtime/`,
re-verifies it with `assemble-runtime.mjs --verify`, confirms `frankenphp` is signed with
`allow-jit` and — in signed mode — that its Team ID is the app's. All of that happens **before**
`sign.sh app`, so the app seals an already-signed runtime; `sign.sh app` never signs anything inside
the directory. `InstallDependencies.live` then resolves it with
`Bundle.main.url(forResource: "runtime", withExtension: nil)` and `SystemPreflightProbes` checks the
signature of `runtime/frankenphp` in preflight 6.10. The `swift` lane stays green without a runtime,
because the installer fails closed at `PACKAGE_VERIFY` when the directory is missing.

## 6. Bumping the version

Any change to a pinned component, the extension list, the templates, the app code or `vendor/`
is a new runtime version (ADR-001 D4):

1. Edit `runtime.json`: bump `runtime_version` (semver), change the pins, set `status: "unbuilt"`,
   `built_at: null`, `source_commit: null`, `package: null`, `artifact: null`. A new extension or
   Caddy module also needs `docs/compliance/third-party-licenses.md` and, if its licence is not on
   ADR-007 D2.1, a legal review before it is added.
2. `node packaging/fixtures/validate-schema.mjs` — the schema and the forbidden-extension list
   (`gmp`, `gettext`, `readline`, `imagick`, …) are enforced there.
3. Run `build-runtime.yml` (§7). It builds (§1), prepares the tree (§2), signs, assembles (§3) with
   the pinned version, publishes, and attaches the `runtime.json` to commit. The assembler refuses a
   version `runtime.json` does not pin.
4. Commit that `runtime.json` (`status: signed` with `package` and `artifact`) in the pull request
   that bumps the version, so the manifest and the published asset are reviewed together.

Installing the new version on a Mac that has the old one leaves the old directory as `previous`
(runtime-layout.md §1); `rollbackToPrevious` flips back without touching `data/`.

## 7. The release workflow (`.github/workflows/build-runtime.yml`)

Dispatch only, `macos-15`, three-hour ceiling (about 16 minutes in practice). There is no
`pull_request` trigger on purpose: a PR must never use the signing identity
(`packaging/signing/README.md` §2), and an unsigned package is useless — `PackageVerifier` checks
the binary against the app's own Team ID and fails closed. A PR instead proves the workflow's shape
through `test/build-runtime-workflow.test.mjs` in the `fixtures` lane.

```sh
gh workflow run build-runtime.yml --ref feat/my-branch -f revision=1
gh run watch "$(gh run list --workflow build-runtime.yml --limit 1 --json databaseId --jq '.[0].databaseId')"
```

| Input | Meaning |
|---|---|
| `revision` | the `<n>` in `runtime-<php>-<frankenphp>-<n>`; bump it to rebuild the same component pins. A tag that already exists is refused, never overwritten |
| `publish` | off = build, gate, sign and pack only (the tarball is still a workflow artifact); on = also create the pre-release and record the `artifact` member |

What it does, in order: refuse to start without the three signing secrets → read the pins → build
(§1, `LXAI_BUILD_AUTOFIX=1` so `spc doctor` may install build tooling **on the runner**; ADR-001 D1
permits that, §12.1 forbids it on a user's Mac) → summarise the licence gate into the job summary →
`sign.sh runtime` → prepare the Laravel tree (§2) → assemble `--status signed` (§3) → tar with
`--no-mac-metadata`, hash it, unpack it again and verify the copy with the installer's rules and
`codesign --verify` → `--record-artifact` → `gh release create --prerelease` with the tarball,
`SHA256SUMS`, the `runtime.json` to commit, the build report, the version string, the Go module list
and `otool -L` → upload the same plus the notice set as workflow artifacts (90 days) → delete the
temporary keychain. The certificate reaches exactly one step; nothing echoes a secret.

The release is a **pre-release** and is not a product release: it carries one component of one, and
`docs/operations/release-policy.md` §2.4 still governs product releases.
