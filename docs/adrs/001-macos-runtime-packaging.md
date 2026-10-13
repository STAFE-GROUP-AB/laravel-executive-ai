# ADR-001: Bundle an own-built FrankenPHP static binary (PHP + Caddy) as the macOS runtime, supervised by one SMAppService launch agent

| | |
|---|---|
| **Status** | Proposed — awaiting DRI (Andreas) sign-off and the Sprint 1 clean-Mac prototype (S1-06) |
| **Date** | 2026-10-10 |
| **Amended** | 2026-10-11 (A24, delegated to ENG): **D3**'s container only — the Supervisor is a helper application bundle at `Contents/Helpers/LaravelExecutiveAISupervisor.app` with an embedded Developer ID provisioning profile, because a bare Mach-O carrying `keychain-access-groups` is SIGKILLed. Process model unchanged. |
| **Deciders** | A (macOS/Swift) DRI, B (Laravel platform) DRI; reviewer H (Security/privacy); Owner Andreas for every licensing conclusion (OPEN-QUESTIONS §15.2 is `ENG+ANDREAS`) |
| **Closes** | Blueprint §15 item 2 in full; §15 item 1 inputs (macOS floor, Intel yes/no) for S0-22; OPEN-QUESTIONS §15.1, §15.2, A5 (reading), A12 (dependency recorded, not resolved) |

## Context

Blueprint §2 fixes the shape of the runtime and leaves the parts open: *"Runtime: paketera/leverera
licenskompatibla, versionslåsta, signerade/notariserade artefakter. PHP och nödvändiga binaries ligger
i appens stödjande resurser eller hämtas från verifierad release-manifest via installer-helper. Använd
`launchd`/`SMAppService` för övervakade processer. Laravel server kör på loopback bakom en medföljande
minimal HTTP-server eller vältestad native runtime. Undvik `php artisan serve` som produktionsserver.
Säkerställ tydlig kompatibilitet med macOS 15+ och Apple Silicon; dokumentera om Intel stöds eller
uttryckligen inte stöds."*

§12.1 adds the constraints that make this urgent: *"bootstrap helper installs an app-owned, immutable,
versioned runtime to Application Support, not user-owned random directories. Separate mutable user data
directory with managed backups"*, *"No hidden prerequisites: PHP/runtime included … no Homebrew, Docker
… If signed packaging of PHP/runtime proves legally or technically impractical, escalate at
architecture review rather than silently requiring Homebrew."* §9 demands *"automatic launch, crash
restart, bounded retries, health probes; tydligt om användarinloggning krävs"* and *"rollback av
binaries"*. §14 requires a signed, notarized, Gatekeeper-clean release with SBOM and licence audit.

Downstream, everything in Sprint 1 Workstream A is blocked on this: S0-22 (support matrix and
preflight checks), S1-05 (installer state machine, `PACKAGE_VERIFY`), S1-06 (runtime installer and
`packaging/manifest/runtime.json`), S1-12 (signed CI build), and ADR-003's choice of which signed
process may touch the Keychain. `docs/ROADMAP.md` forbids Sprint 1 from starting until ADR-001 is
Accepted.

## Decision

### D1. PHP distribution and HTTP server: one FrankenPHP static binary, built by us

The runtime is **FrankenPHP** — a single Mach-O arm64 executable that embeds the PHP engine (ZTS)
and the Caddy HTTP server, produced by **static-php-cli**. There is no separate `php`, `php-fpm`,
nginx or Caddy binary. The same binary serves HTTP (`frankenphp run`) and runs Artisan
(`frankenphp php-cli artisan …`).

Versions pinned for v1 (recorded in `packaging/manifest/runtime.json`, S1-06):

| Component | Version | Licence (SPDX) | Role |
|---|---|---|---|
| FrankenPHP | 1.13.0 (2026-10-04) | MIT | SAPI, worker runtime, Caddy module |
| PHP | 8.5.11 (2026-09-24), ZTS | PHP-3.01 (+ Zend-2.0 for `Zend/`) | engine |
| Caddy | 2.11.7 (2026-10-03) | Apache-2.0 | loopback HTTP server |
| static-php-cli | 2.8.5 build tool (the FrankenPHP `build-static.sh` pins its own) | MIT | build tool only, not shipped |

**v1 ships our own build, not the upstream release artefact.** The upstream
`frankenphp-mac-arm64` release is a kitchen-sink build: 78 extensions including `gmp` (statically
links GNU MP, LGPL-3.0), `readline`, `imagick`, `ldap`, `pgsql`, `mysqli`, `memcached`, `amqp`,
`ssh2`, plus the Caddy modules **Mercure and Vulcain, both AGPL-3.0**, linked into the binary
(prototype, Evidence E4). Our build, produced in CI by FrankenPHP's `build-static.sh`
(`PHP_EXTENSIONS` curated, `SPC_CMD_VAR_FRANKENPHP_XCADDY_MODULES` set to exclude Mercure/Vulcain),
contains only what Laravel 13 and this product need:

`ctype curl dom fileinfo filter hash iconv intl mbstring openssl pcre pdo pdo_sqlite sqlite3
session tokenizer xml xmlreader xmlwriter simplexml zlib opcache bcmath sodium zip pcntl posix
exif gd` — plus `redis`/`pdo_mysql` only if ADR-002's advanced profile is adopted.

**Two build-configuration requirements, added 2026-10-11 after the first real build (A23 decided by
Andreas; Evidence E7). Neither is optional, and `packaging/runtime/build-runtime.sh` fails closed
without them:**

1. **The `nomercure` Go build tag.** Leaving Mercure and Vulcain out of
   `SPC_CMD_VAR_FRANKENPHP_XCADDY_MODULES` is *not* enough: FrankenPHP's own `caddy/mercure.go` is
   compiled unless the tag is set (`//go:build !nomercure`), and the first build without it linked
   `github.com/dunglas/mercure v1.0.3` (AGPL-3.0) anyway. static-php-cli 2.8.5 hard-codes its xcaddy
   tag list, so the recipe patches the pinned source behind an exact-string guard and then verifies
   `build -tags=…nomercure…` in `go version -m` of the finished binary. Shipped tags:
   `nobadger,nomysql,nopgx,nomercure,nowatcher`. (`nomercure` also drops FrankenPHP's Mercure-based
   hot reload, which the product does not use.)
2. **libxml2 and the `iconv` extension are built against the macOS system iconv**, never GNU
   libiconv (LGPL-2.1), which spc 2.8.5 otherwise links statically because `libxml2` is a dependency
   of `php` itself on Darwin and `dom`/`xml`/`simplexml` need libxml2. This is ADR-007 D2.2's
   prescribed route; the three guarded edits to the pinned spc source are described in
   `packaging/runtime/README.md` §1 and OPEN-QUESTIONS A23. The resulting binary links
   `/usr/lib/libiconv.2.dylib` — a library macOS provides and we do not redistribute — and no
   `libiconv.a` exists anywhere in the build tree.

The upstream release artefact is used in Sprint 0 as the **measured reference** (Evidence E1–E4) and
as the emergency fallback if our build pipeline is late; it is never shipped to users because of the
AGPL/LGPL components. Build-time Homebrew on the CI runner (required by `build-static.sh` on macOS)
is permitted; §12.1 forbids Homebrew on the *user's* Mac, and nothing in the shipped artefact depends
on it (Evidence E1: the binary links only macOS system libraries).

`php artisan serve` (PHP's built-in server) is **forbidden** as the production server, per §2. It is
single-threaded, has no production hardening and is documented by PHP as a development aid. It may be
used in tests only.

### D2. Signing and notarization of the runtime binary

The FrankenPHP binary is re-signed by us with the project's Developer ID Application identity (team
undecided — A12), `--options runtime` (Hardened Runtime, mandatory for notarization), timestamped,
and notarized as part of the app bundle/DMG. Entitlements on the runtime executable, exactly:

```xml
<key>com.apple.security.cs.allow-jit</key><true/>
```

Nothing else. Measured (E3): without `allow-jit`, PHP under Hardened Runtime logs *"Allocation of
JIT memory failed, PCRE JIT will be disabled … security restrictions"* on every request start-up;
with it, PCRE JIT works. `com.apple.security.cs.allow-unsigned-executable-memory`,
`disable-library-validation` and `allow-dyld-environment-variables` (all four of which Laravel Herd's
PHP carries, E5) are **not** granted: the static binary loads no plug-ins and we keep OPcache's
machine-code JIT disabled (`opcache.jit=disable`, the upstream default, E4). The runtime is not
App-Sandboxed (it must bind loopback, spawn children and read `~/Library`), and carries no
`keychain-access-groups` (ADR-003: PHP never reads the Keychain).

### D3. Processes and lifecycle: one SMAppService launch agent, the Supervisor

> **Amended 2026-10-11 (A24, delegated to ENG).** The Supervisor's *container* changed: it is a
> headless helper **application bundle** at
> `Contents/Helpers/LaravelExecutiveAISupervisor.app`, not a bare Mach-O at `Contents/MacOS/`.
> Evidence: the first Developer-ID-signed build (7a74ef5, CI run 38120770614) was launched directly
> on the owner's Mac on 2026-10-11 and died with **exit 137 (SIGKILL)** — macOS validates the
> restricted `keychain-access-groups` entitlement at launch against an embedded provisioning
> profile, and a bare Mach-O cannot carry `Contents/embedded.provisionprofile`. **ADR-003 D5 was
> right about this** and said so from the start. Nothing else in D3 changes: one LaunchAgent, one
> `SMAppService` job, the same process model, the same restart policy, the same code. The bullets
> below are written as amended; `packaging/signing/README.md` §5 records the lab run and the
> re-test.

Exactly one launchd job is registered, and it is a **LaunchAgent**, not a daemon:

- Executable:
  `Contents/Helpers/LaravelExecutiveAISupervisor.app/Contents/MacOS/LaravelExecutiveAISupervisor`
  (headless Swift — `LSUIElement` + `LSBackgroundOnly`, no window, no Dock icon — signed with the
  app's Team ID and carrying its own Developer ID provisioning profile, inside the app bundle so
  `SMAppService` can own it).
- Plist: `Contents/Library/LaunchAgents/com.stafe.laravelexecutiveai.supervisor.plist`
  (`com.stafe.laravelexecutiveai` is a **placeholder bundle-id prefix until A12 is decided**), with
  `BundleProgram` → the executable above, `KeepAlive` → `true`, `ThrottleInterval` → `10`,
  `ProcessType` → `Background`, `ExitTimeOut` → `30`, `AssociatedBundleIdentifiers` → the app **and
  the helper bundle** (Login Items attributes the job to either, `man 5 launchd.plist`).
- Entitlements: hardened runtime, no App Sandbox, and the two restricted entitlements ADR-003 D5
  requires (`keychain-access-groups`, `com.apple.application-identifier`), authorised by the
  Developer ID profile embedded at
  `Contents/Helpers/LaravelExecutiveAISupervisor.app/Contents/embedded.provisionprofile`.
- Registered with `SMAppService.agent(plistName:)` → `register()` from the installer's
  `BOOT_SERVICES` state; status read via `SMAppService.status`; `openSystemSettingsLoginItems()`
  offered if status is `.requiresApproval`.

The Supervisor, not launchd, owns the PHP processes. On start it (1) verifies the active runtime
directory's checksums and code signature against `runtime.json` (fail closed), (2) spawns three
children from `runtime/<version>/`, each on a private process group, (3) probes
`GET http://127.0.0.1:<port>/health/live` every 5 s and `/health/ready` every 30 s, (4) restarts a
failed child with exponential backoff `1, 2, 4, 8, 16, 30 s` capped at **5 restarts per 10 minutes**,
after which it marks the service `degraded`, surfaces the failure in the menu bar and stops retrying
until the user acts, and (5) hands secrets to PHP on boot (mechanism: ADR-003).

| Child | Command (from the runtime dir) | Notes |
|---|---|---|
| core HTTP | `frankenphp run --config Caddyfile` | `bind 127.0.0.1`, fixed port from preflight, no TLS on loopback, classic (non-worker) mode in v1 |
| queue worker | `frankenphp php-cli artisan queue:work database --tries=3 --max-time=3600` | ADR-002 database queue |
| scheduler | `frankenphp php-cli artisan schedule:work` | one process; §9 power rules apply |

Why one agent plus a supervisor instead of three launchd jobs: `BundleProgram` must be
app-bundle-relative, but §12.1 puts the runtime in Application Support; health probing against
`/health/ready` and the §12.1 `PACKAGE_VERIFY` step need a native process anyway; and ADR-003 needs a
single signed broker. launchd's `KeepAlive` gives us the outer guarantee (Supervisor crash →
relaunch, throttled to once per 10 s by `ThrottleInterval`), the Supervisor gives us the bounded,
observable inner one.

**User login is required.** A LaunchAgent is bootstrapped at login into the user's GUI session
(Apple: *"LaunchAgents registered with this method bootstrap on each subsequent login"*). After a
reboot — including after a power cut — the services do not run until the user unlocks FileVault and
logs in. This is stated to the user in the installer and in `docs/operations/`, as §9 requires
(*"Ingen falsk garanti om full unattended recovery"*). A LaunchDaemon was rejected (see Alternatives).

### D4. On-disk layout

```
~/Library/Application Support/LaravelExecutiveAI/
  runtime/
    <runtime_version>/            immutable, app-owned; chmod -R a-w after install
      frankenphp                  signed binary (D2)
      Caddyfile                   loopback-only server config, no secrets
      php.ini                     opcache on, opcache.jit=disable, pcre.jit=1, no secrets
      app/                        Laravel source + vendor/ for this release, bootstrap/cache pre-built
      manifest.json               copy of the runtime.json entry that produced this directory
      SHA256SUMS                  verified by the Supervisor on every boot
    current -> <runtime_version>  symlink switched atomically by the updater
    previous -> <runtime_version> kept for rollback (§9); older versions are garbage-collected
  data/                           mutable, user-owned, inside managed backups
    database/executive.sqlite (+ -wal, -shm)
    storage/{app,framework,logs→symlink}
    backups/
~/Library/Logs/LaravelExecutiveAI/          runtime + supervisor logs (not backed up)
~/Library/Caches/LaravelExecutiveAI/        view/route/compiled caches that may be lost
```

- `<runtime_version>` is the semver of the runtime bundle in `packaging/manifest/runtime.json`,
  bumped whenever any pinned component (FrankenPHP, PHP, Caddy, app code, vendor) changes.
- **Two runtime versions coexist by construction**; an update installs `runtime/<new>/` beside
  `runtime/<old>/`, runs the DB migration dry-run against a backup (§9), then flips `current`. Rollback
  flips `current` back to `previous`; no automatic rollback of incompatible schema changes (§9).
- Laravel's `storage_path()` and `database_path()` point into `data/`; `base_path()` is inside the
  immutable runtime. Nothing under `runtime/` is written at run time.
- **No `.env` file exists.** Non-secret configuration is passed as process environment by the
  Supervisor; secrets (APP_KEY, provider tokens) are never written to disk by the installer and
  never appear in `bootstrap/cache/config.php` (ADR-003).

### D5. Support matrix inputs (for S0-22)

- **macOS floor: 15.0 (Sequoia). Highest tested: 26.x.** Ambiguity A5 is read as the range
  "macOS 15 through 26". Technical minimum is lower (`SMAppService` needs macOS 13), so the floor is
  a product decision: 15 is the oldest release still receiving Apple security updates in October 2026,
  and from 15 Gatekeeper removes the Control-click override, so a correctly signed and notarized bundle
  is the only path that works at all (E6). Reopen when macOS 27 ships.
- **Apple Silicon only. Intel is explicitly not supported.** Reasoning: (a) nobody on the team owns
  or tests an Intel Mac; (b) Apple has stated macOS 26 is the last release to support Intel Macs, so
  any Intel user is on a frozen OS within a year of our v1; (c) a universal binary roughly doubles the
  runtime download and every signing/notarization/measurement run in §12.2 for an audience Apple
  itself is retiring. Preflight (`PREFLIGHT`, §12.1) blocks on `hw.optional.arm64 != 1` with an
  actionable message; the app is never offered as a universal build. (Upstream FrankenPHP does
  publish `frankenphp-mac-x86_64`, so this is a choice, not a constraint.)
- Minimum RAM/disk remain **unmeasured** and belong to S0-22. Inputs from this ADR: runtime binary
  ≈ 181 MB upstream (our curated build will be smaller; measure), ≈ 97 MB RSS per idle FrankenPHP
  process, three PHP processes + Supervisor at idle.

### D6. Escalation outcome if signed FrankenPHP packaging proves impractical

If Andreas's legal review (Evidence L1) or the Sprint 1 clean-Mac prototype shows the FrankenPHP
binary cannot be shipped signed and notarized, the fallback is decided now so nobody improvises:

1. **Fallback A (technical):** static-php-cli `php` (CLI) + `php-fpm` binaries, signed with the same
   entitlement policy as D2 — proven feasible by Herd shipping exactly such binaries with Developer ID
   + Hardened Runtime (E5) — fronted on loopback by a standalone Caddy binary (Apache-2.0, pure Go,
   trivially signable) speaking FastCGI. Same layout (D4), same Supervisor (D3), four children instead
   of three.
2. **Fallback B (legal, if any PHP-engine licence term blocks redistribution):** there is no
   PHP-free fallback for a Laravel product; the escalation is to architecture review with Andreas and
   the outcome is recorded as ADR-001 Superseded. **Under no circumstance** does the installer detect,
   suggest, or require Homebrew, MacPorts, Docker, Nix, Herd, Valet, or a user-installed PHP. A PR
   that adds such a path is rejected in review.

## Evidence

### Measured prototype (P1) — upstream FrankenPHP v1.13.0 static binary on this Mac

Machine: Apple M4 Max, 36 GB, macOS 26.6.2 (25G83), Xcode 26.2, Herd PHP 8.4.19 present but unused
by the prototype. Artefacts and reproduction steps: `packaging/fixtures/prototypes/adr-001/`
(`measure-cold-start.sh`, `www/index.php`, `www/probe.php`, `entitlements-runtime.plist`,
`README.md`; the binary is git-ignored). Performed 2026-10-10, ~30 minutes.

**E1 — Artefact and provenance.** `frankenphp-mac-arm64` from
<https://github.com/php/frankenphp/releases/tag/v1.13.0>: 180 861 128 bytes, downloaded in 8.2 s,
SHA-256 `99b139ca8326ad00dad412677dce6b5fceb717a9a04329d730c3cdb89205ee05`. The release publishes
**no checksum file**; provenance verified with `gh attestation verify --repo php/frankenphp`
(Sigstore bundle, built by workflow `.github/workflows/static.yaml` on a GitHub-hosted runner,
transparency-log index 3175770453). `frankenphp version` → `FrankenPHP v1.13.0 PHP 8.5.11 Caddy
v2.11.7`. `otool -L`: links only `/usr/lib/libSystem.B`, `libresolv.9`, `libc++.1`, `libutil`, and
the `CoreFoundation`, `CoreServices`, `SystemConfiguration`, `Security` frameworks — i.e. relocatable,
no Homebrew/opt paths.

**E2 — Signature state as shipped.** `codesign -dv`: `Signature=adhoc`,
`flags=0x20002(adhoc,linker-signed)`, `TeamIdentifier=not set`, no entitlements.
`spctl --assess --type execute`: **rejected**. Conclusion: upstream artefacts cannot be shipped
as-is; re-signing is mandatory (D2).

**E3 — Cold start to first HTTP 200 on 127.0.0.1** (`php-server`, trivial `index.php`, 10 ms poll):

| Binary | run 1 | run 2 | run 3 | run 4 | run 5 | RSS after boot | warm GET | SIGTERM→exit |
|---|---|---|---|---|---|---|---|---|
| as shipped (ad-hoc) | 370 ms¹ | 77 | 77 | 81 | 81 | 96–97 MB | 0.5–0.7 ms | 42–48 ms |
| re-signed, Hardened Runtime + `allow-jit` | 3 547 ms² | 72 | 78 | — | — | 96 MB | — | — |
| re-signed, Hardened Runtime, no entitlements | 3 436 ms² | 63 | 73 | — | — | 97 MB | — | — |

¹ first launch after download (page cache cold). ² first launch after re-signing: the kernel
validates the new 181 MB code directory once; subsequent launches are back to ~70 ms. Budget
implication for §12.2: the runtime's own boot is <0.1 s warm and <4 s worst case; the five-minute
target is spent elsewhere (download, migrations, user prompts).

PCRE under Hardened Runtime: without `allow-jit`, `php-cli -r 'preg_match(...)'` prints
*"Warning: PHP Request Startup: Allocation of JIT memory failed, PCRE JIT will be disabled. This is
likely caused by security restrictions"* (the match still succeeds); with `allow-jit`, no warning.
`frankenphp php-cli script.php` works on PHP 8.5 (0.34 s wall for a one-line script); `php-cli -v`
and `-m` print *"not available … with a php version lower than 8.6"*, so preflight/version checks
must use `frankenphp version` or a probe script, not `-v`.

**E4 — What the upstream binary contains** (`probe.php`, `get_loaded_extensions()`): 78 extensions;
all Laravel 13 required extensions present; `Zend OPcache` enabled with `opcache.jit` unset (JIT
disabled); SQLite 3.45.2 **with FTS5** (input to ADR-002); `pcre.jit=1`; `ZEND_THREAD_SAFE=true`;
present and unwanted: `gmp`, `readline`, `imagick`, `ldap`, `pgsql`, `pdo_pgsql`, `mysqli`,
`memcached`, `amqp`, `ssh2`, `parallel`, `xlswriter`; absent: `imap`, `ffi`. `caddy/go.mod` pins `github.com/dunglas/mercure v1.0.3` and
`github.com/dunglas/vulcain/caddy v1.4.5`; `build-static.sh` default
`SPC_CMD_VAR_FRANKENPHP_XCADDY_MODULES="--with github.com/dunglas/mercure/caddy --with
github.com/dunglas/vulcain/caddy --with github.com/dunglas/caddy-cbrotli"` — overridable, which is
what D1 relies on.

**E5 — Proxy: a production Developer-ID-signed static PHP on this Mac.** Laravel Herd's
`~/Library/Application Support/Herd/bin/php84` (PHP 8.4.19 NTS, 91 836 352 bytes): `Authority=Developer
ID Application: Beyond Code GmbH (8Z259RPWAC)`, `flags=0x10000(runtime)`, `Runtime Version=14.5.0`,
timestamped 2026-03-13, entitlements `allow-jit`, `allow-unsigned-executable-memory`,
`disable-library-validation`, `allow-dyld-environment-variables`; links the same system libraries as
E1. This demonstrates Apple accepts a Developer-ID + Hardened-Runtime signature on a static PHP
binary of this construction; it says nothing about notarization of *our* bundle, which S1-12 proves.

**E6 — Not measurable here.** This Mac has no *Developer ID Application* identity (only Apple
Development/Distribution ones), so Developer ID signing, notarization and the clean-Mac Gatekeeper
pass could not be performed. That is A12 and the S0-06 acceptance line *"a signed bundle booting the
Laravel core on a clean Mac"*, deferred to S1-06/S1-12 and the reason this ADR stays **Proposed**.

### Vendor documentation

| Id | Claim relied upon | Source (retrieved 2026-10-10) |
|---|---|---|
| V1 | *"In macOS 13 and later, use `SMAppService` to register and control LoginItems, LaunchAgents, and LaunchDaemons as helper executables for your app."* `register()`: *"If the service corresponds to a LaunchAgent, the LaunchAgent is immediately bootstrapped and may begin running. In addition LaunchAgents registered with this method bootstrap on each subsequent login."* Daemons: *"the system won't bootstrap the LaunchDaemon until an admin approves … in System Preferences."* Availability macOS 13.0+. | <https://developer.apple.com/documentation/servicemanagement/smappservice> and `…/smappservice/register()` |
| V2 | `BundleProgram`: *"an app-bundle relative path to the executable for the job. This key is only supported for plists that are installed using SMAppService."* `KeepAlive`: *"Jobs that exit quickly and frequently when configured to be kept alive will be throttled."* `ThrottleInterval`: *"by default, jobs will not be spawned more than once every 10 seconds."* `ProcessType` Background: *"resource limits … intended to prevent them from disrupting the user experience."* | `man 5 launchd.plist`, macOS 26.6.2 (25G83) |
| V3 | Hardened Runtime: *"To upload a macOS app to be notarized, you must enable the Hardened Runtime capability."* `allow-jit`: *"whether the app may create writable and executable memory using the `MAP_JIT` flag"*, examples include *"Perl-compatible regular expressions (PCRE)"*. `allow-unsigned-executable-memory`: *"exposes your app to common vulnerabilities in memory-unsafe code languages. Carefully consider whether your app needs this exception."* | <https://developer.apple.com/documentation/security/hardened-runtime>, <https://developer.apple.com/documentation/bundleresources/entitlements/com.apple.security.cs.allow-jit>, `…/com.apple.security.cs.allow-unsigned-executable-memory` |
| V4 | *"In macOS Sequoia, users will no longer be able to Control-click to override Gatekeeper when opening software that isn't signed correctly or notarized."* (2024-08-06) | <https://developer.apple.com/news/?id=saqachfa> |
| V5 | FrankenPHP static builds: *"it's possible to create a static or mostly static build of FrankenPHP thanks to the great static-php-cli project"*; *"By default, the most popular PHP extensions are compiled"*; macOS: *"Run the following script to create a static binary for macOS (you must have Homebrew installed)"*; `PHP_EXTENSIONS` / `PHP_EXTENSION_LIBS` build args. | <https://frankenphp.dev/docs/static/> (repo `docs/static.md`, 1.13.0) |
| V6 | FrankenPHP + Laravel: official page covers Octane (`octane:install --server=frankenphp`) and *"embedded standalone binary"*. | <https://frankenphp.dev/docs/laravel/> |
| V7 | static-php-cli: *"compiles the PHP interpreter together with any extensions you need into a single self-contained binary … Builds target Linux, macOS, and Windows"*; pre-built `spc-macos-aarch64`; `spc dump-license` *"Export open-source license files for artifacts"* (SBOM input for §14). MIT. | <https://static-php.dev/en/guide/>, repo `docs/en/guide/cli-reference.md`, README |
| V8 | Licences, read from the repositories' `LICENSE` files: php/frankenphp **MIT** (Kévin Dunglas); caddyserver/caddy **Apache-2.0**; php/php-src branch `PHP-8.5` **PHP License 3.01** (`master` is already BSD-3-Clause); dunglas/mercure **AGPL-3.0**; crazywhalecc/static-php-cli **MIT**. | GitHub API `repos/*/contents/LICENSE`, 2026-10-10 |
| V9 | PHP licence RFC: Accepted 51–0–2; Modified BSD becomes *"PHP License, version 4"* and *"Zend Engine License, version 3"* for *"the next version of PHP"* after acceptance; *"does not reduce any user rights or add any new restrictions"*. PHP 8.5 therefore remains PHP-3.01. | <https://wiki.php.net/rfc/php_license_update> |
| V10 | *"macOS Tahoe will be the final release for Intel Macs."* (WWDC25 Platforms State of the Union, 2025-06-09, transcript on Apple's page). The "Rosetta through macOS 27" statement is not in that transcript and is cited only as secondary. | Primary: <https://developer.apple.com/videos/play/wwdc2025/102/> (pinned by S0-22, 2026-10-10); secondary for Rosetta: <https://www.techrepublic.com/article/news-apple-macos-27-drops-intel-mac-support-rosetta/> |

### Legal / licensing input — needs legal review (Andreas)

Engineers do not accept legal risk (template). The following are *findings for review*, not
conclusions:

- **L1** All components in the curated build (D1) are under permissive licences: MIT (FrankenPHP),
  Apache-2.0 (Caddy, OpenSSL 3), PHP-3.01 + Zend-2.0 (PHP), BSD-3-Clause (Go runtime, libwebp),
  MIT (libxml2, libyaml, brotli), zlib, ISC (libsodium), Unicode-3.0 (ICU), BSD-3 (libzip), curl
  licence, public domain (SQLite). Each requires shipping its notice text; PHP-3.01 §6 additionally
  requires the acknowledgement *"This product includes PHP software, freely available from
  <http://www.php.net/software/>"* in the about box/documentation and forbids using "PHP" in the
  product name. **Andreas confirms** the notice set, generated by `spc dump-license` + Go module
  licences, satisfies ADR-007.
- **L2** The upstream release binary links **AGPL-3.0** code (Mercure, Vulcain) and **LGPL-3.0**
  code (GNU MP via `gmp`) statically. D1 removes them from the shipped artefact; **Andreas confirms**
  that excluding them is the required outcome and that none may re-enter via a future
  `PHP_EXTENSIONS` change without a new legal review.
- **L3** Redistribution of PHP under PHP-3.01 in binary form is permitted *"with or without
  modification"* subject to the notice conditions; the move to BSD-3-Clause in the next PHP minor
  simplifies this. **Andreas confirms** nothing in PHP-3.01 conflicts with the licence ADR-007
  selects for the product.
- **L4** Signing third-party binaries with our Developer ID makes STAFE/the owner the responsible
  distributor towards Apple (notarization scans, malware liability). **Andreas decides** the team
  identity (A12) with that in mind.

## Alternatives considered

1. **Ship the upstream `frankenphp-mac-arm64` release as-is, re-signed.** Fastest path and what the
   prototype measured. Ruled out for v1 because it embeds AGPL-3.0 (Mercure, Vulcain) and LGPL-3.0
   (gmp) code and 40+ extensions we do not use, each a licence obligation and an attack surface (E4,
   L2). Kept as the Sprint 0 reference and emergency fallback.
2. **static-php-cli `php` + `php-fpm` behind a minimal HTTP server** (the Herd/Valet-shaped
   approach; D6 Fallback A). Equally signable (E5) and equally licence-clean, but it needs a second
   server binary (standalone Caddy or nginx) or a hand-written Swift HTTP→FastCGI proxy — a new
   security-critical component with no upstream — plus FastCGI process-pool tuning. FrankenPHP
   gives the same static PHP with the server already integrated, maintained by the PHP Foundation's
   GitHub organisation, and an official Laravel/Octane path (V6). Ruled out as the primary choice,
   retained as the fallback.
3. **PHP's built-in server (`php artisan serve` / `php -S`).** Forbidden by §2. Single-threaded,
   no request limits, no production hardening; PHP's own manual labels it a development aid.
4. **Homebrew/Herd-installed PHP detected at install time.** Violates §2 and §12.1 ("no hidden
   prerequisites"), makes behaviour depend on a toolchain we do not control or sign, and §12.1
   explicitly names this as the thing to escalate about rather than do.
5. **Three launchd jobs (core, worker, scheduler) directly via SMAppService, no Supervisor.** Simpler
   on paper, but `BundleProgram` must live inside the app bundle (V2) while the runtime lives in
   Application Support (§12.1), so a shim is needed anyway; launchd has no HTTP health probe, no
   "stop after N failures" (it throttles instead), and ADR-003 needs a signed native process to broker
   secrets. One Supervisor satisfies all three.
6. **LaunchDaemon (system-wide, runs without login).** Would give true unattended restart after
   reboot, but requires admin approval in System Settings (V1), runs outside the user session, cannot
   reach the user's Keychain or `~/Library` without privilege juggling, and contradicts §12.2
   ("no admin password until an OS-level protected operation genuinely needs one") and §6's
   privilege-boundary stance. FileVault already makes unattended boot impossible (§9).
7. **Worker mode (Octane) from day one.** Faster per request, but long-lived PHP processes raise
   state-leak and memory-growth risks (FrankenPHP 1.13.0 release notes list several worker-mode
   fixes) and add the Octane dependency. v1 uses classic mode; worker mode is a measured opt-in
   (Reopen conditions).
8. **Universal (arm64 + x86_64) binary.** Doubles download size and the §12.2 measurement matrix for
   a platform Apple is retiring (V10). Rejected; see D5.

## Consequences

**Easier**

- One runtime binary to build, sign, notarize, hash and roll back; `PACKAGE_VERIFY` is one SHA-256
  plus one `codesign --verify`.
- Artisan, queue worker and scheduler run from the same binary — no PHP CLI/FPM version drift.
- Loopback HTTP, HTTP/2 and static-file serving come from Caddy; no nginx config.
- The curated extension list is the SBOM input for §14 (`spc dump-license`).
- ADR-002 can rely on SQLite ≥ 3.45 with FTS5 being present (E4).

**Harder**

- We own a build pipeline (`build-static.sh` in CI, macOS runner with Homebrew for build tooling)
  and must track FrankenPHP/PHP security releases — 1.13.0 alone fixed five CVEs. Target: a runtime
  release within 14 days of an upstream high-severity fix; recorded in `docs/operations/`.
- ZTS PHP: any extension we add later must be thread-safe; OPcache shared-memory restarts under ZTS
  are unsafe (upstream metric `frankenphp_opcache_restarts` must stay at zero).
- `frankenphp php-cli -v/-m` is unavailable on PHP < 8.6; tooling uses probe scripts.
- Sprint 1 must redo E3 on the curated, Developer-ID-signed build on a clean Mac before Accepted.

**Forbidden in code review from now on**

- Any detection of, suggestion of, or dependency on Homebrew, MacPorts, Docker, Herd, Valet or a
  system PHP in product code or installer text.
- `php artisan serve`, `php -S`, or `artisan serve` in anything but tests.
- Writing into `runtime/<version>/` at run time; writing secrets or `.env` files anywhere (ADR-003).
- Entitlements on the runtime beyond `com.apple.security.cs.allow-jit`; enabling `opcache.jit`
  without a new measurement.
- Adding a PHP extension or Caddy module without updating `runtime.json`, the licence notices and
  asking for legal review if its licence is not on the L1 list.
- Registering a LaunchDaemon, or more than the one Supervisor LaunchAgent.

## Residual risks

| Risk | Accepted by |
|---|---|
| Notarization of a 100+ MB Go+PHP static binary with Hardened Runtime has been proven only by proxy (Herd, E5), not on our bundle. If Apple's notarization service rejects it, D6 Fallback A applies and Sprint 1 slips. | A DRI, until S1-12 closes it |
| Services do not run after reboot until the user logs in (FileVault + LaunchAgent). Scheduled briefs are missed in that window. | Owner (product), documented to users per §9 |
| We ship and are responsible for a PHP engine security surface; a late upstream patch is our exposure until our 14-day release lands. | H (Security/privacy) with signed acceptance |
| ZTS PHP has a smaller real-world footprint than NTS; obscure extension bugs may surface only under threads. Mitigated by the curated list and FrankenPHP's own CI. | B DRI |
| Licence conclusions L1–L4 are pending; if L2/L3 come back negative the stack changes. | Andreas (legal owner) |
| Bundle-id prefix and Team ID are placeholders (A12); renaming after first install would orphan `SMAppService` registrations. Must be fixed before any external TestFlight/DMG. | Andreas |

## Blueprint references

§2, §2.1, §6, §9, §11, §12.1, §12.2, §13.1, §14, §15 items 1–2, §16 items 2 and 5, §17, §18.

## Reopen conditions

- Notarization or Gatekeeper rejects the Developer-ID-signed FrankenPHP binary in S1-12 → D6.
- Legal review (L1–L4) rejects any bundled component's redistribution terms.
- §12.2 or §14 p95 gates fail and profiling attributes it to classic (non-worker) mode → evaluate
  Octane/worker mode with a measured prototype.
- FrankenPHP stops publishing macOS arm64 support, or `build-static.sh` drops macOS.
- PHP 8.6 ships under BSD-3-Clause (V9) and FrankenPHP supports it → revisit L1/L3 wording only.
- macOS 27 is released → revisit the floor (D5) with S0-22.
- S0-22 measurements show the three-process idle footprint (≈3 × 97 MB + Supervisor) breaks the
  minimum-RAM target for the lowest supported tier.
