# Install measurement protocol (§12.2 gate)

> **Blueprint refs:** §0 (definition of the five-minute goal; what is async), §12.1 (installer
> states), §12.2 (measurable gates), §12.3 (first-run screens), §16 step 6 (install-timer runner),
> §17 (an outside contributor can reproduce it).
> **Owner:** I (QA/release) · **Tool:** `packaging/manifest/install-timer/` (S0-20, #20)
> **Consumers:** S1-14 (first clean install measured), `docs/operations/release-policy.md` §5
> (release checklist row "§12.2 install-measurement gate").
> **Status:** Binding from Sprint 1. The gate blocks releases from the first stable release;
> Sprint 1 measures and records only.

The blueprint calls the five-minute install a **release gate to be proven, not a promise before
measurement** (§0). This document is the protocol that makes the proof reproducible by someone who
has never talked to the team: what machine, what counts, where the stopwatch starts and stops, how
many runs, and how to hand the numbers in. Numbers are reported as found. A number that misses the
target is a performance issue with an owner — never an adjusted number.

---

## 1. What is measured

### 1.1 Core-ready

**`core_ready_ms` = `WELCOME` → `READY`**, wall clock, on the machine under test:

- **`WELCOME`** — the installer's Welcome screen (§12.3 screen 1) is on screen. The DMG has been
  opened, Gatekeeper has accepted the signature, the app has launched.
- **`READY`** — the installer has received `ready: true` from `GET /health/ready`
  (`docs/architecture/health-contract.md`: HTTP, migrations, login, menu-bar heartbeat, brief
  placeholder, queue worker and scheduler self-test all `ok`) and shows the Done screen
  (§12.3 screen 6).

Between the two, every §12.1 state is timed: `PREFLIGHT`, `PACKAGE_VERIFY`, `INSTALL_CORE`,
`CREATE_OWNER`, `SELECT_AI`, `BOOT_SERVICES`, `HEALTH_CHECK`. A state starts when it is entered
and ends when the next one is entered.

**Human prompts are inside core-ready** (§12.2: "include DMG open, user prompts and runtime
initialization"). They are additionally recorded as their own phase, `human_interaction_ms`, so a
slow installer and a slow typist can be told apart — but neither is subtracted.

The §12.2 targets apply to `core_ready_ms`: **p50 < 180 000 ms and p95 < 300 000 ms**, strictly
less, per machine profile, over at least 30 runs.

### 1.2 Not core-ready: asynchronous post-core steps

§0 lists what happens **after** core-ready and must never be hidden inside the five-minute figure:
large model downloads, Google OAuth, GitHub Apps, iCloud/HEY, Peekaboo permissions, Tailscale
login, iOS push, e-mail verification. The installer's optional path (§12.1 `CONNECT_EMAIL →
CONNECT_GITHUB → PEEKABOO_PERMISSIONS → REMOTE_ACCESS → SCHEDULE_BRIEF`) belongs here too.

These are recorded as `async_post_core.steps`, each with its own start, end, duration and outcome,
and reported in a separate table labelled **network-conditional**. The record format has no field
in which they could be added to `core_ready_ms`, the schema rejects extra fields, and the gate reads
only `core_ready_ms`. They are measured because they matter to the user — not because they count.

An install without a model or API key is still **core-ready**: the health contract's `ready` does
not depend on `ai_ready`, and the Done screen shows **AI setup required**. A run must not be
discarded, extended or re-labelled because AI was not set up.

### 1.3 Also recorded, never gated

| Field | Why it exists |
|---|---|
| `dmg_open_to_welcome_ms` | §12.2 says "include DMG open"; §0 says "from the first click on Install". The protocol defines the gated window as `WELCOME` → `READY` (the installer can emit both events itself), and the DMG-open gap is timed separately when the operator records a `DMG_OPEN` event, so nobody can hide a slow Gatekeeper check. If a campaign shows this gap is material (> 10 s p95), raise it in the S1-14 report. |
| `runtime_cached` | ADR-001 E3: the first launch of a freshly signed 180 MB runtime costs ~3.5 s of code-directory validation; later launches ~70 ms. The §0 target is stated for cached/signed runtimes. Both are measured; the gate defaults to cached runs and the report shows both. |
| `scenario` | Re-install, upgrade and data-preserving re-install are timed for the §12.2 coverage requirement; only `fresh_install` is gated. |
| `machine.macos_version` | The support matrix (S0-22) spans several macOS releases; the summary lists the versions per profile. |

---

## 2. Machine profiles and the clean-machine definition

| Profile | Hardware (§12.2) | macOS |
|---|---|---|
| `mac-a` | Apple M4 Max, 36–64 GB unified memory | a supported version from the S0-22 matrix (ADR-001 D5: 15.0 through 26.x) |
| `mac-b` | Apple M3 Ultra | same |
| `other` | anything else (a developer's Mac, a CI runner) | — |

Runs on `other` are welcome as early signal and are reported, but the gate is evaluated on `mac-a`
and `mac-b` only. Both profiles need ≥ 30 runs.

A **clean machine** (`--clean yes`) means, for every run:

1. The product has never been installed on this macOS user account, **or** the previous install
   was removed with the product's own uninstaller including user data, and
   `~/Library/Application Support/<bundle id>/`, `~/Library/Caches/<bundle id>/`,
   `~/Library/Preferences/<bundle id>.plist`, the Keychain items the product created, and any
   `launchd` agent it registered are gone. Verify with the uninstall checklist in
   `docs/operations/` once S1-07 lands; until then, verify by hand and say so in `--note`.
2. No Homebrew, Docker, MySQL, Redis, Herd, Valet, Ollama or LM Studio is **required**. Having
   them installed is allowed and must be noted; the install must not use them (§12.1). A run in
   which the installer picked up a user-installed PHP is a bug report, not a measurement.
3. Gatekeeper is on. The DMG is the signed and notarized artefact from CI (a Sprint 1 debug build
   or a release candidate), downloaded — not copied from a build directory — so the quarantine
   attribute is present.
4. The machine is on mains power, not thermally throttled (no build, no backup, no Spotlight
   re-index running), with a working network connection. Network speed is noted but does not
   excuse anything in core-ready; by design no core state depends on a download.
5. FileVault state, macOS version and build are noted. Preflight checks these; so do we.

`--runtime-cached yes` means the same DMG was installed and uninstalled on this machine at least
once before this run (so the runtime's code directory has been validated by the kernel) **or** the
runtime bundle has been launched once since it was signed. The first install on a freshly imaged
machine is `--runtime-cached no`; it is a real number too — record it.

---

## 3. Running one measurement

Prerequisites: the DMG under test, the repository checkout (any branch; only
`packaging/manifest/install-timer/` is used), Node 24+ on the machine that will run the tool. The
tool does **not** have to run on the machine under test — the events file can be copied over.

1. **Reset** the machine to the §2 definition. Reboot if anything heavy was running.
2. **Start the capture.** Until the installer writes its own event log (S1-05 must emit the events
   in section 4), the operator times with a stopwatch app that logs timestamps, or with a screen
   recording whose frame timestamps are read afterwards. Record every transition listed in section
   4 to the second or better.
3. **Install**, exactly as a first-time user would, following the screens in order. Do not skip
   the owner form; type a realistic name and e-mail. On the AI screen, choose what the campaign
   says (default: *skip* — core-ready without AI is the baseline; a second campaign may choose a
   detected local runtime). Never download a large model during the measured window; the download
   is an async step.
4. **Stop** when the Done screen appears (`READY`). Keep timing the optional steps you perform
   afterwards as async steps, each with start, end and outcome.
5. **Write the record** on any machine:

   ```bash
   node packaging/manifest/install-timer/install-timer.mjs record \
       --profile mac-a --runtime-cached no --clean yes \
       --events install-events.json \
       --out packaging/manifest/install-timer/results/2026-sprint1/ \
       --product-version 0.1.0-alpha.3 --commit <sha> \
       --note "FileVault on; Ollama present but not used; 100/100 Mbit"
   ```

   On macOS the chip, memory and OS version are read from `sysctl`/`sw_vers`. Writing the record
   on a different machine than the one measured: pass `--chip "Apple M3 Ultra" --memory-gb 96
   --macos-version 26.6.2 --macos-build 25G83` explicitly.
6. **Uninstall** via the product's uninstaller, verify the §2 list, and go to step 1. For a
   `--runtime-cached yes` run, perform one throw-away install-and-uninstall first.

A run that does not reach `READY` yields no record. Note it as an attempt in the campaign summary
(`attempts: 33, records: 31`) and open an issue with the installer log — a failed install is a
finding, not noise.

---

## 4. Events the installer must emit (contract for S1-05)

The installer state machine writes one line per transition to its install log, exactly in this
shape, so the record can be built with no human stopwatch:

```json
{"state": "WELCOME", "at": "2026-10-10T09:00:04.200+02:00"}
{"state": "human_interaction_start", "at": "2026-10-10T09:00:04.200+02:00"}
{"state": "human_interaction_end", "at": "2026-10-10T09:00:07.900+02:00"}
{"state": "PREFLIGHT", "at": "2026-10-10T09:00:07.900+02:00"}
…
{"state": "READY", "at": "2026-10-10T09:02:01.800+02:00"}
{"state": "MODEL_DOWNLOAD", "marker": "start", "at": "2026-10-10T09:02:05.000+02:00"}
{"state": "MODEL_DOWNLOAD", "marker": "end", "outcome": "completed", "at": "2026-10-10T09:09:48.000+02:00"}
```

- One event at **entry** into each of the nine §12.1 states, in order, exactly once. Re-entering a
  state (cancel/resume) ends the measured run; log it, but that run is a resume test, not a
  timing sample.
- `human_interaction_start` when a screen begins waiting for the person (the Welcome CTA, the owner
  form, the AI choice, any sheet that needs a click or the admin password);
  `human_interaction_end` when the person's input has been accepted and the installer proceeds.
  No nesting.
- `READY` only after `/health/ready` returned `ready: true` — never on a timer, never on
  `/health/live`.
- Every optional step as `marker: start` / `marker: end` with `outcome`, using the names in
  `packaging/manifest/install-timer/README.md`. A step that begins before `READY` (a background
  model download started from the AI screen is allowed by §12.1) is still an async step; the record
  flags it as overlapping the core window so the report shows it.
- Timestamps in RFC 3339 with milliseconds from a monotonic-backed wall clock; the whole run on one
  clock.
- Optional `{"state": "DMG_OPEN", …}` if the app can determine when the DMG was mounted; otherwise
  the operator adds it.

The log must never contain tokens, the owner's password or e-mail content (§11); the tool copies
nothing from the events except `state`, `at`, `marker` and `outcome`.

---

## 5. The gate

```bash
node packaging/manifest/install-timer/install-timer.mjs gate \
    packaging/manifest/install-timer/results/<campaign>/ \
    --p50 180000 --p95 300000 --min-runs 30 --profiles mac-a,mac-b --runtime-cached yes \
    --out packaging/manifest/install-timer/results/<campaign>/gate.json
```

Eligible runs: not synthetic, `clean_machine: true`, `scenario: fresh_install`, matching
`runtime_cached`. Per profile the gate needs ≥ 30 eligible runs and `p50 < 180 s`, `p95 < 300 s`.
Exit code 0 only when both profiles pass. Percentiles are nearest-rank: the p95 is a time that
actually happened.

`--allow-insufficient` relaxes the run count for interim reporting (S1-14: "the first credible
run and an honest number, not a passing number"). It never relaxes the thresholds, and the output
says **GATE NOT PROVEN**, never **GATE PASS**, while any profile is short of runs.

The release checklist row (`release-policy.md` §5, "§12.2 install-measurement gate") links the
campaign's `summary.json` and `gate.json` as evidence.

---

## 6. Submitting results

1. Commit the raw records under `packaging/manifest/install-timer/results/<campaign>/` — one file
   per run, unedited, named by the tool. Add the campaign's `summary.json` and `gate.json`.
2. Write or update the campaign page `docs/operations/install-measurements/<campaign>.md`
   (S1-14 creates the first): machine details per §2, DMG version and commit, attempts vs.
   records, the per-phase table from `aggregate`, the slowest mandatory phase **by name**, the
   async table, and any deviation from this protocol.
3. Open a PR titled `test(release): add <campaign> install measurements` referencing the campaign
   issue. If p50 or p95 misses the target, open a `perf` issue with a named owner from the PR and
   link it; the numbers stay as measured.
4. Outside contributors: same path. Include the machine profile, macOS version and whether the
   runtime was cached; use `--profile other` unless the machine matches §2 exactly. A PR with
   records is reviewable by anyone because the tool re-validates every record and recomputes
   every percentile.

---

## 7. What this document does not decide

- The uninstall procedure and its verification list (S1-07, `docs/operations/uninstall.md`).
- The exact support-matrix floor and the minimum RAM/disk (S0-22).
- Whether the installer or an external harness drives the install in automated e2e runs
  (S1-15). This protocol works with either, as long as the events in section 4 are emitted.
