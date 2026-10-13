# ADR-004: Provider-neutral AI layer as a thin adapter over `laravel/ai` 1.2.0

| | |
|---|---|
| **Status** | Proposed |
| **Date** | 2026-10-10 |
| **Deciders** | D (AI/Agents) lead · reviewer H (Security/privacy) · reviewer B (Laravel platform) · Andreas for the model-license items handed to ADR-007 |
| **Closes** | §15 item 4 (AI SDK half; MCP half is ADR-005) · §15 item 6 (runtime choice; licensing conclusion is ADR-007) · OPEN-QUESTIONS A10 |

## Context

§4 permits the official Laravel AI SDK only *"om kompatibilitet och API:er verifieras med låst
version"* and demands a thin provider adapter plus contract tests. §3 fixes the internal contract
(`AiProviderAdapter` with `capabilities()`, `generate()`, `stream()`) and forbids coupling the agent to
vendor clients. §4 further requires neutral onboarding with no preselected brand or model, a strict
`LOCAL_ONLY` mode with no silent fallback, a policy-based `EU_RESTRICTED` mode that never infers EU
safety from domain names, capability-aware routing that fails closed, an LLM that only proposes while a
server-side `PolicyGate` decides, and an agent control plane (maxsteps, maxcost, maxtime, dedupe,
cancellation, circuit breakers, redaction, trace-id, provenance, replay). §10.9 reminds us that a local
model does not escape license review. §12.1 and §12.3.5 require the installer to detect an installed
Ollama or LM Studio and *reuse* it, suggesting compatible choices without preselecting. §17 requires
"minst två valfria externa AI-adaptrar" without naming them (A10).

What breaks if this stays open: S1 cannot stub `app/AI/Providers` (issue #16), the installer's
`SELECT_AI` state has no detection contract, Sprint 2's assistant chat has no routing or egress rules,
and the §14 "locality test with packet capture under Local Only" has nothing to assert against.

OPEN-QUESTIONS §15.4 already recorded facts verified on 2026-10-10. This ADR re-verified them against
the released package source at the version pinned below; where the earlier note and the source differ,
the source wins and the difference is called out.

## Decision

### D1. Pin `laravel/ai` to exactly `1.2.0`

- `composer.json` requires `"laravel/ai": "1.2.0"` — exact, no caret or tilde. `composer.lock` is
  committed. Verified requirements of that release: PHP `^8.3`, `illuminate/*` `^12.0|^13.0`, MIT.
- Upgrades land only through a dedicated PR that (a) bumps the pin, (b) runs the full contract-test
  matrix (D2) green, (c) appends a dated line to this ADR's **Evidence** section naming what changed.
  A bot-opened bump PR without (c) is closed unmerged.
- Every SDK API we rely on has a contract test, so an upstream change breaks a test, not production.
- Two shipped defaults are overridden because they violate §4 brand neutrality:
  - `config/ai.php` ships `'default' => 'openai'`. Ours sets every `default*` key to `'unconfigured'`,
    a provider name that is never defined, so any code path that forgets to pass a provider explicitly
    throws instead of silently calling OpenAI.
  - `OllamaProvider::defaultTextModel()` falls back to a hard-coded model name when none is configured.
    Our adapter always passes an explicit model; a request without a model is rejected before it
    reaches the SDK.
- SDK migrations (`agent_conversations`) are **not** published or run. Conversation memory is
  app-owned (§4); the SDK `Conversational`/`RemembersConversations` features are not used. A contract
  test proves prompting works with those tables absent.

### D2. `AiProviderAdapter` is a thin wrapper; SDK types never leave `app/AI/Providers`

```text
app/AI/Providers/
  AiProviderAdapter.php              # §3 interface (issue #16 writes the stub)
  ModelCapabilities.php              # our DTO, see D3
  Dto/{AiRequest,AiResponse,AiStreamChunk,AiUsage,AiToolCall,AiError}.php
  LaravelAi/
    LaravelAiAdapter.php             # the only class that calls Laravel\Ai\*
    SdkAgentBridge.php               # builds an SDK agent from an AiRequest
    SdkToolBridge.php                # wraps our AgentTool as Laravel\Ai\Contracts\Tool
    ErrorMapper.php                  # SDK exceptions -> AiError
    UsageMapper.php                  # SDK usage -> AiUsage + cost
  ProviderRegistry.php               # builds adapters from ai_providers + Keychain via Ai::build()
  Detection/{OllamaDetector,LmStudioDetector}.php   # §12.3.5, see D3
```

Mapping of the §3 contract onto the verified SDK surface:

| Our contract | SDK API relied upon (verified in 1.2.0 source and docs) |
|---|---|
| `generate(AiRequest): AiResponse` | `Promptable::prompt(prompt, attachments, provider, model, timeout)` on a `SdkAgentBridge`; `HasStructuredOutput::schema(JsonSchema)` when `AiRequest->schema` is set; response `usage->inputTokens/outputTokens/cacheReadInputTokens/cacheWriteInputTokens` |
| `stream(AiRequest): iterable` | `Promptable::stream(...)` → `StreamableAgentResponse`; each SDK event mapped to `AiStreamChunk` |
| tools | `HasTools::tools()` returning `SdkToolBridge` instances; the bridge's `handle()` calls `PolicyGate` **before** the wrapped tool runs — the model never executes anything directly (§4) |
| embeddings | `Laravel\Ai\Embeddings::for($chunks)->generate()` via an `EmbeddingProvider` |
| provider construction | `Ai::build(['driver' => ..., 'url' => ..., 'key' => ...])` with the key read from Keychain (ADR-003) at call time — never from `.env` |
| local drivers | `ollama` (`url`, optional `key`; SDK default URL `http://localhost:11434`, default timeout 60 s) and `openai-compatible` (`url`, optional `key`, `headers`, `models`) which covers LM Studio and any MLX-style server; "Custom endpoint" in the UI is this driver |
| external drivers (v1) | `openai`, `anthropic` (D6) |
| bounds | attributes `MaxSteps`, `MaxTokens`, `Timeout`, `Temperature`, `TopP` set on the bridge from `AiRequest` |
| testing | `Agent::fake()` / `FakeTextGateway`, `FakeEmbeddingGateway` for unit tests; recorded transcripts for replay (D7) |

Rules enforced by a Pest architecture test:

- `App\AI` must not use `Laravel\Ai` except inside `App\AI\Providers\LaravelAi`. Agents, routing,
  memory, briefs and tools see only our DTOs.
- `provider` is always passed to the SDK as a **single** provider, never an array. The SDK's
  `Promptable::withModelFailover()` iterates an array of providers and silently fails over on any
  `FailoverableException` (`ProviderConnectionException`, `ProviderOverloadedException`,
  `RateLimitedException`, `InsufficientCreditsException`). That is exactly the silent fallback §4
  forbids. A contract test asserts the `AgentFailedOver` and `ProviderFailedOver` events are never
  dispatched in our test suite.
- The SDK's own error classes are mapped in `ErrorMapper` to our `AiError` taxonomy:
  `connection`, `overloaded`, `rate_limited`, `insufficient_credits`, `stream_error`, `no_such_tool`,
  `capability_missing` (ours), `egress_blocked` (ours), `policy_denied` (ours), `cancelled` (ours).

**Contract-test matrix** — every adapter (one per driver we ship) must pass all rows in
`tests/Integration/AI/Contract/`, run against a fake gateway in CI and against a real endpoint in the
Mac lab (Ollama and LM Studio) or with a test key (OpenAI, Anthropic):

| # | Row | Assertion |
|---|---|---|
| 1 | text | `generate()` returns non-empty text, usage populated |
| 2 | stream | `stream()` yields ≥2 chunks, concatenation equals a non-streamed answer's shape, terminal chunk carries usage |
| 3 | tools | a tool call is proposed, `PolicyGate` is consulted before execution, denial produces a tool result the model can read, no execution happens on denial |
| 4 | structured output | schema honoured, invalid JSON surfaces as `AiError(structured_output_invalid)` — never silently parsed from free text |
| 5 | embeddings | dimensions match `ModelCapabilities->embeddingDimensions` or `capability_missing` |
| 6 | cancellation | a `CancellationToken` cancelled mid-stream stops iteration within one chunk and records `cancelled` in `ai_runs` |
| 7 | error mapping | each SDK exception class maps to the documented `AiError` kind; unknown exceptions map to `unknown` and are logged scrubbed |
| 8 | cost accounting | `AiUsage` tokens equal SDK usage; cost equals tokens × the per-model price in the registry; local models record tokens with cost `0` |
| 9 | no failover | `AgentFailedOver` never fires; a failing single provider raises, it does not retry elsewhere |
| 10 | LOCAL_ONLY egress | see D4 |
| 11 | no SDK tables | rows 1–8 pass with SDK migrations absent |
| 12 | explicit model | a request without `model` is rejected before the SDK is called |

### D3. Capability-aware routing fails closed; `ModelCapabilities` is our own registry

**The SDK does not expose per-model capabilities.** Verified: `AiManager::ensureProviderSupports()`
checks only provider-level contracts (`TextProvider`, `EmbeddingProvider`, …) and throws
`LogicException('Provider [...] does not support {capability}.')`. Nothing in 1.2.0 tells us whether
a given *model* supports tools, vision, structured output, or what its context window is. Therefore:

- `ModelCapabilities` (DTO) fields: `text`, `streaming`, `tools`, `structuredOutput`, `vision`,
  `embeddings`, `embeddingDimensions`, `contextWindow`, `maxOutputTokens`, `inputPricePerMTok`,
  `outputPricePerMTok` (nullable; `0` for local), `maxConcurrency`, `source`
  (`registry` | `probe` | `user_override`), `verifiedAt`.
- Source of truth, in precedence order:
  1. `resources/ai/models.json` — hand-maintained, versioned registry keyed by `driver/model-id`.
     Each entry carries a `source_url` and `verified_at`. This is the authority for external models.
  2. Runtime probe overlay for local runtimes: Ollama `POST /api/show` returns a `capabilities`
     array plus `details.quantization_level`, `details.parameter_size`, `model_info` and the
     model's license text; LM Studio `GET /api/v0/models` returns `type` (`llm`/`vlm`/`embeddings`),
     `quantization`, `state` (`loaded`/`not-loaded`) and `max_context_length`. Probe results fill
     fields the registry leaves `null`; they never override a registry `false`.
  3. `user_override` in Settings → Local Models: the user may assert a capability for a model the
     registry does not know, with a visible "unverified — may fail" marker. Stored in `ai_models`.
- Unknown model ⇒ every capability `false` except `text`, `contextWindow` `4096`, `streaming` `true`
  (universal across our drivers). Fail closed means the job is refused, not degraded.
- `ModelRouter::resolve(JobRequirements $req, PrivacyMode $mode): RoutingDecision` filters the
  user's configured models by: required capabilities ⊆ model capabilities; privacy-mode eligibility
  (D4, D5); remaining budget (D7); provider not circuit-open (D7); `req->contextTokens` ≤
  `contextWindow`. Empty result ⇒ `CapabilityUnavailableException` with a user-readable reason and the
  list of missing capabilities. The UI shows "This model cannot do X — pick another" (§1.1 shows only
  what a real adapter supports).
- **Forbidden downgrades** (each a negative test): dropping structured output and regex-parsing
  free text; dropping tools and letting the model "describe" the action; truncating context silently
  (a context overflow is a refusal with the token count shown); switching provider or model inside a
  run; retrying on a different model after a capability error.
- Detection for `SELECT_AI` (§12.3.5): `OllamaDetector` performs a loopback `GET
  http://127.0.0.1:11434/api/version` then `GET /api/tags` (installed models) and `GET /api/ps` (loaded
  models); `LmStudioDetector` performs `GET http://127.0.0.1:1234/v1/models` and, when available,
  `GET /api/v0/models` for loaded state and context length (requires LM Studio ≥ 0.3.6; the v0 API may
  require a bearer token — treat `401` as "running, token required" and ask the user for the token,
  never as "not installed"). Connect timeout 500 ms, total 2 s, loopback only. The installer lists what
  it found as *options* in alphabetical order with no radio button preselected; finding exactly one
  runtime does not select it.

### D4. `LOCAL_ONLY` is enforced at the adapter boundary, with no fallback, and proven by an egress test

Enforcement is layered; each layer alone is sufficient to block, and each has its own test:

1. **Registry gate.** In `LOCAL_ONLY`, `ProviderRegistry` instantiates adapters only for drivers in
   `{ollama, openai-compatible}` **and** whose configured URL host is in the allowlist below. Any other
   configured provider is shown greyed out as "disabled by Local Only", never built, never callable.
2. **Host allowlist.** Default allowlist is loopback only: `127.0.0.0/8`, `::1`, and the literal
   `localhost`. Hostnames are resolved **before** the request and the resolved IP is pinned for the
   request (DNS-rebinding defence, same code path as the §11 SSRF guard in `Browser/Fetch`). An
   optional `LOCAL_ONLY_ALLOW_LAN` toggle (off by default, with the warning "model traffic will leave
   this Mac over your local network") extends the allowlist to RFC 1918 and link-local ranges only.
   Public IPs are never allowlisted in this mode.
3. **HTTP-layer veto.** The SDK builds every request with Laravel's `Http` facade
   (`CreatesClient::createClient()` → `Http::baseUrl(...)`). `AiServiceProvider` registers
   `Http::globalRequestMiddleware()` that, when the current run is `LOCAL_ONLY`, inspects the target
   host of **every** outgoing request made during an AI run and throws `EgressBlockedException` for a
   host outside the allowlist, before any socket is opened. Redirects are re-checked.
4. **No failover.** D2 already guarantees a single provider per call; `EgressBlockedException` is
   deliberately **not** a `FailoverableException`.
5. **Embeddings, screenshots, tool transcripts and prompts** go through the same adapter and the same
   middleware, so the rule covers everything §4 lists. Email/GitHub sync is a separate, transparently
   labelled egress category and is not affected (§4).

Test evidence required before this ADR is Accepted (§14 "locality test with packet capture under
Local Only"):

- `tests/Security/LocalOnlyEgressTest.php` (unit, every CI run): `Http::fake()` catch-all spy; run the
  full AI fixture suite in `LOCAL_ONLY` with a deliberately configured cloud provider; assert zero
  recorded requests to non-allowlisted hosts and that the cloud provider raised `egress_blocked`.
- Mac lab gate (release blocking, I owns): start real Ollama on loopback, run the deterministic eval
  set under `LOCAL_ONLY` while `tcpdump -i any -n 'not (host 127.0.0.1 or host ::1)'` filtered to the
  PHP worker PIDs (via `lsof -i -p`) captures; assert an empty capture. The pcap is attached to the
  release checklist.

### D5. `EU_RESTRICTED` is policy-based and evidence-driven

A provider is eligible under `EU_RESTRICTED` only when its `ai_providers` row carries all of:

| Field | Meaning |
|---|---|
| `processing_region` | Contracted inference region(s); enum values `EU`, `EEA+CH`, `US`, `GLOBAL`, `UNKNOWN` |
| `region_mechanism` | How region is bound: `endpoint` (distinct base URL), `account_setting` (project/workspace flag), `request_parameter`, `local_machine` |
| `region_evidence_url` + `region_verified_at` | The vendor page or contract clause the user relied on, and when |
| `dpa_reference` + `dpa_signed_at` | Data-processing agreement actually in force for this account |
| `subprocessors_url` | Current sub-processor list |
| `transfer_impact_ref` | Pointer to the transfer impact analysis (§10.6) or `none_required` for `local_machine` |
| `zero_data_retention` + evidence | Whether ZDR/abuse-monitoring opt-out is active for the account |
| `verified_by`, `expires_at` | Who confirmed it; eligibility lapses on expiry |

Rules:

- Missing or expired evidence ⇒ not eligible; the router refuses with the field names that are missing.
- **Domain-name inference is forbidden** (§4). No code may derive region from `.eu`, `eu.` or any
  hostname pattern; an architecture test fails on regexes over provider hostnames in `app/AI`.
- Local runtimes (D4 allowlist) satisfy `EU_RESTRICTED` for *inference* by construction
  (`region_mechanism = local_machine`) — the model's license (D8) still applies.
- `EU_RESTRICTED` does not imply `LOCAL_ONLY`; the modes compose as a conjunction when both are set.

Verified vendor state on 2026-10-10 (recorded so the UI can pre-fill `region_mechanism`, not as
eligibility — the user still supplies account-level evidence):

- **OpenAI**: EU residency exists, bound per project and by endpoint (`eu.api.openai.com`), and
  "To use data residency with any region other than the United States, you must be approved for
  abuse monitoring controls." ⇒ `region_mechanism = endpoint + account_setting`; eligible once the
  user records the approved project and DPA.
- **Anthropic first-party API**: `inference_geo` accepts only `"global"` and `"us"`; "Currently,
  `"us"` is the only available workspace geo." ⇒ **not eligible** under `EU_RESTRICTED` in v1. EU
  regions exist only via Amazon Bedrock / Google Vertex, which are not v1 adapters (D6).
- **OpenRouter**: "OpenRouter supports in-region routing in the EU and US on the Business and
  Enterprise plans" via `eu.openrouter.ai`; provider filters `only`, `ignore`, `data_collection:
  deny`, `zdr` exist, but there is no provider-level region filter. ⇒ eligible only with the EU
  endpoint, an `only` list, `zdr`, and evidence for each upstream provider named in `only`.

### D6. A10 resolved: v1 ships OpenAI and Anthropic as the two external adapters

Chosen by reach among the target users (developers, consultants, small teams) and by API maturity in
the pinned SDK (both are first-class drivers with text, streaming, tools, structured output, usage;
OpenAI additionally embeddings). Trade-offs recorded honestly: Anthropic is not `EU_RESTRICTED`-
eligible today (D5) and is therefore offered only in `CUSTOM` mode until its residency changes.

- **v1 "Choose AI" options** (alphabetical, none preselected): Anthropic · Custom OpenAI-compatible
  endpoint (covers MLX-style servers) · LM Studio · Ollama · OpenAI.
- **OpenRouter** — third external adapter **if cheap**: the SDK driver exists and the contract matrix
  is identical, so the marginal cost is CI test credits plus the D5 evidence record. It ships in v1
  only if rows 1–9 pass in Sprint 2 without adapter-specific code; otherwise post-v1. It is never
  shown in the UI until that happens (§1.1).
- **Gemini** — post-v1, same interface. Not shown in v1 UI.
- **GoZero Core** — never, in any form (§0, §4, §17). An architecture test fails on the string.

### D7. Agent control plane — parameters and defaults

All values live in `config/ai-agent.php` (kebab-case file, snake_case keys), are user-editable per job
class in Settings, and are enforced in `app/AI/Agents/RunSupervisor` **outside** the SDK so they hold
for every driver:

| Parameter | Default | Hard ceiling | Mechanism |
|---|---|---|---|
| `max_steps` | 8 (chat), 12 (brief jobs) | 25 — the SDK's own `DEFAULT_MAX_STEPS`; we never pass more | SDK `MaxSteps` attribute set from our value; supervisor also counts `StepCompleted` events |
| `max_time_seconds` | 120 (chat), 300 (queued jobs) | 600 | SDK `Timeout` per prompt + supervisor wall-clock check between steps; cancellation on breach |
| `step_timeout_seconds` | 60 local / 45 cloud | 120 | SDK `Timeout` |
| `max_output_tokens` | 4096 | model `maxOutputTokens` | SDK `MaxTokens` |
| `max_cost_per_run` | €0.50 | — | pre-flight estimate from registry prices + running total from usage; refusal before the step that would exceed |
| `max_cost_per_day` | €5.00 per provider | — | summed from `ai_runs`; local runs cost 0 but count tokens |
| `max_concurrency` | 1 local, 4 cloud | per `ModelCapabilities->maxConcurrency` | Laravel queue middleware `WithoutOverlapping`/`RateLimited` keyed by provider |
| `dedupe_window_minutes` | 60 | — | idempotency key `sha256(job_class, normalized_input, model_id)` in `ai_runs`; identical request returns the stored response |
| `cancellation` | — | — | cooperative `CancellationToken` checked between steps and per stream chunk; "Pause AI actions" in the menu bar flips a global token |
| `circuit_breaker` | open after 3 `connection`/`overloaded` errors in 60 s; half-open after 300 s | — | per provider in cache; open circuit ⇒ router excludes the provider (no automatic substitute) |
| `redaction` | on | — | `Security/Redaction` runs over every prompt, tool input, tool output and transcript before it is sent or stored; ADR-003 defines the patterns |
| `trace_id` | ULID per run | — | stored in `ai_runs.trace_id`, propagated to tool runs and MCP calls; **not** sent as a header to external providers |
| `provenance` | on | — | `ai_runs` stores provider, model id, model digest (Ollama `digest`), quantization, privacy mode, region evidence id, prompt hash, capabilities snapshot, usage, cost, wall time, outcome |
| `replay` | — | — | `FakeTextGateway` driven by recorded transcripts under `tests/fixtures/ai/`; the deterministic eval set (§4.2, §13 D) runs on replay in CI and on real local models in the lab |

### D8. Candidate small local models for the optional async download — **no selection made**

Per §15.6 and §12.1 the installer offers *at most one* small tested model as an explicitly
asynchronous post-core download, and ships **no weights in the DMG** until ADR-007 confirms the
license. Candidates verified on 2026-10-10 (sizes are the Q4_K_M download sizes shown by the Ollama
registry; the §15.6 "measured download size" still has to be timed in the install lab):

| Model | Tag / size | License (verified) | Redistribution inside an MIT app |
|---|---|---|---|
| Llama 3.2 3B Instruct | `llama3.2:3b` 2.0 GB (1B: 1.3 GB); Ollama lists `tools` | Llama 3.2 Community License (2024-09-25) + Acceptable Use Policy | **Review required.** Permitted with obligations: ship a copy of the agreement, display "Built with Llama", keep the Notice text, 700 M MAU clause, AUP flows down to users. Not OSI. Multimodal EU restriction is in the AUP, not the license — not applicable to the text-only 3B but must be re-checked |
| Qwen2.5 7B Instruct | `qwen2.5:7b` 4.7 GB | Apache-2.0 | Permitted; attribution only. Large for a "small" download |
| Qwen2.5 3B Instruct | `qwen2.5:3b` 1.9 GB | **Qwen Research License** — "FOR NON-COMMERCIAL PURPOSES ONLY"; "If you are commercially using the Materials, you shall request a license" | **Cannot be offered or redistributed.** Excluded regardless of ADR-007 |
| Mistral 7B Instruct v0.3 | `mistral:7b-instruct` 4.4 GB; function calling | Apache-2.0 | Permitted; attribution only. Large |
| Gemma 3 4B | `gemma3:4b` 3.4 GB (1B text-only: 806 MB) | Gemma Terms of Use (effective 2026-04-01) + Prohibited Use Policy | **Review required.** Permitted with flow-down: use restrictions "as an enforceable provision in any agreement", copy of terms, Notice file. Not OSI |
| Phi-4-mini-instruct 3.8B | `phi4-mini` 2.5 GB; 128K context | MIT | Permitted; cleanest fit for an MIT product |

Handed to Andreas / ADR-007 as the **Legal / licensing input** this ADR cannot supply:
(1) whether Llama's attribution and AUP flow-down obligations are acceptable in an MIT product;
(2) whether Gemma's flow-down of use restrictions is acceptable; (3) confirmation that Phi-4-mini
(MIT) and the Apache-2.0 models need only attribution; (4) Qwen2.5-3B is excluded — confirm no one
re-adds it. Newer families (Qwen 3.x, Llama 4, Gemma 4) may be added to this table only after their
license pages are verified the same way; the SDK's own default-model string is not evidence.

## Evidence

**Vendor documentation** (all fetched 2026-10-10):

- Packagist `laravel/ai` releases: `v1.2.0` published 2026-10-07T19:23:23Z; `v1.1.0` 2026-10-05;
  `v1.0.1` 2026-09-29; `v1.0.0` 2026-09-23. Source: https://repo.packagist.org/p2/laravel/ai.json.
- `laravel/ai` v1.2.0 source (git tag `v1.2.0`, commit `0020f8f5`), https://github.com/laravel/ai:
  `composer.json` requires `php ^8.3`, `illuminate/* ^12.0|^13.0`, license MIT.
  `src/Promptable.php`: `prompt(AgentInput|UserMessage|Decisions|string $prompt, array $attachments
  = [], Lab|array|string|null $provider = null, ?string $model = null, ?int $timeout = null):
  AgentResponse`; `withModelFailover()` catches `FailoverableException` and moves to the next
  provider when `$provider` is an array; `getProvidersAndModels()` falls back to `config('ai.default')`.
  `src/Providers/Provider.php::formatProviderAndModelList()` turns an array into the failover chain.
  `src/Gateway/TextGenerationLoop.php`: `DEFAULT_MAX_STEPS = 25`; "Resolve the step budget: explicit
  `maxSteps`, else 1.5x tools capped at the ceiling, else 5."
  `src/Gateway/Concerns/CreatesClient.php`: `Http::baseUrl($baseUrl)->withHeaders(...)->timeout(...)`
  — all gateways use Laravel's HTTP client.
  `src/Gateway/Ollama/Concerns/CreatesOllamaClient.php`: default base URL `http://localhost:11434`,
  default timeout `60`.
  `src/AiManager.php::ensureProviderSupports()`: `throw new LogicException('Provider
  ['.$instance::class."] does not support {$capability}.")` — provider-level only; no per-model data.
  `src/Exceptions/`: `AiException`, `FailoverableException` (interface), `ProviderConnectionException`,
  `ProviderOverloadedException`, `RateLimitedException`, `InsufficientCreditsException`,
  `StreamErrorException`, `NoSuchToolException`. `src/Events/`: `AgentFailedOver`, `ProviderFailedOver`,
  `AgentFailed`, `StepCompleted`, `ToolInvoked`. `src/Enums/Lab.php`: `Ollama = 'ollama'`,
  `OpenAICompatible = 'openai-compatible'`, `OpenRouter = 'openrouter'`, `OpenAI`, `Anthropic`,
  `Gemini`, …. `config/ai.php`: `'default' => 'openai'`; `'ollama' => ['driver' => 'ollama', 'key' =>
  env('OLLAMA_API_KEY', ''), 'url' => env('OLLAMA_URL', 'http://localhost:11434')]`;
  `'openai-compatible' => ['driver' => 'openai-compatible', 'url' => env('OPENAI_COMPATIBLE_URL')…]`.
  `src/Migrations/2026_01_11_000001_create_agent_conversations_table.php` is the only migration.
  `src/Providers/OllamaProvider.php::defaultTextModel()` returns a hard-coded model when unconfigured.
- Laravel AI SDK documentation, https://laravel.com/docs/13.x/ai-sdk (13.x, read 2026-10-10):
  `composer require laravel/ai`; `HasTools` with `tools(): iterable`; `HasStructuredOutput` with
  `schema(JsonSchema $schema): array`; `Embeddings::for($chunks)->generate()`; `Ai::build(['driver'
  => 'anthropic', 'key' => $user->anthropic_key])` under "Runtime Providers"; `$response->usage->
  inputTokens / outputTokens / cacheReadInputTokens / cacheWriteInputTokens`; attributes `MaxSteps`,
  `MaxTokens`, `Timeout`, `Temperature`, `TopP`, `Provider`, `Model`, `UseCheapestModel`,
  `UseSmartestModel`; `Agent::fake([...])`; text-provider matrix lists OpenAI, OpenAI Compatible,
  Anthropic, Gemini, Ollama, OpenRouter among others; openai-compatible example comment "(LM Studio,
  vLLM, Together, Fireworks, local gateways)".
- Ollama API, https://github.com/ollama/ollama/blob/main/docs/api.md (read 2026-10-10): default port
  `11434`; `GET /api/tags` lists local models with `name`, `model`, `size`, `digest`, `details`
  (`family`, `parameter_size`, `quantization_level`); `POST /api/show` returns `details`,
  `model_info`, license and a **`capabilities`** array; `GET /api/ps` lists loaded models; `GET
  /api/version`; `POST /api/chat` supports `stream`, `tools`, `format` (JSON schema); `POST /api/embed`.
- LM Studio OpenAI compatibility, https://lmstudio.ai/docs/app/api/endpoints/openai: base URL
  "http://localhost:1234/v1"; endpoints `GET /v1/models`, `POST /v1/chat/completions`, `POST
  /v1/embeddings`, `POST /v1/completions`. LM Studio REST API,
  https://lmstudio.ai/docs/developer/rest/endpoints: "GET /api/v0/models" returns `id`, `type`
  (llm, vlm, embeddings), `publisher`, `arch`, `quantization`, `state` (loaded/not-loaded),
  `max_context_length`; "Requires LM Studio 0.3.6 or newer."; example header `Authorization: Bearer
  $LM_API_TOKEN`; a v1 REST API (LM Studio 0.4.0) is recommended for new projects — detector must
  tolerate both.
- OpenAI data residency, https://developers.openai.com/api/docs/guides/your-data: "Data residency is
  configured per-project within your API Organization."; EU requests use `eu.api.openai.com`; "To use
  data residency with any region other than the United States, you must be approved for abuse
  monitoring controls."
- Anthropic data residency, https://platform.claude.com/docs/en/manage-claude/data-residency:
  `inference_geo` values `"global"` ("Default. Inference may run in any available geography") and
  `"us"`; "Currently, `"us"` is the only available workspace geo."; "On Amazon Bedrock and Google
  Cloud, the inference region is determined by the endpoint URL or inference profile".
- OpenRouter provider routing, https://openrouter.ai/docs/features/provider-routing: `only`,
  `ignore`, `data_collection: "deny"` ("use only providers which do not collect user data"), `zdr`
  ("Restrict routing to only ZDR (Zero Data Retention) endpoints"); "OpenRouter supports in-region
  routing in the EU and US on the Business and Enterprise plans".
- Model sizes from the Ollama registry tag pages (read 2026-10-10): `llama3.2:3b` 2.0 GB, `llama3.2:1b`
  1.3 GB; `qwen2.5:7b-instruct-q4_K_M` 4.7 GB, `qwen2.5:3b-instruct-q4_K_M` 1.9 GB; `gemma3:4b`
  3.4 GB, `gemma3:1b` 806 MB; `phi4-mini:3.8b-q4_K_M` 2.5 GB; `mistral:7b-instruct-q4_K_M` 4.4 GB.
- Model licenses: Llama 3.2 Community License, https://huggingface.co/meta-llama/Llama-3.2-3B-Instruct/blob/main/LICENSE.txt
  ("LLAMA 3.2 COMMUNITY LICENSE AGREEMENT", 2024-09-25; §1.b.i copy of agreement, "Built with Llama",
  "Llama" name prefix; §1.b.iii Notice text; §2 700 million MAU) — note `llama.com/llama3_2/license`
  redirects twice and ends in a 404, so the HF copy is cited. Gemma Terms of Use,
  https://ai.google.dev/gemma/terms (effective 2026-04-01; §3.1 use-restriction flow-down, copy of
  agreement, Notice file; Prohibited Use Policy incorporated). Qwen2.5-7B-Instruct `apache-2.0`,
  https://huggingface.co/Qwen/Qwen2.5-7B-Instruct. Qwen2.5-3B-Instruct `qwen-research`,
  https://huggingface.co/Qwen/Qwen2.5-3B-Instruct/blob/main/LICENSE ("FOR NON-COMMERCIAL PURPOSES
  ONLY"). Phi-4-mini-instruct MIT, https://huggingface.co/microsoft/Phi-4-mini-instruct ("The model
  is licensed under the MIT license", 3.8B, 128K context). Mistral-7B-Instruct-v0.3 `apache-2.0`,
  https://huggingface.co/mistralai/Mistral-7B-Instruct-v0.3 ("Supports function calling").

**Implementation, 2026-10-13 (S2-13, issue #95)** — the adapter, the capability registry, the router,
the detectors and the twelve-row matrix are built; see
[`docs/architecture/ai-providers.md`](../architecture/ai-providers.md) for the as-built page, the
per-row status table and the lab protocol. The four facts above were re-verified against the pinned
`1.2.0` source and all four hold:

- `Promptable::withModelFailover()` iterates `iterateProvidersWithFailover()` and dispatches
  `AgentFailedOver` only when `$provider` is an array; our adapter passes a single string, so with one
  entry the generator yields once with `isFinalAttempt = true` and the exception is rethrown. Matrix
  row 9 asserts no failover event is ever dispatched.
- `config/ai.php` in the package still ships `'default' => 'openai'`; ours sets all seven `default*`
  keys to `'unconfigured'` and row 12 asserts none of them names a defined provider.
- `OllamaProvider::defaultTextModel()` returns `… ?? 'qwen3.5:4b'` (and `'qwen3.5:0.8b'` /
  `'gemma4:cloud'` for cheapest/smartest). No provider entry configures `models`, `AiRequest` cannot
  exist without a model, and the adapter passes it on every call, so the fallback is unreachable.
- `grep -rl capabilit src/` in the package matches exactly one file —
  `AiManager::ensureProviderSupports()`, which is provider-level only. The registry is ours.

Two further facts settled while building, both recorded in the as-built page: attachments ride on
`UserMessage` as in-memory base64 `Laravel\Ai\Files\*` objects and the trailing user turn must be sent
as the prompt rather than as history; and `Illuminate\JsonSchema` hoists a property's `required()` flag
into the parent object's `required` array, so our schema arrays are a true pass-through.

**Measured prototype** — the CI half only. Rows 1–12 pass for all five shipped drivers against the
SDK's `FakeTextGateway` with `Http::preventStrayRequests()` armed; there is no API key in the
repository and CI performs no real-endpoint call. Still required before **Accepted**:

- (a) the D4 egress unit test (`tests/Security/LocalOnlyEgressTest.php`, **S2-14**) and the Mac-lab
  pcap run — layer 1 of D4 (the registry gate) is built and tested, layers 2–3 are S2-14's;
- (b) rows 1–12 against real Ollama and real LM Studio on Mac A, and rows 1–9 with a test key per
  external driver — **blocked for Anthropic**, see the finding below;
- (c) timed download of each D8 candidate on the lab network, recorded in
  `docs/architecture/support-matrix.md`.

**Finding for Andreas — `claude-opus-4-1` is retired on the first-party Claude API.** Anthropic's
pricing and deprecation pages (read 2026-10-13) record `claude-opus-4-1-20250805` as deprecated
2026-06-05 and **retired 2026-08-05**, replacement `claude-opus-4-8`, remaining available only on
Amazon Bedrock and Google Cloud — neither of which is a v1 adapter under D6. The model name is
unchanged in `resources/ai/models.json` because nothing in this repository renames a model on its own;
the entry is marked `review_required` with the finding, its verified price ($15/$75 per MTok) kept as
evidence, and its context window left `null` because the model's documentation page is gone (so the
registry falls back to the fail-closed 4096). Consequence: obligation (b) cannot be met for Anthropic
until the owner either approves a Bedrock/Vertex route or names a different model.

**Finding for Andreas — no EUR conversion policy.** `ModelCapabilities` prices are EUR per million
tokens and every vendor publishes USD. The artefact records the verified USD figures as evidence and
leaves the EUR fields `null`, so an external model's `costEur` is honestly `null` and a local model's
is `0.0`. D7's `max_cost_per_run` and `max_cost_per_day` cannot refuse anything for a real reason until
a conversion policy exists; that is S2-15's dependency.

**D6 OpenRouter gate — evaluated, outcome post-v1.** The code condition is met (the adapter is generic;
adding OpenRouter would be a catalogue row plus a binding, with no adapter-specific branch), but the
rest is not: the frozen fixture schema's `driver` enum does not admit `openrouter`, rows 1–9 have not
run against a real key, and no D5 evidence record exists for the upstream providers an `only` list
would name. It therefore ships **post-v1 and is never shown in the UI**, per §1.1.

**Legal / licensing input** — not yet. The D8 table is the inventory; the conclusion belongs to
Andreas in ADR-007. Engineers do not accept license risk.

Differences from the OPEN-QUESTIONS §15.4 note of 2026-10-10: that note did not mention the SDK's
built-in multi-provider failover, its `openai` default, its hard-coded Ollama default model, or that
it carries no per-model capability data. All four are now decisions above (D1, D2, D3).

## Alternatives considered

1. **Write our own HTTP clients per provider, no SDK.** Full control of egress and errors, no
   upstream churn. Ruled out: §4 explicitly prefers the official SDK once verified; six providers ×
   (text, stream, tools, structured output, embeddings) is a maintenance surface the project cannot
   staff, and the SDK already uses Laravel's HTTP client, so our egress veto works through it anyway.
2. **Use the SDK's agents, tools and conversation memory directly throughout `app/AI`.** Least code.
   Ruled out: SDK types would leak into agents, briefs and tools, making the §3 contract a fiction; the
   SDK's failover and `ai.default` behaviours contradict §4 and would have to be remembered at every
   call site instead of being blocked once at the boundary; conversation memory must be app-owned (§4).
3. **Pin with a caret (`^1.2`).** Fewer bump PRs. Ruled out: §4 says *låst version*; a minor release
   could add a failover path or change usage fields without failing our build until runtime.
4. **Trust the SDK's provider contracts as "capabilities".** No registry to maintain. Ruled out:
   verified that the SDK only knows whether a *provider* can do text/embeddings, not whether the chosen
   *model* supports tools, vision or a given context — fail-closed routing is impossible without our
   own per-model data.
5. **Ship Gemini or OpenRouter instead of Anthropic as the second external adapter.** OpenRouter's EU
   in-region routing is attractive for `EU_RESTRICTED`. Ruled out for v1 primary: reach among target
   users and direct-vendor API maturity favour OpenAI + Anthropic; OpenRouter is kept as the cheap
   third (D6) rather than a replacement.
6. **Allow LAN hosts in `LOCAL_ONLY` by default** (home-lab GPU box). Ruled out as default: §4 says
   strict; traffic leaving the Mac is a different trust boundary. Kept as an explicit, warned opt-in.

## Consequences

Easier: one adapter class for every driver the SDK supports; new providers are config plus a registry
entry plus the same 12-row contract suite; LOCAL_ONLY is testable in one place; the installer's
detection is two loopback GETs.

Harder: `resources/ai/models.json` is now a maintained artefact with a review step per model; every
SDK bump is an ADR amendment; approval-driven tools pay one extra `PolicyGate` round-trip per call.

**Forbidden in code review** because of this ADR:

- `use Laravel\Ai\…` anywhere outside `app/AI/Providers/LaravelAi/`.
- Passing an array of providers to `prompt()`/`stream()`, or relying on `config('ai.default')`.
- Calling the SDK without an explicit model id.
- Publishing or running the SDK's migrations; using `Conversational`/`RemembersConversations`.
- Any `.eu`/hostname heuristic for region; any capability assumed without a registry or probe entry.
- Any fallback from one model or provider to another inside a run; any silent downgrade listed in D3.
- Any provider id, string or brand preselected in `SELECT_AI`; the string "GoZero" in `app/AI`.
- Bundling model weights in the DMG or starting a model download before `READY`.
- Qwen2.5-3B-Instruct in any manifest.

## Residual risks

- **Upstream SDK drift.** A 1.x release may change failover or usage semantics; mitigated by the exact
  pin and the contract matrix. Accepted by D.
- **Registry staleness.** A model gains or loses a capability and `models.json` lags; the result is a
  refused job (fail closed), not a leak. Accepted by D.
- **Anthropic not EU-eligible in v1.** Users in `EU_RESTRICTED` have one external option (OpenAI) plus
  local. Accepted by H as a product limitation, documented in onboarding.
- **LAN opt-in widens the Local Only boundary.** Off by default, warned, allowlist-bound; traffic on
  the user's own LAN is still outside the Mac. Accepted by H.
- **Egress veto depends on the SDK using Laravel `Http`.** A future gateway using a raw Guzzle or
  cURL client would bypass layer 3; layers 1–2 and the pcap gate remain. Contract row 10 is the alarm.
  Accepted by D and H.
- **License obligations of Llama/Gemma** may force the single offered model to be Phi-4-mini or an
  Apache-2.0 model regardless of quality. Accepted by Andreas via ADR-007.
- **Local model quality** for Swedish/Italian/German (§4.2) is unmeasured until the eval set runs.
  Accepted by D.

## Blueprint references

§0, §1.1, §2.1, §3, §4, §4.1, §4.2, §10 (items 1, 2, 6, 9), §11, §12.1, §12.3.5, §13 (D, H, I),
§14, §15 items 4 and 6, §17, §18.

## Reopen conditions

- `laravel/ai` releases a version that exposes per-model capabilities, or removes `Ai::build`,
  `openai-compatible`, or the Ollama driver.
- The SDK stops using Laravel's `Http` client in any text or embedding gateway.
- Anthropic (first-party) or another v1 provider adds a verifiable EU inference/workspace geo.
- The D2 contract matrix fails for a driver on the pinned version with no fix other than bypassing
  the adapter.
- ADR-007 rejects every candidate in D8, or approves one whose download exceeds the §12.1 "small"
  budget measured in the lab.
- The §4.2 eval set shows the chosen local model below the brief-quality floor in any required
  language.
