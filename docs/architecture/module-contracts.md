# Module contracts (S0-16)

**Status:** Stubs landed; signatures stable from Sprint 2 · **Owner:** Workstream B (Laravel platform)
**Source:** blueprint §3 "Modulkontrakt och datamodell" · **Tests:** `tests/Unit/Contracts/`

Blueprint §3 gives four interfaces as *"kontraktsexempel, inte drop-in kod"* and says the implementation
requires explicit typing, transaction handling, exceptions, cancellation and policy checks. This page
records how each sketch became a typed PHP contract, who consumes it, and where it deliberately
differs from the sketch. The code is the contract; this page is the map.

## 1. The four interfaces

| §3 sketch | Interface | DTOs | Failure | ADR |
|---|---|---|---|---|
| `AiProviderAdapter { capabilities(); generate(); stream() }` | [`App\AI\Providers\AiProviderAdapter`](../../app/AI/Providers/AiProviderAdapter.php) | `ModelCapabilities`, `Dto\{AiRequest, AiResponse, AiStreamChunk, AiMessage, AiAttachment, AiToolDefinition, AiToolCall, AiUsage, AiError}` + enums | `Exceptions\{ProviderUnavailable, CapabilityUnavailable, EgressBlocked, InvalidStructuredOutput}Exception` | ADR-004 |
| `WorkspaceRepository { customers(); contacts(); projects(); tasks(); timeline() }` | [`App\CRM\WorkspaceRepository`](../../app/CRM/WorkspaceRepository.php) | `WorkspaceScope`, `Queries\{QueryPage, CustomerQuery, ContactQuery, ProjectQuery, TaskQuery, ActivityQuery}`, read models `CustomerSummary`, `ContactSummary`, `ProjectSummary`, `TaskSummary`, `ActivityEntry` | `Exceptions\WorkspaceAccessDeniedException` | ADR-002 |
| `ComputerUseProvider { capabilities(); observe(); execute() }` | [`App\Browser\ComputerUse\ComputerUseProvider`](../../app/Browser/ComputerUse/ComputerUseProvider.php) | `AutomationSession`, `AllowlistEntry`, `ObservationRequest`, `Observation`, `UiElement`, `Rect`, `UiAction`, `AuthorizedUiAction`, `ActionResult`, `AutomationCapabilities`, `AutomationPermissions` + enums | `Exceptions\{AutomationDenied, AutomationUnavailable}Exception` | ADR-005 |
| `TunnelProvider { inspect(); configure(); disable() }` | [`App\Support\Install\TunnelProvider`](../../app/Support/Install/TunnelProvider.php) | `Tunnel\{TunnelStatus, TunnelConfig, TunnelResult, TunnelVerificationStep}` + enums | `Tunnel\TunnelException` with `TunnelFailureReason` | ADR-006 |

Shared primitives:

- **Cancellation** — `App\Support\Cancellation\CancellationToken` (interface), `CancellationSource`
  (caller-owned, flips the token), `NeverCancelled` (explicit opt-out), `OperationCancelledException`.
  Every long-running method takes a token as its last parameter and declares the exception.
- **Policy** — `App\Approvals\Policies\PolicyDeniedException` is the base of every server-side refusal
  (`WorkspaceAccessDeniedException`, `AutomationDeniedException`). One catch at the agent loop turns any
  denial into a tool result the model can read without anything having executed.

## 2. Consumers

| Contract | Produces implementations | Consumes the interface |
|---|---|---|
| `AiProviderAdapter` | D — `LaravelAiAdapter` + `ProviderRegistry` (S2, ADR-004 D2); fakes for the contract-test matrix | D agents and `ModelRouter`; `Briefs/Generation`; the S0-21 negative test; C for the "AI unavailable" UI states |
| `WorkspaceRepository` | E — SQLite implementation on the frozen schema (E2-2); MySQL profile later (ADR-002 D10) | D agent tools and `crm.*` MCP tools (G); `Briefs/Generation`; S0-18 fixture replay |
| `ComputerUseProvider` | G — helper-backed implementation over the authenticated IPC (S3, ADR-005 D3) | D `RunAuthorizedTool`; `App\Approvals` (the only producer of `AuthorizedUiAction`); A for the menu-bar session state and panic stop |
| `TunnelProvider` | A/C — `Tunnel\TailscaleServeTunnelProvider` (S4, ADR-006 D2) | the installer's Remote Access step (A); Settings → Remote access (C); `docs/operations` uninstall |

## 3. Deliberate differences from the §3 sketch

| Sketch | Contract | Why |
|---|---|---|
| `capabilities(): ModelCapabilities` (no argument) | `capabilities(string $modelId)` | Capabilities are per **model**, not per provider; the SDK carries no per-model data and our registry is keyed by `driver/model-id` (ADR-004 D3). The adapter is per provider row, the model is chosen per request |
| `stream(AiRequest): iterable` | `stream(AiRequest, CancellationToken): Generator<int, AiStreamChunk, null, AiResponse>` | A generator's return value carries the completed response (usage, finish reason) so streaming callers never reassemble it; cancellation is checked per chunk (ADR-004 contract row 6) |
| `generate(AiRequest)` | `generate(AiRequest, CancellationToken)` | §3 asks for cancellation in signatures; ADR-004 D7 checks the token between steps |
| — | `providerId()`, `driver()` added | The router, `ai_runs` provenance and the Keychain lookup (ADR-003) need to know which `ai_providers` row produced the adapter |
| `customers(CustomerQuery): iterable` etc. | same, plus `CancellationToken`; returns `iterable<int, CustomerSummary>` read models | The consumers (brief generator, AI tools, MCP tools) must see one stable shape on every DB profile (ADR-002 D10 "identical ids"); Eloquent models are an implementation detail of E's module and do not exist yet. Livewire screens may use Eloquent directly; this contract is the agent/brief read API |
| no scope on queries | every `*Query` carries a `WorkspaceScope` and a `QueryPage` | §3.1 `organization_id`, §11 cross-account tests: a query without a scope cannot be refused, and a query without a page cannot be bounded |
| `capabilities(): array` | `capabilities(): AutomationCapabilities` | The issue forbids untyped arrays; the UI needs permission state and action kinds, not a bag |
| `observe(AutomationSession)` | `observe(AutomationSession, ObservationRequest, CancellationToken)` | The provider must know **which** allowlisted window to capture and what kind of observation; both are policy inputs (ADR-005 D1.5) |
| `execute(AuthorizedUiAction)` | `execute(AuthorizedUiAction, CancellationToken)`; `AuthorizedUiAction` carries `proposalId`, `approvalId`, `approverUserId`, `proposalHash`, `nonce`, `expiresAt` | §11 frozen immutable proposal; ADR-005 D1.6 per-action approval. A source scan (`AuthorizedActionProvenanceTest`) allows only `app/Approvals` to construct it |
| — | `stop(AutomationSession, string $reason): void` added | §6 panic stop and ADR-005 D1.9 are non-negotiable; a safety control that exists only by convention is not a contract |
| `disable(): void` | `disable(): TunnelResult` | ADR-006 D2 requires post-disable verification and reporting of handlers the app did not create; `void` cannot carry that |
| `configure(TunnelConfig)` | `configure(TunnelConfig, CancellationToken)`; `TunnelConfig` requires a `confirmationId` | ADR-006 D1.3: nothing is configured without a recorded user confirmation; rollback on cancel |

Not in the contract on purpose:

- **Embeddings** (`Embeddings::for()` in the SDK) — a separate `EmbeddingAdapter` in Sprint 2 (ADR-004 contract row 5); mixing it into `AiProviderAdapter` would force every text-only provider to stub it.
- **Session start** for automation — the session grant is a UI action with its own policy; S3 (G) defines `AutomationSessionManager`. `ComputerUseProvider` only consumes a live session.
- **`serve reset`** for the tunnel — ADR-006 D6.2 says the only mutating commands are the two D2 lines, while D2 also offers "Remove all Serve configuration" from `Drifted`/`FunnelDetected`. The contract expresses the replace path via `TunnelConfig::$replaceDriftedConfiguration` and leaves `reset` to the ADR owners (A) to reconcile before S4.
- **CRM writes** — mutations go through action proposals and approvals (§11); the repository is read-only.

## 4. What the SDK provides and what is ours

`laravel/ai` is pinned at 1.2.0 (ADR-004 D1). Its types never leave `App\AI\Providers\LaravelAi`
(ADR-004 D2; enforced by an arch test), so every DTO here is ours. The mapping, so the bridge is
mechanical:

| Ours | SDK 1.2.0 | Relationship |
|---|---|---|
| `AiRequest` | `Promptable::prompt(prompt, attachments, provider, model, timeout)` / `stream(...)`; attributes `MaxSteps`, `MaxTokens`, `Timeout`, `Temperature`, `TopP`; `HasStructuredOutput::schema()` | Input mapping; one provider, always an explicit model |
| `AiRequest::$outputSchema`, `AiToolDefinition::$parameters` | `array<string, Illuminate\JsonSchema\Types\Type>` — the exact return shape of `Laravel\Ai\Contracts\Tool::schema()` and `HasStructuredOutput::schema()` | **Reused** framework type (it is Illuminate, not SDK) |
| `AiMessage` / `AiMessageRole` | `Laravel\Ai\Messages\Message` / `MessageRole` (`assistant`, `user`, `tool_result`) | Mirrored, plus `system` (the SDK carries it as agent instructions) |
| `AiResponse` | `Laravel\Ai\Responses\TextResponse` (`text`, `usage`, `toolCalls`, `meta->provider/model`, `reasoning`) | Wrapped |
| `AiUsage` | `Laravel\Ai\Responses\Data\TextUsage` (`inputTokens`, `outputTokens`, `cacheReadInputTokens`, `cacheWriteInputTokens`, `reasoningTokens`) | Mirrored one-to-one, plus `costEur` (ours) |
| `AiToolCall` | `Laravel\Ai\Responses\Data\ToolCall` (`id`, `name`, `arguments`) | Mirrored without the provider reasoning fields |
| `AiFinishReason` | `Laravel\Ai\Responses\Data\FinishReason` | Mirrored, plus `Cancelled` |
| `AiStreamChunk` / `AiStreamChunkKind` | `Laravel\Ai\Streaming\Events\{TextDelta, ReasoningDelta, ToolCall, StreamEnd, Error}` | Folded: start/end markers collapse into deltas |
| `AiErrorKind` | `Laravel\Ai\Exceptions\{ProviderConnection, ProviderOverloaded, RateLimited, InsufficientCredits, StreamError, NoSuchTool}Exception` | First six mirrored; `capability_missing`, `egress_blocked`, `policy_denied`, `structured_output_invalid`, `cancelled`, `unknown` are ours |
| `ModelCapabilities` | nothing — `AiManager::ensureProviderSupports()` is provider-level only | Entirely ours (ADR-004 D3) |

## 5. Typing rules the tests enforce

`tests/Unit/Contracts/ModuleContractsTest.php` asserts, by reflection, for every interface method and
every DTO constructor and public method:

1. Every parameter has a native type; no parameter or return is `mixed`.
2. A native `array`, `iterable` or `Generator` has a generics docblock (`list<X>`, `array<K, V>`,
   `iterable<int, X>`, `Generator<K, V, S, R>`) with imported class names.
3. Every interface has at least one method taking a `CancellationToken` and at least one `@throws`.
4. Every DTO is `final readonly`.
5. Each interface docblock cites §3.

Two places carry `array<string, mixed>` in a docblock, by choice: `AiToolCall::$arguments` and
`AiResponse::$structuredOutput`. Both are JSON objects whose shape is defined by a schema the request
supplied and validated by the PolicyGate or the adapter, not by the DTO. Typing them tighter would
either move validation into a value object that cannot see the schema, or re-encode them as strings.
External text (model output, screen content, email) is **data, not instruction** everywhere in these
contracts; the docblocks say so at each entry point.

## 6. Notes for the next items

- **S0-17 (`EmailConnector`)** — reuse `CancellationToken`, derive refusals from `PolicyDeniedException`,
  put the connector under `App\Integrations\Mail` with DTOs in a `Dto\` sub-namespace, and make
  `ApprovedOutgoingEmail` follow the `AuthorizedUiAction` pattern (approval ids, hash, nonce, expiry;
  only `App\Approvals` constructs it; add it to `AuthorizedActionProvenanceTest`).
- **S0-21 (negative security test)** — the boundary to assert is: a foreign email body is an
  `AiMessage` with role `User`/`ToolResult`; the model may emit an `AiToolCall`; nothing may turn that
  into an `AuthorizedUiAction` or an `ApprovedOutgoingEmail` without an `action_approvals` row. The
  provenance test already fails the build if production code outside `app/Approvals` constructs one.
- **S2 implementers** — the exceptions are the contract for failure; do not add new ones to a method
  without updating its `@throws` and this page. Add fields to DTOs freely (readonly, named arguments);
  never remove or retype one without a review.
