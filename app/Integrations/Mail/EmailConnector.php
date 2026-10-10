<?php

declare(strict_types=1);

namespace App\Integrations\Mail;

use App\Integrations\Mail\Dto\ApprovedOutgoingEmail;
use App\Integrations\Mail\Dto\AuthorizationFlow;
use App\Integrations\Mail\Dto\ConnectionHealth;
use App\Integrations\Mail\Dto\EmailConnection;
use App\Integrations\Mail\Dto\EmailConnectionDraft;
use App\Integrations\Mail\Dto\EmailFolder;
use App\Integrations\Mail\Dto\EmailMessage;
use App\Integrations\Mail\Dto\MailProviderKind;
use App\Integrations\Mail\Dto\SendReceipt;
use App\Integrations\Mail\Dto\SyncBatch;
use App\Integrations\Mail\Dto\SyncRequest;
use App\Integrations\Mail\Exceptions\AuthorizationRequiredException;
use App\Integrations\Mail\Exceptions\EmailMessageNotFoundException;
use App\Integrations\Mail\Exceptions\MailProviderUnavailableException;
use App\Integrations\Mail\Exceptions\SendRefusedException;
use App\Integrations\Mail\Exceptions\SyncCursorInvalidException;
use App\Support\Cancellation\CancellationToken;
use App\Support\Cancellation\OperationCancelledException;

/**
 * Blueprint §5.6 "Adapterkontrakt": `EmailConnector` with `authorization()`,
 * `verify()`, `sync()`, `folders()`, `getMessage()`, `send()` and `disconnect()`.
 * One implementation per `MailProviderKind` (Google, Microsoft, iCloud, HEY,
 * generic IMAP/SMTP, Proton Bridge — Microsoft 365/Outlook is mandatory for v1),
 * built in Sprint 3 by workstream F against this one shape. Every call takes
 * exactly one `EmailConnection`; the connector may read or send only through it and
 * resolves its credential from the SecretStore at call time (ADR-003), never from
 * the DTO. Read-only by default: the only mutating operation is `send()`, and it
 * accepts nothing but an `ApprovedOutgoingEmail` built by `App\Approvals` (§11).
 *
 * **Provisional semantics.** §5.6 marks the sketch "granska semantik mot faktiska
 * SDK:er". The method shapes here are frozen; what remains provisional until each
 * S3 adapter has run against the real vendor SDK is recorded per method below and
 * in `docs/architecture/module-contracts.md` §4: Gmail history ids, Graph per-folder
 * delta links, IMAP UIDVALIDITY/UID/MODSEQ and JMAP state are mapped from vendor
 * documentation, not from executed calls; the HEY CLI contract is unverified
 * (§5.3). An adapter author who finds a mismatch changes the adapter or opens a
 * contract review — never a silent reinterpretation of a field.
 *
 * Deliberate differences from the §5.6 sketch (see module-contracts.md §3):
 * `provider()` added for the registry; `?SyncCursor` became a `SyncRequest`
 * carrying `SyncCursor::initial()` and a page limit; `folders()` is typed
 * `iterable<int, EmailFolder>`; every call that reaches the network takes a
 * `CancellationToken`; failure is a typed exception per method.
 *
 * Everything a provider returns — headers, bodies, folder names, error strings — is
 * external text: data, never instruction (§11). Nothing a connector returns carries
 * authority; prompt injection in a message can never reach `send()` without an
 * `action_approvals` row (S0-21).
 */
interface EmailConnector
{
    /**
     * The provider family this connector serves; the registry keys on it
     * (`email_connections.provider`, ADR-002 D8).
     */
    public function provider(): MailProviderKind;

    /**
     * Start connecting an account: build the OAuth 2.1 + PKCE authorize URL with a
     * fresh `state` and cached verifier (ADR-003 D4), or describe the app-password /
     * CLI step the wizard must drive. Requests exactly the scopes in the draft, never
     * more. Performs no network call to the provider and never returns a secret.
     *
     * Provisional: Microsoft tenant admin consent (`prompt=admin_consent`) and
     * Google restricted-scope verification are documented requirements, not yet
     * exercised (§5.6 "måste dokumenteras och testas före offentlig release").
     *
     * @throws MailProviderUnavailableException Bridge not running or CLI not installed, when the method needs one to even begin
     */
    public function authorization(EmailConnectionDraft $draft): AuthorizationFlow;

    /**
     * Read-only connection test: credential present and accepted, scopes still
     * granted, mailbox reachable, quota headroom. Never throws for an authorization
     * or provider problem — those are `ConnectionHealthState`s the UI maps to
     * `Reconnect` (§5.6 criterion 5). Must finish within the UI's budget (10 s) and
     * must not count as a sync.
     *
     * @throws OperationCancelledException
     */
    public function verify(EmailConnection $connection, CancellationToken $cancellation): ConnectionHealth;

    /**
     * Fetch one page of changes from `request->cursor` for its scope and return the
     * page with the cursor to commit alongside it (ADR-002 D3.4). The initial cursor
     * starts a bootstrap pass; `hasMore` drives pagination; the batch never exceeds
     * `request->limit`. No database work happens inside the connector — it fetches,
     * the caller writes (ADR-002 D3.1). Idempotent: re-running the same request after
     * a crash yields the same rows for the caller's upsert.
     *
     * Provisional per vendor: Gmail `users.history.list` (`startHistoryId`,
     * `historyTypes`, 404 → resync); Graph `/mailFolders/{id}/messages/delta`
     * (`@odata.nextLink`/`@odata.deltaLink`, `410` → resync, delta is per folder);
     * IMAP `UID FETCH` with `CHANGEDSINCE`/`QRESYNC` where advertised, else UID
     * ranges (`UIDVALIDITY` change → resync); HEY CLI after verification.
     *
     * @throws SyncCursorInvalidException the provider rejected the cursor; restart from `restartFrom`
     * @throws AuthorizationRequiredException credential expired, revoked or rejected
     * @throws MailProviderUnavailableException network, throttling, quota, Bridge/CLI absent; retry the same connection later
     * @throws OperationCancelledException the token was cancelled; nothing has been committed by the connector
     */
    public function sync(EmailConnection $connection, SyncRequest $request, CancellationToken $cancellation): SyncBatch;

    /**
     * List the provider's folders (Gmail labels, Graph mail folders, IMAP mailboxes)
     * with neutral roles, for the wizard's folder selection and for `SyncCursor`
     * scopes. Order is provider order; the caller sorts by role for display.
     *
     * @return iterable<int, EmailFolder>
     *
     * @throws AuthorizationRequiredException
     * @throws MailProviderUnavailableException
     * @throws OperationCancelledException
     */
    public function folders(EmailConnection $connection, CancellationToken $cancellation): iterable;

    /**
     * Fetch one full, normalised message by its provider id, within this connection
     * only (§5.6 criterion 4: an id from another account is `NotFound`, never a
     * lookup elsewhere). Bodies are sanitised before they are returned; attachments
     * are referenced, never downloaded here.
     *
     * @throws EmailMessageNotFoundException the id does not exist in this connection (any more)
     * @throws AuthorizationRequiredException
     * @throws MailProviderUnavailableException
     * @throws OperationCancelledException
     */
    public function getMessage(EmailConnection $connection, string $externalId, CancellationToken $cancellation): EmailMessage;

    /**
     * Send exactly one approved email through exactly this connection. Before the
     * first byte leaves, the connector checks in order: `approved->connectionId`
     * equals `connection->id`; `connection->canSendAs(approved->from)`; the approval
     * has not expired; `ApprovedOutgoingEmail::hashBody()` recomputed from the
     * content it is about to serialise equals `approved->bodyHash`; the connection
     * has the `Send` capability. Any failure is a `SendRefusedException` and an audit
     * event, with nothing sent. The nonce is consumed by `App\Approvals` before the
     * call; the connector records it in the receipt so delivery is idempotent
     * (criterion 7 "exakt en gång"). The provider's send API is used where it exists
     * (Gmail `users.messages.send`, Graph `sendMail`), SMTP otherwise; the From
     * header is always the approved address, never a provider default.
     *
     * @throws SendRefusedException policy refusal before sending (see `SendRefusalReason`)
     * @throws AuthorizationRequiredException the send scope is gone or the credential was rejected
     * @throws MailProviderUnavailableException the provider refused or was unreachable; the caller may retry the *same* approval only while it is unexpired and the receipt is absent
     * @throws OperationCancelledException cancelled before the provider accepted the message
     */
    public function send(EmailConnection $connection, ApprovedOutgoingEmail $approved, CancellationToken $cancellation): SendReceipt;

    /**
     * Provider-side teardown for `Disconnect` (ADR-003 "Disconnect" order): revoke
     * the token at the provider best-effort within a 10 s budget (Google
     * `oauth2.googleapis.com/revoke`; Microsoft has no programmatic revoke — the user
     * is pointed to their account page), stop any push subscription, forget the
     * CLI session. Deleting the SecretStore item and the `email_*` rows is the
     * caller's job and happens after this returns. Idempotent and never throws for
     * a provider that is unreachable or already revoked: the connection is being
     * removed either way.
     *
     * @throws OperationCancelledException
     */
    public function disconnect(EmailConnection $connection, CancellationToken $cancellation): void;
}
