# AI providers as built: the `laravel/ai` adapter, the capability registry and the contract matrix (S2-13)

ADR-004 decided that the AI layer is a **thin adapter** over `laravel/ai` 1.2.0, that the SDK's types
never leave one directory, that per-model capabilities are ours because the SDK has none, and that
routing fails closed. This page is what that turned into, where it differs from the ADR, and what is
still owed — including one finding the owner has to rule on.

Companion pages: [ai-state.md](ai-state.md) for how a provider becomes offerable and how `ai_ready`
is gated, [module-contracts.md](module-contracts.md) §4 for the DTO ↔ SDK mapping,
[`docs/adrs/004-provider-neutral-ai.md`](../adrs/004-provider-neutral-ai.md) for the decisions.

## 1. The boundary

```text
app/AI/
  Providers/
    AiProviderAdapter.php          # §3 contract (S0-16)
    ModelCapabilities.php          # our DTO (S0-16)
    ProviderConnection.php         # one configured provider: driver, URL, Keychain ref, models
    ProviderConnections.php        # where connections come from (interface)
      InstalledProviderConnections.php   # from install config, until ai_providers (S2-02 #85)
      InMemoryProviderConnections.php    # the contract matrix and the installer hand-off
    RegionEvidence.php             # D5 evidence record; ProcessingRegion, RegionMechanism
    ProviderRegistry.php           # decides *whether* a provider may be built (D4 layer 1)
    AdapterFactory.php             # decides nothing; builds one (interface)
    Detection/
      LoopbackProbe.php            # the only HTTP client detection uses: 127.0.0.1, 0.5 s / 2 s
      OllamaDetector.php           # /api/version, /api/tags, /api/ps, /api/show
      LmStudioDetector.php         # /v1/models, /api/v0/models
    LaravelAi/                     # ← the ONLY directory that may import Laravel\Ai
      LaravelAiAdapter.php         # generate() / stream() / capabilities()
      LaravelAiAdapterFactory.php  # the only caller of Ai::build()
      SdkAgentBridge.php           # an SDK agent built from one AiRequest
      StructuredSdkAgentBridge.php # … plus HasStructuredOutput, when a schema is asked for
      SdkToolBridge.php            # our tool as an SDK tool — approval-gated, never executed
      SdkMessageMapper.php         # our messages and attachments → SDK messages
      ErrorMapper.php              # SDK exceptions → AiError
      UsageMapper.php              # SDK usage → AiUsage + cost
  Routing/
    ModelRouter.php                # capability + privacy + context filter, fails closed
    ModelCapabilityRegistry.php    # registry → probe → override precedence
    JobRequirements.php, RoutingDecision.php, ConfiguredModel.php
    ProbedCapabilities.php, CapabilityOverride.php
    PrivacyMode.php                # S0-16, plus fromConfigValue()
```

`tests/Security/NoSdkTypeLeakArchTest.php` enforces the boundary and four rules with it: no
`Laravel\Ai` reference in code outside `app/AI/Providers/LaravelAi/` (docblocks may *name* SDK types —
that mapping is documentation module-contracts.md asks for, and the test strips comments before
scanning); no `provider:` given an array or a spread; no listener for `AgentFailedOver` or
`ProviderFailedOver`; and no `GoZero` in `app/AI`. It also pins the file list inside the boundary, so
widening the one SDK-aware directory is a visible diff rather than a quiet drift.

`ProviderRegistry` deliberately knows nothing about the SDK. It decides whether a provider may exist
under the current privacy mode; `AdapterFactory` — one implementation, inside the boundary — knows
how to build one. Splitting the decision from the construction is what lets the Local Only gate live
outside the vendor-aware code.

## 2. The four ADR-004 facts, re-verified against the pinned source

Verified in `vendor/laravel/ai` at `1.2.0` (`composer.lock` reference `0020f8f5…`, MIT, requires PHP
`^8.3` and `illuminate/* ^12.0|^13.0`) on 2026-10-13. All four hold, and all four are the reason a
piece of this implementation looks the way it does.

| ADR-004 claim | Verified in 1.2.0 | What we do about it |
|---|---|---|
| The SDK silently fails over when `provider` is an **array** | `Promptable::prompt()` / `::stream()` take `Lab\|array\|string\|null $provider`; `withModelFailover()` iterates `iterateProvidersWithFailover()` and on a `FailoverableException` calls `recordAgentFailover()` → `event(new AgentFailedOver(...))`, moving to the next provider. `Provider::formatProviderAndModelList()` turns an array into that chain. `ProviderConnectionException`, `ProviderOverloadedException`, `RateLimitedException` and `InsufficientCreditsException` all implement `FailoverableException` | `LaravelAiAdapter` passes `provider:` a **single** string, always. With one entry, `iterateProvidersWithFailover()` yields once with `isFinalAttempt = true`, so the exception is rethrown and no event fires. Matrix row 9 asserts it; the arch test forbids the array form |
| `config/ai.php` ships `'default' => 'openai'` | Confirmed, line 16 of the package's config | Our `config/ai.php` sets every `default*` key to `'unconfigured'`, a provider name that is never defined, so a forgotten argument throws instead of calling OpenAI. Row 12 asserts all seven keys and that none of them names a defined provider |
| The Ollama driver falls back to a hard-coded model | `OllamaProvider::defaultTextModel()` returns `$this->config['models']['text']['default'] ?? 'qwen3.5:4b'` (also `cheapestTextModel()` → `'qwen3.5:0.8b'`, `smartestTextModel()` → `'gemma4:cloud'`) | `AiRequest` cannot be constructed without a model; the adapter passes it on every call; no provider entry in `config/ai.php` carries a `models` key; and the factory never writes one. The fallback is unreachable. Row 12 |
| The SDK carries **no** per-model capability data | `AiManager::ensureProviderSupports()` checks only provider-level contracts and throws `LogicException('Provider [...] does not support {capability}.')`. `grep -rl capabilit src/` matches exactly one file — that method | `ModelCapabilityRegistry` over `resources/ai/models.json` is ours, with the D3 precedence. Without it, fail-closed routing is impossible |

Two further facts settled while building, both recorded here because they shaped the code:

- **Attachments.** `UserMessage` carries a `Collection` of `Laravel\Ai\Files\*`; `Image::fromBase64()`
  and `Document::fromBase64()` are the in-memory constructors. `Promptable::prompt($prompt,
  $attachments, …)` appends a `UserMessage` built from its first two arguments *after* the history it
  was handed, and `Message::tryFrom()` returns a `UserMessage` unchanged — so `SdkMessageMapper` stops
  the history one turn short and sends the trailing turn as the prompt, while attachments on earlier
  turns survive. `AiAttachment` holds bytes in memory and becomes base64, never a file on disk
  (ADR-005 D1.7).
- **JSON-schema `required`.** `Illuminate\JsonSchema\Types\Type::required()` sets a flag that the
  serializer *removes* from the property and hoists into the parent object's `required` array
  (`Serializer::$ignore = ['required', 'nullable']`). So `AiRequest::$outputSchema` and
  `AiToolDefinition::$parameters` are a true pass-through — nothing may pre-serialise them — and
  reading which properties are required means serialising the schema, which is what the adapter does
  to validate a structured answer.

## 3. The twelve-row contract matrix

`tests/Integration/AI/Contract/ContractMatrixTest.php`, every row against every shipped driver
(`ollama`, `lmstudio`, `openai`, `anthropic`, `custom` — the ADR-004 D6 v1 list). The harness is
`tests/Integration/AI/Contract/Support/ContractHarness.php`.

**Local column** = CI, against the SDK's own `FakeTextGateway` with `Http::preventStrayRequests()`
armed. No row can open a socket, and there is no API key anywhere in this repository. **Lab column**
= the real-endpoint half, which is a Mac-lab run (§5). CI never claims it.

| # | Row | Local (CI) | Lab | Notes |
|---|---|---|---|---|
| 1 | text | green | owed | non-empty text, usage populated, provider/model/trace echoed back |
| 2 | stream | green | owed | ≥2 chunks; concatenated deltas equal the whole answer; terminal `End` chunk carries usage and finish reason; the generator's return value equals what it streamed |
| 3 | tools | green | owed | the call comes back as a **proposal**; the gate is consulted before execution; `handle()` and `withoutApproval()` both throw; a denial reaches the model as a `ToolResult` turn it can read |
| 4 | structured output | green | owed | schema honoured; a prose answer that *contains* the value is refused as `structured_output_invalid`, never parsed |
| 5 | embeddings | green (partial) | owed | dimensions come from the registry (768 for `nomic-embed-text`) and a text-only model is refused with `capability_missing`. The embedding **adapter** itself is a separate contract (module-contracts.md §3 "not in the contract on purpose") and is not S2-13's |
| 6 | cancellation | green | owed | mid-stream cancellation stops iteration within one chunk and returns `Cancelled`; cancelling before the first chunk raises instead of returning a partial answer. **The `ai_runs` half is skipped: `needs S2-02 tables (#85)`** |
| 7 | error mapping | green | owed | all six SDK exception classes map to their documented kind and retryability; an unmapped exception maps to `unknown`, is logged scrubbed, and is still raised — the vendor's message never becomes ours. The **in-stream** error path (a provider reporting `Streaming\Events\Error` instead of throwing) is only reachable against a real provider, because `FakeTextGateway` never emits one: that branch is lab-verified, not CI-verified |
| 8 | cost accounting | green (partial) | owed | tokens equal the SDK's; a model on the owner's machine records tokens at cost `0.0`; an unpriced remote model records `null`, never a guess. The arithmetic (tokens × price) is asserted against a priced `ModelCapabilities`. **No external model has an EUR price yet — see §4** |
| 9 | no failover | green | owed | `AgentFailedOver` and `ProviderFailedOver` are never dispatched, and a failing single provider raises |
| 10 | LOCAL_ONLY egress | green (layer 1) | **required before ADR-004 is Accepted** | a remote provider is never built under `LOCAL_ONLY` (`EgressBlockedException`) and nothing is sent; `EgressBlockedException` is not a `FailoverableException`. Layers 2–3 (resolve-and-pin, the `Http` global veto) and `tests/Security/LocalOnlyEgressTest.php` are **S2-14**; the pcap run is §5 |
| 11 | no SDK tables | green | owed | `agent_conversations` does not exist, no published migration creates it, and a multi-turn prompt works without it |
| 12 | explicit model | green | owed | a blank model is refused by `AiRequest` before the SDK is reached; the model travels on every call; no provider configures a default model; all seven `default*` keys are `'unconfigured'` |

Rows skipped in CI, and why — the full list, so nothing is quietly absent:

| Skipped | Reason |
|---|---|
| row 6, "the cancelled outcome is recorded in `ai_runs`" | `needs S2-02 tables (#85)`. The cancellation *behaviour* is tested; only the ledger write is deferred |

### 3.1 What the matrix revealed

Two findings worth keeping, because both are the design working rather than failing:

- **A bring-your-own model is unknown, and that is fail-closed.** `openai-compatible` serves LM Studio
  and any custom gateway, so no registry can know which model is behind it. Under D3 such a model is
  text and streaming only — which means tool and structured-output jobs on it are **refused** until
  the owner asserts the capability through the `user_override` path (precedence level 3, marked
  "unverified — may fail" in the UI). The matrix reaches rows 3 and 4 for those two drivers that way,
  because that is the real mechanism a real owner would use. An Ollama model gets there through the
  `/api/show` probe instead.
- **Cost-free is a property of the machine, not the driver.** Keying "free" off the driver name would
  have billed a paid remote OpenAI-compatible gateway at zero. `ModelCapabilityRegistry::resolve()`
  therefore takes the provider's `ProviderKind`, and only `Local` prices at `0.0`.

## 4. The capability registry

`resources/ai/models.json` is a **maintained artefact with a review step per model**, not a cache.
Each entry is keyed `driver/model-id` and carries a `source_url`, a `verified_at`, a `verified_by` and
a `review_required` flag; an entry under review reports **no** verification date through
`ModelCapabilityRegistry`, so the UI can mark it unverified. A model that is not in the file is
unknown: every capability `false` except `text`, `streaming` `true`, `contextWindow` 4096 — and a job
that needs more is refused, never degraded.

Precedence, exactly D3's and no other:

1. **Registry** — `resources/ai/models.json`. Authority for external models.
2. **Probe overlay** — Ollama `POST /api/show` (`capabilities`, `model_info.*.context_length`,
   `details.quantization_level`), LM Studio `GET /api/v0/models` (`type`, `quantization`, `state`,
   `max_context_length`). Fills fields the registry left `null`.
3. **User override** — the owner asserting a capability in Settings → Local Models, with a visible
   "unverified — may fail" marker.

**Neither a probe nor an override may overturn a registry `false`.** That asymmetry is the point: a
runtime that over-reports, or an owner who insists, must not be able to talk the product into a
capability nobody verified. `tests/Unit/AI/ModelRouterTest.php` asserts it in both directions.

**Recommended models are recommendations, never preselections** (§12.3.5, ai-state.md §4):
`gpt-5-mini` for OpenAI and `claude-opus-4-1` for Anthropic are flagged `recommended: true` and
readable through `recommendedFor($driver)`. Nothing consumes that to choose: `ChooseAiDraft` starts
empty, `/settings/ai` renders `data-ai-preselected="none"`, and both are tested.

### 4.1 Owner decision needed: `claude-opus-4-1` is retired on the first-party API

Recorded here and in the artefact's `review_reason`, unchanged in the file because nothing in this
repository renames a model on its own:

- Anthropic's pricing page and deprecation page (read 2026-10-13) say `claude-opus-4-1-20250805` was
  deprecated **2026-06-05** and **retired on the Claude API on 2026-08-05**, with
  `claude-opus-4-8` named as the replacement. It remains available only on Amazon Bedrock and Google
  Cloud, which ADR-004 D6 does not ship as v1 adapters.
- Consequence: a request to it through the `anthropic` driver will fail against a live key, so the
  **lab half of the matrix cannot pass for Anthropic** until the owner either approves a
  Bedrock/Vertex route or names a different model. The fake-gateway half is unaffected.
- Its context window and max output are `null` in the artefact because the model's documentation page
  is gone (404) and no figure could be cited. With the window unknown the registry falls back to the
  fail-closed 4096, so a large prompt is refused rather than truncated — correct, and another reason
  the entry is marked `review_required`.
- Verified price, kept as evidence: **$15 / MTok input, $75 / MTok output**.

### 4.2 Owner decision needed: no EUR conversion policy

`ModelCapabilities` prices are EUR per million tokens; every vendor publishes USD. No conversion
policy exists, so the artefact records the verified USD figures as evidence
(`vendor_input_price_per_mtok_usd`) and leaves the EUR fields `null`. The effect is deliberate and
visible: an external model's `costEur` is `null` ("unknown"), a local model's is `0.0`, and nothing is
a converted guess. **ADR-004 D7's `max_cost_per_run` and `max_cost_per_day` cannot refuse anything for
a real reason until this is decided** — that is S2-15's dependency, flagged here because the registry
is where the number will live. Verified figure for `gpt-5-mini`: $0.25 / $2.00 per MTok, 400,000
context, 128,000 max output.

## 5. The lab protocol — ADR-004 Evidence obligations

CI proves the shape of the contract. It cannot prove that a real Ollama answers, that a real key
authenticates, or that no packet left the Mac. Those are a lab run on a Mac, recorded in ADR-004's
Evidence section, and until they are done the ADR stays **Proposed**.

| Obligation | Protocol | Owner |
|---|---|---|
| Rows 1–12 against real **Ollama** | `ollama serve` on loopback; configure the `ollama` provider with a pulled model; run `php artisan test --filter=ContractMatrix` with the fake gateway swapped for the live one; record the SDK version, the model digest, the quantization and each row's result | D |
| Rows 1–12 against real **LM Studio** | LM Studio ≥ 0.3.6 with a loaded model and the REST API on; same run. Record whether the v0 API needed a bearer token, since the detector's `401` path is only exercisable live | D |
| Rows 1–9 with a **test key** per external driver | One throwaway, spend-limited key per provider, never committed and never in `.env.example`. **Anthropic is blocked by §4.1** | D, with the owner's key |
| Row 10 **packet capture** | Start real Ollama; run the deterministic eval set under `LOCAL_ONLY` with a cloud provider deliberately configured, while `tcpdump -i any -n 'not (host 127.0.0.1 or host ::1)'` filtered to the PHP worker PIDs captures. Assert an **empty** capture; attach the pcap to the release checklist | I |
| D6 **OpenRouter** gate | Rows 1–9 against OpenRouter with no adapter-specific code. See §6 | D |
| D8 **download timings** | Time each candidate model's download on the lab network; record in support-matrix.md | I |

Nothing in this repository may report a lab result. The table above is the list of what is owed, and
ADR-004's Evidence section is where a completed run is written down, dated, with what changed.

## 6. OpenRouter: the D6 decision

**Post-v1, and not shown in the UI.** D6 made OpenRouter conditional — it ships in v1 only if matrix
rows 1–9 pass "without adapter-specific code". The code side of that condition is met: the adapter is
generic over the SDK's drivers, and adding OpenRouter would be a catalogue row plus a binding, with no
branch anywhere. What is **not** met is the rest of the condition:

- The fixture schema's `driver` enum admits only `ollama`, `openai-compatible`, `openai` and
  `anthropic` (ADR-004 D1's table), so an `openrouter` row cannot be added without changing a frozen
  contract — which is a decision, not an implementation detail.
- Rows 1–9 "passing" for OpenRouter means passing in the **lab**, against a real key, and the lab run
  has not happened. Shipping it on the strength of a fake gateway would be precisely the "available"
  claim §1.1 forbids.
- D5 eligibility needs an evidence record per upstream provider named in an `only` list, which nobody
  has produced.

So it stays out: no catalogue row, no binding, nothing in `SELECT_AI` or `/settings/ai`, and
`tests/Unit/AI/SupportedProvidersTest.php` uses `openrouter` and `gemini` precisely as the ids that
prove an unbound provider is never offered. Revisit when the lab run and the D5 record exist.

## 7. What is offered, and why that changed in this PR

`adapter_available` flipped to `true` for all five catalogue rows, in the one PR, in all three
mirrors — `packaging/fixtures/ai-providers/ai-providers.json`,
`App\AI\Providers\SupportedProviders::catalogue()` and the Swift `AiProviderCatalog.all` — which is
exactly the mechanism ai-state.md §2 specifies. `App\Providers\AiServiceProvider` binds an adapter
under `AiProviderAdapter::class.'@'.$id` for each of them, and three tests hold the sides together:
`SupportedProvidersTest` refuses a flag without a binding **and** a binding without a flag,
`ChooseAiScreenTests.testCatalogueMirrorsTheSharedFixture` pins the Swift mirror to the fixture, and
the arch test checks the bound set equals the flagged set.

The bindings are **lazy**. `app()->bound()` answers the honest question — "does this build carry an
adapter for this driver?" — while actually constructing one needs a URL or a Keychain key the owner
may not have supplied. So resolution is deferred to the first run that needs it, where a missing key
surfaces as the provider's own authentication error (an "AI error" state the UI already renders)
rather than as a boot failure.

Offerable is still not selected. The five options are listed with nothing chosen, and `ai_ready`
remains false until a real completion succeeds — `RecordAiCompletionSuccess` is **S2-15's** to call,
and `tests/Feature/Health/AiReadinessTest.php` still asserts that nothing in `app/` references it.

## 8. Detection (§12.3.5)

`LoopbackProbe` is the only HTTP client detection uses: the literal `127.0.0.1`, 500 ms to connect,
2 s in total, no redirects, no credentials. The literal address is not a convenience — a detector that
accepted a hostname would be a DNS-rebinding path straight through the Local Only boundary, so the
restriction lives in the probe rather than in each caller.

- `OllamaDetector` — `GET /api/version`, then `/api/tags` (installed) and `/api/ps` (loaded).
  `POST /api/show` supplies the capability overlay.
- `LmStudioDetector` — `GET /v1/models` for presence, then `/api/v0/models` for `type`,
  `quantization`, `state` and `max_context_length`. A `401` from either endpoint means **running,
  token required**, never "not installed": telling the owner LM Studio is missing would send them to
  reinstall something they already have. An LM Studio older than 0.3.6 answers only on `/v1/models`,
  and is still offered.

Detection **never throws**: a refused connection, a timeout and an absent runtime are all
`DetectedRuntime::absent()`, because detection sits on the installer's critical path and must not be
able to fail it. And detection never selects: the installer lists what it found as options in
alphabetical order with no radio button preselected, and finding exactly one runtime does not select
it.

## 9. Deliberate differences from ADR-004

| ADR-004 | As built | Why |
|---|---|---|
| `SdkToolBridge` "wraps our AgentTool as `Laravel\Ai\Contracts\Tool`" and "the bridge's `handle()` calls `PolicyGate` **before** the wrapped tool runs" | The bridge is `Approvable` and always requires approval, so the SDK's loop stops at `pendingApprovals` and `handle()` is **unreachable** — it throws if ever reached | Same guarantee, one less way to get it wrong. The ADR's shape has the gate inside a method the SDK calls during the run; this one makes the run unable to execute a tool at all, and the PolicyGate (E2-4) decides afterwards from a returned proposal. `handle()` throwing is the alarm if a future SDK changes that path |
| `ProviderRegistry` "builds adapters … via `Ai::build()`" | `ProviderRegistry` decides eligibility; `LaravelAi\LaravelAiAdapterFactory` calls `Ai::build()` | The registry sits outside the SDK boundary, so it may not import `Laravel\Ai`. Splitting decision from construction keeps the arch test true without weakening it |
| D3 lists `CapabilityUnavailableException` for an empty routing result | A context overflow raises `ContextWindowExceededException` and missing D5 evidence raises `RegionEvidenceMissingException`; both carry the ADR's `AiError` kinds | D3 wants the token count shown and D5 wants the missing field names. Reporting either as "the model lacks a capability" would be a refusal that misdescribes itself — accurate refusals are the whole point of failing closed |
| — | `StructuredStreamingUnsupportedException` added | `StreamsText::stream()` throws on any `HasStructuredOutput` agent, so streaming a schema is impossible in 1.2.0. It is a limit of the pinned SDK, not of the model, and says so rather than reporting the model as incapable |
| — | `PrivacyMode::fromConfigValue()` resolves an unrecognised value to `LocalOnly` | A privacy setting nobody can parse must not widen what leaves the Mac |
| Row 2 says the terminal chunk carries usage | A stream that ended in an `Error` chunk gets **no** terminal `End` chunk | `AiStreamChunk` documents that an `Error` chunk is always the last one, and appending an `End` after it would tell the caller the stream finished normally. The generator still *returns* its partial `AiResponse` with `finishReason: Error`, so the caller records what it got |

## 10. Not decided here

- `ai_providers`, `ai_models`, `ai_runs`, `tool_runs` — **S2-02 (#85)**. `ProviderConnection` and
  `CapabilityOverride` are the shapes those tables will map onto; nothing in the adapter, the registry
  or the router needs them to exist.
- The `Http` global egress veto, the resolve-and-pin SSRF guard, the LAN opt-in and
  `tests/Security/LocalOnlyEgressTest.php` — **S2-14**. Layer 1 (this page) holds without them.
- The agent control plane: `max_steps` enforcement outside the SDK, budgets, the circuit breaker,
  dedupe, the trace id and provenance — **S2-15 (ADR-004 D7)**, which is also the first caller of
  `RecordAiCompletionSuccess`.
- The `PolicyGate` and approvals that turn a proposal into an action — **E2-4**.
- A separate `EmbeddingAdapter` — module-contracts.md §3 keeps it out of `AiProviderAdapter` on
  purpose.
- Which local model, if any, ships as an optional download — ADR-004 D8 and **ADR-007**.
