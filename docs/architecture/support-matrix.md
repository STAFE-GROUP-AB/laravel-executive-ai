# Support matrix and preflight checks (S0-22)

| | |
|---|---|
| **Status** | Recorded 2026-10-10 — numbers marked **PROVISIONAL** are replaced by measurements on the minimum machine in S1-14 |
| **Closes** | Blueprint §15 item 1; OPEN-QUESTIONS §15.1 and A5; the §12.1 preflight list for S1-05 |
| **Owner** | A (macOS/Swift); I (QA/release) for the Sprint 1 measurements |
| **Inputs** | ADR-001 D3/D4/D5/E1/E3, ADR-002 E1, ADR-003 D5/D6, ADR-004 D8, ADR-005 D2, ADR-006 D1, Blueprint §2, §12.1–§12.3 |

Every number in this document carries one of two labels and they are never blurred:

- **MEASURED** — observed on a named machine on a named date with the command recorded, reproducible
  from `packaging/fixtures/prototypes/adr-001/` or this document.
- **PROVISIONAL** — derived from measured parts plus a stated margin, or an estimate where no
  artefact exists yet. The S0-22 acceptance line *"minimum RAM and free disk are measured on a real
  install, not estimated"* **cannot be met in Sprint 0**: there is no installable product (S0-25,
  S0-26, S1-05, S1-06 are open). This document therefore measures every component that exists,
  derives the minimum from them, and binds S1-14 to replace each PROVISIONAL value (§7).

---

## 1. Supported macOS versions

| | Value | Basis |
|---|---|---|
| **Floor** | **macOS 15.0 (Sequoia)** | ADR-001 D5. Product decision, not a technical one: `SMAppService` needs only macOS 13. 15 is chosen because (a) it is the oldest release still inside Apple's security-update window in October 2026, and (b) from macOS 15 Gatekeeper removes the Control-click override for software that is not signed and notarized ([Apple developer news, 2024-08-06](https://developer.apple.com/news/?id=saqachfa)), so a correctly signed, notarized bundle is the *only* install path on every supported version — the installer never has to explain a workaround. |
| **Highest tested** | **macOS 26.6.2 (build 25G83)** | **MEASURED** `sw_vers` on this Mac (Mac16,9, Apple M4 Max), 2026-10-10. Every ADR-001/002/005 measurement to date ran on this build. |
| **A5 ruling** | "macOS 15/26" in §15.1 is read as the **range 15 through 26** | OPEN-QUESTIONS A5, ADR-001 D5. Floor 15.0, highest tested 26.x. |
| **Newer than tested** | macOS 27 and later: install is **allowed**, logged as a warning, never blocked | A newer major is not evidence of breakage; blocking would strand users at the first autumn release. Reopen the floor when macOS 27 ships (ADR-001 reopen condition). |

**What "tested" means, per Sprint.** A version is *tested* only when a clean install run on it is
committed under `packaging/manifest/install-timer/results/` (S1-14 protocol) and the §12.2 `ready`
self-tests pass.

| Sprint | Tested set | Evidence required |
|---|---|---|
| 0 (now) | 26.6.2 only, and only the runtime prototype — **not** the product | ADR-001 E1–E4 on this Mac |
| 1 | 26.x on Mac A (and Mac B if available); **15.x on the minimum machine** (§5) | S1-14: ≥10 clean installs per profile, raw JSON committed |
| 2–3 | 15.x and 26.x on every lab profile after each runtime bump | S1-14 protocol re-run per `runtime.json` change |
| 4 (release) | 15.x, the current 26.x, and the first 27.x beta if public | §12.2 gate: ≥30 installs per profile, p50 < 180 s, p95 < 300 s |

The floor is **re-examined, not automatically raised**, each time Apple drops a release from its
security-update window.

---

## 2. Supported chips

| Chip family | Verdict | Basis |
|---|---|---|
| **Apple Silicon — M1 and every later M-series (M1/M2/M3/M4 and successors, all tiers)** | **Supported** | The runtime is a single arm64 Mach-O (ADR-001 D1). macOS 15 and 26 both run on every M1 Mac, so chip and OS floors coincide on the oldest hardware. |
| **Intel (x86_64)** | **Explicitly NOT supported.** No universal build is produced; preflight blocks with the message in §6.2. | ADR-001 D5 and alternative 8. |

**Primary Apple source for the Intel sunset (found; V10 in ADR-001 updated).** Apple's WWDC25
Platforms State of the Union, 2025-06-09, transcript on Apple's own page:

> *"macOS Tahoe will be the final release for Intel Macs."*
> — <https://developer.apple.com/videos/play/wwdc2025/102/> (transcript, closing section; retrieved
> 2026-10-10)

The transcript page does **not** contain the "security updates for three years" or the "Rosetta
through macOS 27" sentences that circulate in the press. Those are attributed to Apple's spoken
remarks and to developer-documentation updates, but we could not locate them verbatim on an Apple URL
on 2026-10-10; they are therefore cited **only** as secondary
([TechRepublic](https://www.techrepublic.com/article/news-apple-macos-27-drops-intel-mac-support-rosetta/),
[MacRumors](https://www.macrumors.com/2026/02/16/macos-tahoe-26-4-rosetta-2-warnings/)) and nothing in
this matrix depends on them. The decision rests on the one primary sentence above plus ADR-001 D5's
engineering reasons (nobody on the team owns an Intel Mac; a universal binary doubles every
signing/notarization/measurement run for a platform whose final OS is already shipping).

What Intel users see: the preflight blocker (§6.2). The DMG is still downloadable — we do not know
the chip before they run the installer — but the README states the requirement up front.

---

## 3. Measured components (what exists today)

All on **this Mac**: Mac16,9, Apple M4 Max (14 cores), 36 GB (`hw.memsize` = 38 654 705 664),
macOS 26.6.2 (25G83), Xcode 26.2, Swift 6.2.3, APFS SSD. Date: 2026-10-10.
This is the **upper-bound** machine (ADR-002 E1); none of these numbers is from the minimum machine.

| # | Component | Value | Label | How measured |
|---|---|---|---|---|
| M1 | Runtime binary, upstream `frankenphp-mac-arm64` v1.13.0 | **180 861 128 bytes** (≈181 MB; 180 300 KiB on APFS per `du -k`) | MEASURED | Download per `packaging/fixtures/prototypes/adr-001/README.md` step 1; SHA-256 `99b139ca…ee05` matches ADR-001 E1. Our curated build (ADR-001 D1) will be smaller — not built yet. |
| M2 | Core HTTP process, idle RSS right after first 200 (`frankenphp php-server`) | **95 840 – 97 264 kB** over 3 runs (cold start 154 / 79 / 73 ms) | MEASURED | `./measure-cold-start.sh bin/frankenphp-mac-arm64 3`. Matches ADR-001 E3 (96–97 MB). Serving a trivial `index.php`, not Laravel. |
| M3 | Long-running `php-cli` process, idle RSS (shape of the queue worker and scheduler, ADR-001 D3) | **67 136 kB** | MEASURED | `frankenphp php-cli sleep.php` (`<?php sleep(60);`), sampled with `ps -o rss` after 4 s. No Laravel bootstrapped, so a real `queue:work` will be higher. |
| M4 | Supervisor | **13 536 kB** idle RSS; 54 152-byte binary | MEASURED **as a proxy only** | A 12-line headless Swift stub (`Timer` + `URLSession` probing `127.0.0.1` every 5 s, the ADR-001 D3 loop and nothing else), `swiftc -O`, sampled after 12 s. The real Supervisor adds checksum/signature verification, `SMAppService`, the ADR-003 secrets broker and logging. **PROVISIONAL budget: 32 MB.** |
| M5 | Laravel application + `vendor/` on disk | **82 184 KiB total, of which `vendor/` 81 524 KiB** (≈80 MiB) | MEASURED **as a proxy only** | Fresh `laravel/laravel` (Framework 13.35.0) + `livewire/livewire`, `composer create-project --no-scripts`, no `node_modules`, no built assets, in the scratchpad. The product's `app/` is empty today (S0-26 open). **PROVISIONAL budget: 150 MiB** per runtime version incl. `bootstrap/cache` and built assets. |
| M6 | SQLite database per 100 000 synced messages (headers, no bodies, with indexes) | **≈74 MB** (ADR-002 E1); `VACUUM INTO` snapshot of the 43.8 MB bench file → 42.6 MB (ADR-002 E3) | MEASURED (ADR-002, this Mac) | `packaging/fixtures/prototypes/adr-002/wal-contention-bench.php`. **FTS5 index size was not measured** — ADR-002 E1 measured FTS5 trigger *throughput*, not bytes. Open item for §7. |
| M7 | Optional local model download (ADR-004 D8, Ollama registry sizes, no selection made) | Phi-4-mini 2.5 GB · Llama 3.2 3B 2.0 GB (1B 1.3 GB) · Gemma 3 4B 3.4 GB (1B 806 MB) · Qwen2.5 7B 4.7 GB · Mistral 7B 4.4 GB | Vendor-stated, not measured | Registry metadata, 2026-10-10. §15.6 still requires the download to be *timed* in the lab. Never part of core-ready (§12.2). |
| M8 | FileVault query without privileges | `fdesetup status` → `FileVault is On.` / `FileVault is Off.` (exit 0, no prompt); `diskutil apfs list` prints a `FileVault:` line per volume | MEASURED | Confirms both read-only paths in §4.9 work as a normal user. |
| M9 | Loopback port probe | `nc -z 127.0.0.1 8787` → exit 1 (free) in < 10 ms | MEASURED | Shell parity for the `bind()` probe in §4.6. |

---

## 4. Minimum RAM and free disk — PROVISIONAL derivation

### 4.1 RAM

Idle resident set of the product's own processes, from §3:

| Process | Idle RSS | Label |
|---|---|---|
| core HTTP (FrankenPHP) | 97 MB | MEASURED (M2, trivial page; Laravel + OPcache will add tens of MB) |
| queue worker (`php-cli artisan queue:work`) | 67 MB floor | MEASURED (M3, without Laravel) |
| scheduler (`php-cli artisan schedule:work`) | 67 MB floor | MEASURED (M3, without Laravel) |
| Supervisor | 14 MB proxy → 32 MB budget | PROVISIONAL (M4) |
| **Sum, idle** | **≈245 MB measured parts → 265 MB with the Supervisor budget** | PROVISIONAL |
| Menu bar app (SwiftUI, `MenuBarExtra`) | not built (S0-25) — budget 80 MB | PROVISIONAL |
| Under load (PHP `memory_limit` 128 MB × concurrent requests, SQLite page cache, FTS5 queries) | budget 1 GB total for all four processes | PROVISIONAL |

Nothing above is near a machine limit: every Apple Silicon Mac Apple has sold ships with **at least
8 GB** of unified memory, and macOS 15/26 reserve several GB of that themselves. The constraint that
actually bites is the **optional local model** (M7): a 3–4 B-parameter Q4 model needs roughly its
file size again in RAM while loaded, on top of the browser the PWA runs in.

| Tier | Minimum RAM | Label | Preflight |
|---|---|---|---|
| **Core (no local model, or external/API provider)** | **8 GB** | PROVISIONAL — lower bound is the smallest shipped Apple Silicon configuration; product idle use ≈0.3 GB, load budget 1 GB | Blocker below 8 GiB physical (never expected to fire on supported hardware; kept because it is one `sysctl` and documents the floor) |
| **Core + local model (Ollama/LM Studio/MLX, optional)** | **16 GB recommended** | PROVISIONAL — model RSS (2–5 GB) + core + browser on an 8 GB machine leaves no headroom; must be measured on the minimum machine with the selected model before any number is published | Not a preflight item: surfaced on screen 5 **Choose AI** as a warning on < 16 GB machines, never blocks the install |

### 4.2 Free disk

Components on the volume holding `~/Library/Application Support/LaravelExecutiveAI/` (ADR-001 D4):

| Item | Size | Label |
|---|---|---|
| Runtime binary | 181 MB (upstream; curated build smaller) | MEASURED (M1) |
| `app/` + `vendor/` + caches per runtime version | 80 MiB measured proxy → 150 MiB budget | PROVISIONAL (M5) |
| **× 2 runtime versions** (`current` + `previous` kept for rollback, ADR-001 D4) | **≈ 660 MB** | PROVISIONAL (2 × (181 + 150)) |
| Database at 100 000 messages | 74 MB + WAL (≤ autocheckpoint 1 000 pages ≈ 4 MB, ADR-002 D6) | MEASURED (M6) |
| FTS5 index | **unmeasured** | PROVISIONAL — assume ≤ database size until §7 measures it |
| Backups (`VACUUM INTO` snapshots, ADR-002 D5) | ≈ 0.6 × database per retained snapshot; 3 retained ≈ 130 MB at 100 k messages | PROVISIONAL (ratio measured in ADR-002 E3, retention count is an ADR-002 D5 default) |
| Logs (`~/Library/Logs/LaravelExecutiveAI/`) | rotation budget 100 MB | PROVISIONAL |
| Installer DMG in `~/Downloads` during install | ≈ 200–250 MB until the user deletes it | PROVISIONAL (bundle not built) |
| **Total, core, at 100 k messages** | **≈ 1.3 GB** | PROVISIONAL |

| Tier | Minimum free disk | Label | Preflight |
|---|---|---|---|
| **Core** | **2 GB free** to start the install; **5 GB** recommended | PROVISIONAL — 1.3 GB derived total + APFS/Spotlight/snapshot headroom; the 2 GB figure must be re-derived from the measured install footprint in S1-14 | Blocker below 2 GB; warning below 5 GB (logged, not shown on screen 2) |
| **Core + local model** | core + **2 × the selected model's download size** (download + unpacked blobs) | PROVISIONAL — registry sizes (M7); the 2× factor is an assumption to be measured | Checked on screen 5 **Choose AI** against the model actually selected, not in preflight |

**S1-14 obligation.** Every PROVISIONAL cell in §4 is replaced by a value measured on the minimum
machine (§5) during S1-14, using the S0-20 timer. Until then the README and the installer copy say
"provisional". A PROVISIONAL number may become *stricter* as a result; it is never relaxed without a
measurement.

---

## 5. Lab machine profiles (§12.2) and the recommended third profile

| Profile | Spec | Role | Status |
|---|---|---|---|
| **Mac A** | clean M4 Max, 36–64 GB, macOS 26.x (§12.2) | Upper bound; the §12.2 p50/p95 gate | This Mac is an M4 Max 36 GB but is **not clean** (Herd, Xcode, Homebrew present). A clean user account or a wiped partition on it is the Sprint 1 fallback; a separate clean machine is the §12.2 letter. |
| **Mac B** | clean M3 Ultra (§12.2) | Second gate profile, desktop-class | Not available to the team today. |
| **Mac C — recommended, decision for Andreas** | **M1, 8 GB, 256 GB SSD** (e.g. MacBook Air M1 2020 or Mac mini M1 2020), running **macOS 15.x** — the floor OS on the oldest supported chip with the smallest shipped memory and the smallest common SSD | **The minimum machine.** Without it, §4 can never leave PROVISIONAL, and ADR-002 E1 and ADR-001 E3 cannot be re-run on the minimum profile as their Accepted criteria require. | Needs acquisition. Used units are inexpensive; a cloud Mac (e.g. AWS `mac2.metal`) is an M1 but ships with 16 GB, so it covers the chip but **not** the RAM floor and is only a partial substitute. |

Recommendation: acquire Mac C before S1-14 starts; keep it on macOS 15.x (do not upgrade it), with
FileVault **on** so the ADR-001 D3 "services run only after login" behaviour and the ADR-005 D2
lock-screen cases are measured where they are hardest, not where they are easiest.

---

## 6. Preflight checks (§12.1) — binding list for S1-05

Rules shared by every check:

- **Blocker** — install cannot continue; the Check screen (§12.3 screen 2) shows the message and
  one action. **Warning** — recorded in the install log and the diagnostics bundle, surfaced later
  (Done-screen checklist or menu bar), **never shown on screen 2** ("show blockers only").
- Every check runs in the GUI app without privileges. No check asks for an admin password or opens
  Terminal (§12.1, §12.2).
- Each check has a shell-parity command so a contributor can reproduce it on any Mac; the shell
  command is documentation, the Swift API is the implementation.
- Messages exist in English and Swedish; Swedish is shown when the user's first preferred language
  is `sv`. Only blockers have user-facing text.

Out of preflight by decision elsewhere: **Tailscale** detection (ADR-006 — post-core,
`REMOTE_ACCESS` only); **Ollama/LM Studio** detection (screen 5 **Choose AI**); **TCC permissions**
(Accessibility, Screen Recording — `PEEKABOO_PERMISSIONS`, optional path, ADR-005); **Login Items
approval** (`BOOT_SERVICES`, ADR-001 D3).

### 6.1 macOS version

| | |
|---|---|
| Checks | `ProcessInfo.processInfo.isOperatingSystemAtLeast(OperatingSystemVersion(majorVersion: 15, minorVersion: 0, patchVersion: 0))`; record `operatingSystemVersion` and `sysctl kern.osversion` (build) in the log. Shell parity: `sw_vers -productVersion`. |
| Class | **Blocker** if < 15.0. **Warning** if major > highest tested (27+ today). |
| EN | **This Mac is running macOS {version}. Laravel Executive AI needs macOS 15 or later.** Update macOS in System Settings › General › Software Update, then run the installer again. |
| SV | **Den här datorn kör macOS {version}. Laravel Executive AI kräver macOS 15 eller senare.** Uppdatera macOS i Systeminställningar › Allmänt › Programuppdatering och kör sedan installeraren igen. |

### 6.2 Chip (Apple Silicon)

| | |
|---|---|
| Checks | `sysctlbyname("hw.optional.arm64")` must be `1` **and** `sysctlbyname("sysctl.proc_translated")` must be `0` or absent (not running under Rosetta). Record `hw.model` and `machdep.cpu.brand_string`. Shell parity: `sysctl hw.optional.arm64 sysctl.proc_translated`. |
| Class | **Blocker.** |
| EN | **This Mac has an Intel processor. Laravel Executive AI runs only on Macs with Apple Silicon (M1 or later).** Intel Macs are not supported and no Intel version is planned. |
| SV | **Den här datorn har en Intel-processor. Laravel Executive AI fungerar bara på Mac-datorer med Apple Silicon (M1 eller senare).** Intel-datorer stöds inte och ingen Intel-version är planerad. |

### 6.3 RAM

| | |
|---|---|
| Checks | `ProcessInfo.processInfo.physicalMemory` (== `sysctl hw.memsize`) ≥ 8 GiB. Shell parity: `sysctl -n hw.memsize`. |
| Class | **Blocker** below 8 GiB (PROVISIONAL threshold, §4.1). No warning tier in preflight; the local-model advice lives on screen 5. |
| EN | **This Mac has {ram} of memory. Laravel Executive AI needs at least 8 GB.** |
| SV | **Den här datorn har {ram} minne. Laravel Executive AI kräver minst 8 GB.** |

### 6.4 Free disk

| | |
|---|---|
| Checks | `try URL(fileURLWithPath: applicationSupportDirectory).resourceValues(forKeys: [.volumeAvailableCapacityForImportantUsageKey]).volumeAvailableCapacityForImportantUsage` on the volume that will hold `~/Library/Application Support/LaravelExecutiveAI/` (the "important usage" value already accounts for purgeable space, which is what the user can actually get). The disk *estimate* shown on screen 2 is the §4.2 core total for a fresh install (no model). Shell parity: `df -h ~/Library`. |
| Class | **Blocker** below 2 GB (PROVISIONAL, §4.2). **Warning** below 5 GB. |
| EN | **Not enough free space. The install needs {required} free on this disk and {available} is available.** Free up space (Apple menu › About This Mac › Storage) and run the installer again. |
| SV | **För lite ledigt utrymme. Installationen behöver {required} ledigt på den här disken och {available} är tillgängligt.** Frigör utrymme (Apple-menyn › Om den här datorn › Lagring) och kör installeraren igen. |

### 6.5 Permissions (filesystem and session)

| | |
|---|---|
| Checks | (a) `FileManager.default.createDirectory` of a temporary subdirectory under `~/Library/Application Support/LaravelExecutiveAI/.preflight-<uuid>` succeeds and is removed — proves write access and that the volume is not read-only or a network mount; (b) the current user is the console user (`SCDynamicStoreCopyConsoleUser`) and is **not** the Guest account (`NSUserName() != "Guest"`, home is wiped at logout) and not `root`; (c) the home volume is not case-sensitive-incompatible for the runtime — informational only, logged. TCC (Accessibility, Screen Recording, Automation) is **not** checked here. Shell parity: `touch "$HOME/Library/Application Support/.lxai-preflight" && rm "$_"`; `stat -f %Su /dev/console`. |
| Class | **Blocker** if (a) fails or (b) is Guest/root. |
| EN | **Laravel Executive AI can't write to this user's Library folder.** Log in with a standard user account that has a permanent home folder (not Guest), and make sure the disk is not read-only. |
| SV | **Laravel Executive AI kan inte skriva till den här användarens Bibliotek-mapp.** Logga in med ett vanligt användarkonto som har en permanent hemmapp (inte Gäst) och kontrollera att disken inte är skrivskyddad. |

### 6.6 Port collisions

| | |
|---|---|
| Checks | Preferred loopback port **8787** (placeholder from ADR-006 until `runtime.json` fixes it), then fallbacks `8788–8799`. For each: `socket(AF_INET, SOCK_STREAM)`, **no** `SO_REUSEADDR`, `bind()` to `127.0.0.1:<port>`, `close()`. The first port that binds is recorded for `BOOT_SERVICES` and written into the Caddyfile (ADR-001 D3 "fixed port from preflight"). An existing install answering `GET /health/live` with the S0-14 schema on the preferred port is **not** a collision — it is handled by 6.8. Shell parity: `nc -z 127.0.0.1 8787` (exit 1 = free). |
| Class | **Warning** if the preferred port is taken and a fallback is free (logged with the owning process from `lsof -nP -iTCP:<port> -sTCP:LISTEN` if readable). **Blocker** only if every candidate is taken. |
| EN | **Another program is using every network port Laravel Executive AI can run on (127.0.0.1:8787–8799).** Quit the program using those ports and run the installer again. |
| SV | **Ett annat program använder alla nätverksportar som Laravel Executive AI kan köra på (127.0.0.1:8787–8799).** Avsluta programmet som använder portarna och kör installeraren igen. |

### 6.7 Network state

| | |
|---|---|
| Checks | `NWPathMonitor` — wait ≤ 2 s for the first `pathUpdateHandler`; record `status` (`.satisfied` / `.unsatisfied`), `isExpensive`, `isConstrained`, `availableInterfaces` types. No request is sent to any server during preflight. Shell parity: `scutil -r 1.1.1.1` (reachability flags only, no traffic). |
| Class | **Warning** when `.unsatisfied`: core install proceeds offline because the runtime ships inside the bundle (§12.1 "no hidden prerequisites"); `PACKAGE_VERIFY` re-checks only if it must fetch from the release manifest, and that state owns its own error. `isExpensive`/`isConstrained` is passed to screen 5 so a model download is never started silently on a metered link. |
| EN / SV | *No blocker text — this check never blocks.* |

### 6.8 Existing install

| | |
|---|---|
| Checks | ADR-001 D4 paths: `~/Library/Application Support/LaravelExecutiveAI/runtime/current` (symlink target + its `manifest.json` version), `…/data/database/executive.sqlite` (exists, readable, owner == current user), `SMAppService.agent(plistName: "com.stafe.laravelexecutiveai.supervisor.plist").status` (`.enabled`, `.requiresApproval`, `.notRegistered`, `.notFound`), and whether something on the chosen port answers `/health/live` with our schema (version + `instance_id`). Outcome routes the state machine: **nothing found** → fresh install; **found, same or older `manifest.json` version** → data-preserving reinstall/upgrade path (§12.2 "data-preserving reinstall"); **found, newer version** → blocker (no downgrade). Shell parity: `readlink "$HOME/Library/Application Support/LaravelExecutiveAI/runtime/current"`. |
| Class | **Warning** (routing information) in the first two cases. **Blocker** for a newer-version install or a `data/` directory owned by another user. |
| EN | **A newer version of Laravel Executive AI ({installed}) is already installed. This installer is version {this}.** Download the latest installer, or open the installed app and let it update itself. |
| SV | **En nyare version av Laravel Executive AI ({installed}) är redan installerad. Den här installeraren är version {this}.** Hämta den senaste installeraren, eller öppna den installerade appen och låt den uppdatera sig själv. |

### 6.9 FileVault state

| | |
|---|---|
| Checks | There is no public Swift API. Run `/usr/bin/fdesetup status` via `Process` (works without privileges and without an Authorization prompt — **MEASURED** on this Mac, M8; output `FileVault is On.` / `FileVault is Off.`). Fallback if `fdesetup` is missing or returns non-zero: parse the `FileVault:` lines of `/usr/sbin/diskutil apfs list` for the volume holding the home directory (read-only, verified M8). Record the result in the install log and the diagnostics bundle. |
| Class | **Warning only, never a blocker.** Off → the Done-screen checklist recommends enabling FileVault because the SQLite database is not application-encrypted in v1 (ADR-003 D6) and the Keychain items (ADR-003 D1) are the only at-rest-encrypted secrets; On → the installer and `docs/operations/` note that services start only after login (ADR-001 D3) and scheduled automation is skipped while the Mac is at the pre-boot screen (ADR-005 D2). |
| EN / SV | *No blocker text.* |

### 6.10 Trusted developer signature (self-check)

| | |
|---|---|
| Checks | For the GUI app bundle, the Supervisor bundle and the runtime binary (the three signed executables of ADR-003 D5): `SecStaticCodeCreateWithPath` → `SecStaticCodeCheckValidityWithErrors(code, SecCSFlags(rawValue: kSecCSCheckAllArchitectures | kSecCSStrictValidate | kSecCSCheckNestedCode), requirement, &error)` with the requirement `anchor apple generic and certificate leaf[subject.OU] = "<TEAMID>"` (`<TEAMID>` is the A12 placeholder; the requirement string is generated from the app's own `SecCodeCopySigningInformation` designated requirement so a Team ID change is one constant). Additionally compare the runtime binary's SHA-256 with `manifest.json` (ADR-001 D4) — this is the same verification `PACKAGE_VERIFY` repeats before anything executes. Notarization is not re-checked at runtime: Gatekeeper enforced it at first launch on every supported macOS (§1). Shell parity: `codesign --verify --deep --strict --verbose=2 "/Applications/Laravel Executive AI.app"` and `spctl --assess --type execute -v …`. |
| Class | **Blocker** if any of the three fails validity or the Team ID requirement. |
| EN | **This copy of Laravel Executive AI is not correctly signed and may have been modified.** Delete it, download the installer again from the official release page, and verify the download before opening it. |
| SV | **Den här kopian av Laravel Executive AI är inte korrekt signerad och kan ha ändrats.** Radera den, hämta installeraren igen från den officiella releasesidan och verifiera nedladdningen innan du öppnar den. |

### 6.11 Summary table

| # | Check | Blocker when | Warning when | Shown on screen 2 |
|---|---|---|---|---|
| 1 | macOS version | < 15.0 | > highest tested major | blocker only |
| 2 | Apple Silicon | not arm64, or translated | — | blocker only |
| 3 | RAM | < 8 GiB (PROVISIONAL) | — | blocker only |
| 4 | Free disk | < 2 GB (PROVISIONAL) | < 5 GB | blocker only |
| 5 | Permissions | cannot write Application Support; Guest/root | — | blocker only |
| 6 | Port collision | all of 8787–8799 taken | preferred port taken | blocker only |
| 7 | Network | — | offline / expensive / constrained | never |
| 8 | Existing install | newer version; foreign-owned data dir | existing install found (routing) | blocker only |
| 9 | FileVault | — | off (recommend) / on (login note) | never |
| 10 | Developer signature | any of three executables invalid | — | blocker only |

Ordering is 1 → 10 as listed; all ten run even after the first blocker so the log is complete, but
screen 2 lists blockers in this order and the first one carries the primary action.

---

## 7. What Sprint 1 must measure (feeds S1-14)

Each line is a PROVISIONAL value above or an Accepted-criterion of an ADR; S1-14's
`docs/operations/install-measurements/2026-sprint1.md` reports every one as found, never adjusted.

- [ ] **Minimum machine (Mac C, M1 8 GB, macOS 15.x)** acquired and set up clean with FileVault on
      (§5) — *decision for Andreas; blocks everything below that says "minimum machine".*
- [ ] Idle RSS of all four product processes (core HTTP with Laravel booted, `queue:work`,
      `schedule:work`, real Supervisor) on the minimum machine, 10 min after `ready`; replaces §4.1.
- [ ] Peak RSS of the same four during (a) the first brief generation and (b) an 8-worker email sync
      of the 100 k-message fixture (§5.6 criterion 3); replaces the 1 GB load budget.
- [ ] Menu bar app RSS idle and with a brief rendered; replaces the 80 MB budget.
- [ ] Curated FrankenPHP build size (ADR-001 D1) and the installed `runtime/<version>/` directory
      size including `app/`, `vendor/`, `bootstrap/cache`, built assets; replaces M1/M5 budgets.
- [ ] Total disk delta of a fresh install measured as `volumeAvailableCapacityForImportantUsage`
      before DMG open and after `ready` (the S0-20 timer records both); replaces the 2 GB / 5 GB
      thresholds.
- [ ] Disk delta after one runtime upgrade (two versions coexisting) and after rollback.
- [ ] FTS5 index size for the 100 k-message fixture (ADR-002 E1 did not measure bytes).
- [ ] `VACUUM INTO` snapshot size and count under the ADR-002 D5 retention default; replaces the
      backup line in §4.2.
- [ ] ADR-002 E1 WAL-contention bench re-run on the minimum machine (its Accepted criterion).
- [ ] ADR-001 E3 cold start re-run on the curated, Developer-ID-signed binary on the minimum machine
      (its Accepted criterion), including the first-launch code-directory validation cost.
- [ ] With-model tier: RAM and disk with the one model ADR-004/ADR-007 select, measured on the
      minimum machine; the "2 × download size" assumption confirmed or replaced; download *timed*
      (§15.6) and reported separately from core-ready (§12.2).
- [ ] Every preflight check in §6 executed on macOS 15.x (minimum machine) and 26.x (Mac A) with
      the shell-parity command, and each blocker forced once (Intel check via `arch -x86_64` on a
      Rosetta-translated test binary; disk via a filled sparse image; port via a listener on 8787–8799;
      signature via a deliberately re-signed copy) to prove the message and action appear.
- [ ] Highest-tested version bumped in §1 to the exact build used, and the §1 "tested set" row for
      Sprint 1 filled in.
- [ ] README "Requirements" updated in the same PR, PROVISIONAL labels removed only where a line
      above is checked.

---

## Blueprint references

§2 (macOS 15+, Apple Silicon, Intel yes/no), §12.1 (preflight list, no shell commands), §12.2 (lab
profiles, gates, no admin password), §12.3 screen 2 (blockers only), §15 item 1, §17.
