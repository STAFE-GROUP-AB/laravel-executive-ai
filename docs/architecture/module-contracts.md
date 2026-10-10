# Module contracts (S0-16, S0-17)

**Status:** Stubs landed; signatures stable from Sprint 2 · **Owner:** Workstream B (Laravel platform); §5.6 row co-owned by F (Integrations)
**Source:** blueprint §3 "Modulkontrakt och datamodell", §5.6 "Adapterkontrakt" · **Tests:** `tests/Unit/Contracts/`

Blueprint §3 gives four interfaces as *"kontraktsexempel, inte drop-in kod"* and says the implementation
requires explicit typing, transaction handling, exceptions, cancellation and policy checks; §5.6 adds a
fifth, `EmailConnector`, as a *"designförslag, granska semantik mot faktiska SDK:er"*. This page
records how each sketch became a typed PHP contract, who consumes it, and where it deliberately
differs from the sketch. The code is the contract; this page is the map.

## 1. The five interfaces

| Sketch | Interface | DTOs | Failure | ADR |
|---|---|---|---|---|
| `AiProviderAdapter { capabilities(); generate(); stream() }` | [`App\AI\Providers\AiProviderAdapter`](../../app/AI/Providers/AiProviderAdapter.php) | `ModelCapabilities`, `Dto\{AiRequest, AiResponse, AiStreamChunk, AiMessage, AiAttachment, AiToolDefinition, AiToolCall, AiUsage, AiError}` + enums | `Exceptions\{ProviderUnavailable, CapabilityUnavailable, EgressBlocked, InvalidStructuredOutput}Exception` | ADR-004 |
| `WorkspaceRepository { customers(); contacts(); projects(); tasks(); timeline() }` | [`App\CRM\WorkspaceRepository`](../../app/CRM/WorkspaceRepository.php) | `WorkspaceScope`, `Queries\{QueryPage, CustomerQuery, ContactQuery, ProjectQuery, TaskQuery, ActivityQuery}`, read models `CustomerSummary`, `ContactSummary`, `ProjectSummary`, `TaskSummary`, `ActivityEntry` | `Exceptions\WorkspaceAccessDeniedException` | ADR-002 |
| `ComputerUseProvider { capabilities(); observe(); execute() }` | [`App\Browser\ComputerUse\ComputerUseProvider`](../../app/Browser/ComputerUse/ComputerUseProvider.php) | `AutomationSession`, `AllowlistEntry`, `ObservationRequest`, `Observation`, `UiElement`, `Rect`, `UiAction`, `AuthorizedUiAction`, `ActionResult`, `AutomationCapabilities`, `AutomationPermissions` + enums | `Exceptions\{AutomationDenied, AutomationUnavailable}Exception` | ADR-005 |
| `TunnelProvider { inspect(); configure(); disable() }` | [`App\Support\Install\TunnelProvider`](../../app/Support/Install/TunnelProvider.php) | `Tunnel\{TunnelStatus, TunnelConfig, TunnelResult, TunnelVerificationStep}` + enums | `Tunnel\TunnelException` with `TunnelFailureReason` | ADR-006 |
| §5.6 `EmailConnector { authorization(); verify(); sync(); folders(); getMessage(); send(); disconnect() }` | [`App\Integrations\Mail\EmailConnector`](../../app/Integrations/Mail/EmailConnector.php) | `Dto\{EmailConnectionDraft, AuthorizationFlow, EmailConnection, ConnectionHealth, SyncCursor, SyncRequest, SyncBatch, EmailMessage, EmailAddress, EmailAttachmentRef, EmailFolder, MailServerSettings, ApprovedOutgoingEmail, SendReceipt}` + enums `MailProviderKind`, `AuthorizationMethod`, `MailCapability`, `ConnectionHealthState`, `EmailDirection`, `EmailFolderRole` | `Exceptions\{MailProviderUnavailable, AuthorizationRequired, SyncCursorInvalid, EmailMessageNotFound, SendRefused}Exception` with `MailUnavailableReason`, `AuthorizationRequiredReason`, `SendRefusalReason` | ADR-002 D3/D8/D9, ADR-003 D4/D7 |

Shared primitives:

- **Cancellation** — `App\Support\Cancellation\CancellationToken` (interface), `CancellationSource`
  (caller-owned, flips the token), `NeverCancelled` (explicit opt-out), `OperationCancelledException`.
  Every long-running method takes a token as its last parameter and declares the exception.
- **Policy** — `App\Approvals\Policies\PolicyDeniedException` is the base of every server-side refusal
  (`WorkspaceAccessDeniedException`, `AutomationDeniedException`, `SendRefusedException`). One catch at
  the agent loop turns any denial into a tool result the model can read without anything having executed.
- **Authorized action types** — `AuthorizedUiAction` and `ApprovedOutgoingEmail` are the only inputs
  that make something happen outside the machine. Both carry `proposalId`, `approvalId`,
  `approverUserId`, a content hash, `nonce`, `approvedAt`, `expiresAt`; both may be constructed only
  under `app/Approvals` (`AuthorizedActionProvenanceTest`, one dataset for both).

## 2. Consumers

| Contract | Produces implementations | Consumes the interface |
|---|---|---|
| `AiProviderAdapter` | D — `LaravelAiAdapter` + `ProviderRegistry` (S2, ADR-004 D2); fakes for the contract-test matrix | D agents and `ModelRouter`; `Briefs/Generation`; the S0-21 negative test; C for the "AI unavailable" UI states |
| `WorkspaceRepository` | E — SQLite implementation on the frozen schema (E2-2); MySQL profile later (ADR-002 D10) | D agent tools and `crm.*` MCP tools (G); `Briefs/Generation`; S0-18 fixture replay |
| `ComputerUseProvider` | G — helper-backed implementation over the authenticated IPC (S3, ADR-005 D3) | D `RunAuthorizedTool`; `App\Approvals` (the only producer of `AuthorizedUiAction`); A for the menu-bar session state and panic stop |
| `TunnelProvider` | A/C — `Tunnel\TailscaleServeTunnelProvider` (S4, ADR-006 D2) | the installer's Remote Access step (A); Settings → Remote access (C); `docs/operations` uninstall |
| `EmailConnector` | F — one adapter per `MailProviderKind` under `App\Integrations\{Google, Microsoft, ICloud, Hey, Mail\Imap, Mail\ProtonBridge}` (S3; Microsoft 365 mandatory for v1); fakes replaying `packaging/fixtures/crm/communications.json` for the contract-test matrix | B's sync/normalisation layer (`email-sync` jobs, ADR-002 D3), the Add Email Account wizard (A Swift + C PWA), `App\Approvals` for `send()`, the brief sender (`Briefs/Delivery`), the S0-21 negative test, the §5.6 criterion-4 cross-account tests |

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

### 3.1 Differences from the §5.6 `EmailConnector` sketch

| Sketch | Contract | Why |
|---|---|---|
| — | `provider(): MailProviderKind` added | The registry picks the connector from `email_connections.provider` (ADR-002 D8); without it a connector cannot say which of the six §5.6 families it serves |
| `sync(EmailConnection, ?SyncCursor): SyncBatch` | `sync(EmailConnection, SyncRequest, CancellationToken)`; `SyncRequest` carries `SyncCursor::initial()` instead of `null` plus a `limit` (1–500) | A null cursor is a fail-open default; the initial cursor is an explicit object every connector must accept. The limit is the ADR-002 D3.2 batch size so a job can commit page and cursor in one transaction; `SyncBatch` refuses to exceed it |
| `folders(EmailConnection): iterable` | `folders(EmailConnection, CancellationToken): iterable<int, EmailFolder>` | The §5 typing rules forbid an untyped iterable; the wizard and the cursor scopes need ids and roles, not names |
| `verify(EmailConnection): ConnectionHealth` | same, plus `CancellationToken`; **never throws** for authorization or provider problems | §5.6 criterion 5: expiry, revocation, network loss and changed passwords are UI states with a `Reconnect` button, like `TunnelProvider::inspect()` |
| `getMessage(EmailConnection, string)` | same, plus `CancellationToken`; `EmailMessageNotFoundException` added | A deleted message is the common race after a sync and must not look like a provider outage; criterion 4 forbids answering from another connection |
| `send(EmailConnection, ApprovedOutgoingEmail)` | same, plus `CancellationToken`; `ApprovedOutgoingEmail` carries `proposalId`, `approvalId`, `approverUserId`, `connectionId`, `from`, `to/cc/bcc`, `subject`, body, attachments manifest, `bodyHash`, `nonce`, `approvedAt`, `expiresAt` | §11 frozen immutable proposal; §5.6 criterion 8. The DTO verifies `bodyHash = hashBody(text, html, manifest)` on construction and the connector recomputes it from the MIME it is about to send; `from` is checked with `EmailConnection::canSendAs()`, never used to look a connection up. Only `app/Approvals` constructs it (`AuthorizedActionProvenanceTest`) |
| `disconnect(EmailConnection): void` | same, plus `CancellationToken`; provider-side revoke only, idempotent, never throws for an unreachable provider | ADR-003 "Disconnect" order: revoke best-effort (10 s) → `SecretStore::delete` → rows. The secret and the rows are the caller's; a provider that is already gone must not block removal |
| — | `AuthorizationFlow` refuses an authorize URL carrying `client_secret`, `code_verifier`, tokens or a non-S256 challenge, and a `state` that differs from the flow's | ADR-003 D4: PHP owns `state` and the verifier; the URL that leaves the core must be safe to log at the GUI side. `secretService`/`secretAccount` are string placeholders for `App\Security\Secrets\SecretRef` until S1 lands it |
| — | `EmailConnectionDraft` refuses an `AuthorizationMethod` the `MailProviderKind` does not allow (Google/Microsoft: OAuth + PKCE only; iCloud/Proton: app password only; HEY: CLI session) and an OAuth draft without explicit scopes and a callback URI | §5.6 table "aldrig användarlösenord", "inte lova generell iCloud Mail OAuth", "minsta scopes" shown to the user before consent |

Not in the contract on purpose:

- **Embeddings** (`Embeddings::for()` in the SDK) — a separate `EmbeddingAdapter` in Sprint 2 (ADR-004 contract row 5); mixing it into `AiProviderAdapter` would force every text-only provider to stub it.
- **Session start** for automation — the session grant is a UI action with its own policy; S3 (G) defines `AutomationSessionManager`. `ComputerUseProvider` only consumes a live session.
- **`serve reset`** for the tunnel — ADR-006 D6.2 says the only mutating commands are the two D2 lines, while D2 also offers "Remove all Serve configuration" from `Drifted`/`FunnelDetected`. The contract expresses the replace path via `TunnelConfig::$replaceDriftedConfiguration` and leaves `reset` to the ADR owners (A) to reconcile before S4.
- **CRM writes** — mutations go through action proposals and approvals (§11); the repository is read-only.
- **Mail mutations other than `send()`** — §5.6 lists `reply`, `forward`, `delete`, `move`, `archive`
  as approval-driven *if introduced*. None is introduced; a reply is a `send()` with
  `inReplyToExternalId`. Adding one means a new authorized DTO under the same provenance test.
- **Attachment download** — `EmailAttachmentRef` is a reference; fetching bytes is a separate, later
  method with its own policy (§5.6 "bilagor öppnas aldrig automatiskt").
- **Push/webhook ingress** — `MailCapability::Push` is declared so the UI can show it; the signed
  ingress (Gmail Pub/Sub, Graph change notifications) is opt-in and out of scope for the stub.
- **Token exchange and refresh** — PHP's OAuth client (ADR-003 D4) performs the exchange on the IPC
  callback and the refresh inside the adapter; neither is a connector method, so no caller can be
  handed a token.

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

### 4.1 Mail vendors: how one `SyncCursor` carries three incompatible positions

No mail SDK is pinned yet (S3 picks them). `SyncCursor` is an opaque, provider-owned, versioned
token — `kind` (namespaced, owned by one connector), `scope` (folder id or `*`), `value` (the vendor
token verbatim), optional typed `parts`, `issuedAt`, `version` — persisted as sorted JSON through
`encode()`/`decode()` in `email_sync_cursors`. Nothing outside the adapter reads `value`. Expiry is
the connector's verdict, `SyncCursorInvalidException`, carrying `SyncCursor::initial($scope)`.

| Vendor | Cursor `kind` / `scope` / `value` / `parts` | Expiry signal | Status |
|---|---|---|---|
| Gmail API `users.history.list` | `gmail.history` / `*` (history is per mailbox, labels filter it) / `historyId` as a decimal string (opaque uint64, never compared across mailboxes) / — | HTTP 404 when `startHistoryId` is older than retained history → full resync | **Assumed from Google's documentation**; retention window and `historyTypes` behaviour to be verified by the S3 adapter |
| Microsoft Graph `/me/mailFolders/{id}/messages/delta` | `graph.delta` / the mail folder id (delta state is **per folder**) / the complete `@odata.deltaLink` (pass finished) or `@odata.nextLink` (mid-pass) URL / `link` → `delta`\|`next` | `410 Gone` with `syncStateNotFound` / `resyncRequired` → full resync of that folder | **Assumed from Microsoft's documentation**; Graph says to persist the whole link rather than `$deltatoken`/`$skiptoken` — confirm link length stays under `MAX_VALUE_LENGTH` (16 KiB) |
| IMAP (RFC 3501; RFC 7162 CONDSTORE/QRESYNC; RFC 6154 SPECIAL-USE for roles) | `imap.qresync` or `imap.uid` (no CONDSTORE) / the mailbox name as the server reports it / `"<uidvalidity>:<uid>:<modseq>"` / `uidvalidity`, `uid`, `modseq` as ints (`modseq` 0 without CONDSTORE) | `UIDVALIDITY` differs from the stored one → every UID is void → full resync of that mailbox | **Verified against the RFCs**, not against iCloud, Proton Bridge or any specific server; CONDSTORE support per server is to be probed |
| JMAP (Fastmail-style; possibly HEY) | `jmap.state` / JMAP account id / `Email/changes` state string / — | `cannotCalculateChanges` | **Assumed**; only if a JMAP provider is ever added |
| HEY CLI | `hey.cli` / per verification / per verification / — | per verification | **Unverified** (§5.3: check the installed CLI's commands before shipping); the kind is reserved |
| Proton Mail Bridge | IMAP kinds above, loopback host, Bridge-issued password, pinned self-signed certificate (`MailServerSettings::protonBridgeDefaults()`: 1143/1025 STARTTLS) | as IMAP | **Assumed from Bridge documentation**; ports and certificate handling to be verified on an installed Bridge |

Other vendor facts the DTOs encode and their status: OAuth 2.0 Authorization Code + PKCE S256 with
`state` for Google and Microsoft (verified against the §5.6 table and ADR-003 D4, exercised nowhere
yet); Google token revocation at `oauth2.googleapis.com/revoke` (ADR-003 E34); no programmatic
revoke on Microsoft identity platform (assumed — the adapter must confirm and otherwise point the
user to their account page); Gmail `users.messages.send` and Graph `sendMail` as the send APIs
(assumed); SMTP returning only `250 OK`, hence `SendReceipt::$messageIdHeader` (verified, RFC 5321).

## 5. Typing rules the tests enforce

`tests/Unit/Contracts/ModuleContractsTest.php` asserts, by reflection, for every interface method and
every DTO constructor and public method:

1. Every parameter has a native type; no parameter or return is `mixed`.
2. A native `array`, `iterable` or `Generator` has a generics docblock (`list<X>`, `array<K, V>`,
   `iterable<int, X>`, `Generator<K, V, S, R>`) with imported class names.
3. Every interface has at least one method taking a `CancellationToken` and at least one `@throws`.
4. Every DTO is `final readonly`.
5. Each interface docblock cites its blueprint paragraph (§3, or §5.6 for `EmailConnector`, whose
   docblock must also carry the "provisional until verified against each vendor SDK" note).
6. `EmailConnector::send()` takes exactly `(EmailConnection, ApprovedOutgoingEmail, CancellationToken)`.

Two places carry `array<string, mixed>` in a docblock, by choice: `AiToolCall::$arguments` and
`AiResponse::$structuredOutput`. Both are JSON objects whose shape is defined by a schema the request
supplied and validated by the PolicyGate or the adapter, not by the DTO. Typing them tighter would
either move validation into a value object that cannot see the schema, or re-encode them as strings.
External text (model output, screen content, email) is **data, not instruction** everywhere in these
contracts; the docblocks say so at each entry point.

## 6. Notes for the next items

- **S0-21 (negative security test)** — the boundary to assert is: a foreign email body is an
  `EmailMessage` whose `textBody`/`htmlBody` become an `AiMessage` with role `User`/`ToolResult`; the
  model may emit an `AiToolCall`; nothing may turn that into an `AuthorizedUiAction` or an
  `ApprovedOutgoingEmail` without an `action_approvals` row. The provenance test already fails the
  build if production code outside `app/Approvals` constructs either. Useful assertions the DTOs make
  cheap: an `ApprovedOutgoingEmail` cannot exist with an empty `approvalId`/`nonce`, with a
  `bodyHash` that does not match its content, or with a `From` the connection cannot send as
  (`EmailConnection::canSendAs()` is false and `send()` must throw `SendRefusedException`);
  `EmailAddress` refuses CR/LF so a header injected through a recipient is unrepresentable.
- **S3 mail adapter authors (F)** — the interface is frozen, the vendor semantics in §4.1 are not:
  verify each "assumed" row against the SDK you pin and update §4.1 before the adapter merges. Own
  exactly one `kind` prefix (`gmail`, `graph`, `imap`, `jmap`, `hey`); check `SyncCursor::belongsTo()`
  first and throw `SyncCursorInvalidException` for anything else. Never do database work inside the
  connector (ADR-002 D3.1) and never exceed `SyncRequest::$limit` — `SyncBatch` throws if you do.
  `verify()` reports, it does not throw; `disconnect()` is best-effort and idempotent. In `send()`
  perform the five `SendRefusalReason` checks in order before the first network byte, recompute
  `ApprovedOutgoingEmail::hashBody()` over the body you serialise (fold CRLF to LF first — the hash
  is defined on the canonical form), set the From header from `approved->from` and never from a
  provider default, and put the provider's message id or your generated `Message-ID` in the receipt.
  Credentials come from the SecretStore by `(secretService, secretAccount())`, never from a DTO; on a
  provider `401` re-fetch once (ADR-003 D3.1), then throw `AuthorizationRequiredException`. Every string
  from the provider is data: never interpolate it into an exception message or a log line.
- **E2-2 (schema)** — `email_sync_cursors.value` stores `SyncCursor::encode()` (sorted JSON, ≤ 16 KiB +
  envelope); one row per `(connection_id, scope)`; committed in the batch transaction (ADR-002 D3.4).
  `email_connections` stores what `EmailConnection` carries and nothing a connection cannot carry (no
  token, no password). `communications` rows are projected from `EmailMessage` + `EmailConnection`:
  `account_id` ← `connectionId`, `account_label` ← `label()`, `direction`, `occurred_at` ← `occurredAt`,
  `subject`, `excerpt` ← `excerpt()`, `from_address` ← `from->address`, `to_addresses` ← `toAddresses()`.
- **S1 SecretStore (H)** — `AuthorizationFlow` and `EmailConnection` carry `secretService` +
  `secretAccount` strings; replace both with `SecretRef` in one change and update the DTO tests.
- **S2 implementers** — the exceptions are the contract for failure; do not add new ones to a method
  without updating its `@throws` and this page. Add fields to DTOs freely (readonly, named arguments);
  never remove or retype one without a review.
