# AI state: "AI setup required", never a false "AI ready" (S1-11)

Blueprint §0: without an available model or API key the app is installed and clearly shows **AI
setup required**, never a false **AI ready**. §12.2: an install without an external LLM account has
an operational PWA and CRM with a "Select AI" banner and does not count as AI-ready; AI-ready
requires a successful model completion. §12.3 screen 5 / §1.1: Choose AI presents neutral options,
detects an installed local runtime and suggests it **without preselecting a brand**, and exposes
only options a real adapter supports. This document is how the Laravel core and the Mac app
implement those three rules the same way. The field semantics are the health contract's
([health-contract.md](health-contract.md) §3, §7); this page adds the mechanism.

## 1. How `ai_ready` is gated

```text
config lea.ai.provider (LEA_AI_PROVIDER)          AiCompletionSuccessMarker (cache, no expiry)
        │                                                  │   written only by
        │ null / invalid ──▶ not_configured                │   RecordAiCompletionSuccess::record()
        │                                                  │
        └ "ollama" ──┬── no marker, or marker.provider ≠ "ollama" ──▶ configured_untested
                     └── marker.provider == "ollama" ──────────────▶ ready  (ai_last_success_at = marker.at)
```

`App\Support\Health\ConfiguredProviderAiReadiness` is the Sprint 1 `AiReadiness` binding:

| Input | `ai_state` | `ai_ready` | `ai_provider` | `ai_last_success_at` |
|---|---|---|---|---|
| no selected provider | `not_configured` | false | null | null |
| selected provider, no recorded success for **that** provider | `configured_untested` | false | the id | null |
| selected provider and a recorded success on it | `ready` | true | the id | the success time |

- **The only writer** of the success marker is `App\Support\Health\RecordAiCompletionSuccess`.
  It is to be called from the AI run path after `AiProviderAdapter::generate()` or a completed
  `stream()` returned a usable response — never from the installer, a models list, a connectivity
  probe or a health check. **Sprint 2's run loop is the first caller.** Until it lands nothing in
  `app/` references the class, and `tests/Feature/Health/AiReadinessTest.php` asserts exactly that,
  so no Sprint 1 install can report AI ready. Sprint 2 replaces that assertion with the run loop's
  own test and should rebind `AiReadiness` over `ai_runs` (§3.1) when that table exists; the marker
  is the bridge until then.
- **Provider-bound.** The marker stores `{provider, model, at}`. A success recorded on another
  provider does not count, so switching provider drops back to `configured_untested` by
  construction. The provider-selection path (S1-07 → core) must also call
  `RecordAiCompletionSuccess::reset()` so that switching *back* does not resurrect an old success
  — the contract's "changing provider or model **always** resets `ai_ready`".
- **Fails safe.** The marker lives in the cache store without expiry. Losing the cache (`cache:clear`,
  a fresh data directory) returns the install to "AI setup required"; it can never produce a
  false "ready".
- **`error`** is reported once Sprint 2 records failed runs; until then a failed attempt leaves the
  state where it was. The PWA, the menu bar and the installer already render it.
- Reproducing the fixtures: `Tests\Support\CoreReadyState::create()` is the core-ready world
  (`ready-core-ready-no-ai.json`); `CoreReadyState::aiReady()` adds the recorded completion that
  makes it `ready-ai-ready.json` (`ai_provider: ollama`, `ai_last_success_at: 2026-10-10T17:45:12+02:00`).

## 2. Which providers may be offered

`packaging/fixtures/ai-providers/ai-providers.json` is the one catalogue (schema
`ai-providers.schema.json`, validated in CI). Each row is `{id, display_name, kind, driver,
adapter_available}`; `adapter_available` is the honesty flag and the only field that changes over
time — it flips to `true` in the PR that lands a bound `AiProviderAdapter` for that provider.

| Side | Mirror | Pinned by |
|---|---|---|
| Laravel | `App\AI\Providers\SupportedProviders::catalogue()` | `tests/Unit/AI/SupportedProvidersTest.php` — equals the fixture; `adapter_available` ⇔ `app()->bound(SupportedProviders::adapterBinding($id))` |
| Swift | `AiProviderCatalog.all` (`Install/AiSelection.swift`) | `ChooseAiScreenTests.testCatalogueMirrorsTheSharedFixture` — equals the fixture; ids resolve to `LocalAiRuntime` / `AiProviderID` with the same display names |
| Copy | `lang/en/ai.php`, `lang/sv/ai.php` `providers` | `SupportedProvidersTest` — keys equal the catalogue ids |

`SupportedProviders::supported()` returns the rows whose flag is true **and** whose adapter is
actually bound under `AiProviderAdapter::class.'@'.$id`. An adapter lands by (1) binding it under that
abstract, (2) flipping the fixture flag, (3) nothing else — both mirrors and both tests then force the
two sides to agree in the same PR.

**S2-13 did exactly that for all five rows.** `App\Providers\AiServiceProvider` binds a
`LaravelAiAdapter` per catalogue id and the flag is now true in the fixture, the PHP mirror and the
Swift mirror; the binding is lazy, so "an adapter exists in this build" is answered without needing a
URL or a Keychain key the owner may not have supplied yet. With no adapter bound at all — a build that
lost the bindings, or a future row added ahead of its adapter — both the PWA page and the installer
still say so instead of listing anything, and that branch keeps its own test on each side. See
[ai-providers.md](ai-providers.md) §7.

The catalogue is ADR-004 D1/D6: local `ollama` and `openai-compatible` (LM Studio, custom
endpoints), external `openai` and `anthropic`. Gemini and OpenRouter from §1.1 are not in v1 and
therefore not in the catalogue; adding one is a fixture row plus the adapter.

## 3. Where each surface shows it

| Surface | Setup required (`not_configured`, `configured_untested`) | Error | Ready |
|---|---|---|---|
| **PWA header badge** (`components/ai-state-badge`) | "AI setup required" / "AI-konfiguration krävs" | "AI error" / "AI-fel" | "AI ready" / "AI redo" |
| **PWA banner** (`components/ai-state-banner`, `/home` and `/settings/ai`) | banner + **Select AI** → `/settings/ai` | banner + **Reconfigure AI** | not rendered |
| **PWA `/settings/ai`** (`AiSettingsController`, `settings.ai`) | current provider line ("… so AI is not ready"), the options list | same | "… has completed a request" |
| **Menu bar status chip** (`PanelModel.aiStatusText`) | "AI setup required" | "AI error" | "AI ready" |
| **Menu bar AI row** (`aiProviderText`, `aiLastSuccessText`, `aiAction`) | provider (+ "no completion has succeeded yet"), **Set up AI** | provider, **Reconfigure AI** | provider, **Last successful completion: …**, no action |
| **Installer Done screen** | "AI setup required" card unless `ai_state == ready` | — | no card |

All PWA wording comes from `App\Http\View\AiStateCopy` over `lang/{en,sv}/ai.php`; all menu bar
wording from `MenuBarCopy` (`MenuBarStrings.en` / `.sv`, chosen by `Locale.current`'s language,
`MenuBarCopy.use(_:)` to pin). "AI ready" exists on exactly one key and one code path per side:

- Laravel: `AiStateCopy::label(AiState::Ready)` → `ai.state.ready`. `tests/Feature/Pwa/AiReadyGateTest.php`
  sweeps `/home`, `/briefs`, `/briefs/{brief}` and `/settings/ai` in every `AiState` but `Ready`, in
  English and Swedish, and asserts the phrase never appears; in `Ready` it must appear on every page
  and the banner must be gone. The Ready case is also reached through the real path
  (`CoreReadyState::aiReady()`), not only a stub. `ManifestTest` keeps the single-code-path check.
- Swift: `PanelModel.aiStatusText` returns `MenuBarCopy.aiReady` only for `ai_state == ready &&
  ai_ready == true`. `MenuBarAiReadyGateTests` runs every health fixture plus a synthetic document
  for every `ai_state × ai_ready` pair (including an unknown future state), in both languages,
  through every status-section text (`aiStatusText`, `state.title`, `state.detail`, provider,
  last success, action) and asserts the phrase appears iff AI is ready. `MenuBarCopyTests` greps
  both language tables for secret markers.

The "Select AI" / "Reconfigure AI" affordances open `/settings/ai` (PWA) and `openSettings()` (menu
bar) until the deep link into the Mac's SELECT_AI re-entry exists (S1-07 Settings › Local Models).

Captures (fixture data only): PWA [home banner](images/pwa/home-ai-setup-required-desktop-1400.jpg)
and [`/settings/ai`](images/pwa/settings-ai-desktop-1400.jpg); menu bar
[AI setup required](images/menubar/05-ai-setup-required.png) and [AI ready](images/menubar/01-fresh.png)
with the provider and last-success lines; installer [Choose AI](images/installer/05-choose-ai.png)
with the suggested-not-selected Ollama card.

## 4. SELECT_AI (installer screen 5)

`ChooseAiOptions` derives what the screen may show from the catalogue and
`LocalAiRuntimeDetecting`; `ChooseAiDraft` is the person's choice and starts empty:

- **No preselection.** `ChooseAiDraft()` has `choice == nil` and `provider == nil`; Continue is
  disabled until a choice is made — "Skip for now" is a choice. The own-key picker opens on
  "Choose a provider", never on a brand.
- **Only adapter-backed options.** Detected runtimes whose catalogue row has `adapter_available`
  are options ("Use Ollama on this Mac", tagged *Detected*); remote providers with an adapter fill
  the key picker. With no adapter in the build (today) the screen offers Skip, says why, and shows
  no provider list.
- **Suggested, not selected.** A detected runtime without an adapter is a *Detected: Ollama* card:
  visible, explained, not a button, not selected.

`ChooseAiScreenTests` pins the catalogue equality, the empty first render, the suggestion-only
behaviour, the option list against the selectable set, and renders the screen from the installer
harness (`LXAI_INSTALL_SCREENSHOT_DIR` writes `docs/architecture/images/installer/05-choose-ai*.png`).
The installer's state machine, `AiSelectionApplying` and the Supervisor hand-off are unchanged
(S1-05, S1-07).

## 4a. The privacy mode the surfaces report (S2-14)

Every surface that displays a privacy mode reads it from `App\Security\Privacy\PrivacyModeStore`,
which returns the live `privacy_policies` row — **not** `config('lea.privacy_mode')`, which is now
only the value the install-time default row is written from and the fallback for a boot before
migrations. That applies to `/health/ready`'s `privacy_mode` field, the menu bar that polls it, and
the `$shell` the PWA layout renders on every page.

The reason is threat **T-68**'s second half: "model traffic leaves the Mac while the UI may still
suggest otherwise". A build that enforced the stored mode while displaying the configured one would
tell the owner the opposite of the truth at exactly the moment it mattered, and the two values can
genuinely differ — the config is a packaged default, the row is what the owner last chose.

Which providers SELECT_AI and `/settings/ai` may offer follows the same policy. `ProviderRegistry`
takes the stored mode **and** the stored `LOCAL_ONLY_ALLOW_LAN` flag, so `buildable()` and
`disabledBy()` answer for the policy in force, and a provider is greyed out with the
`EgressRule` that refused it rather than hidden. What each mode permits is
[`privacy-modes.md`](privacy-modes.md); the address rules behind the Local Only half are
[`../security/egress-guard.md`](../security/egress-guard.md).

## 5. Not decided here

- The `ai_runs` table and the `AiReadiness` rebinding over it (Sprint 2, data-model.md §3.1).
- Recording failed runs (`error`) and the retry/backoff that follows (Sprint 2).
- The Settings › Local Models pane and the SELECT_AI re-entry deep link (S1-07 / Sprint 2).
- Per-model readiness: the contract says provider *and model*; the marker stores the model and the
  comparison is by provider until a model is part of the selection.
