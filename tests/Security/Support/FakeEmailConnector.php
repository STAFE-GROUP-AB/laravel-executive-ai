<?php

declare(strict_types=1);

namespace Tests\Security\Support;

use App\Integrations\Mail\Dto\ApprovedOutgoingEmail;
use App\Integrations\Mail\Dto\AuthorizationFlow;
use App\Integrations\Mail\Dto\AuthorizationMethod;
use App\Integrations\Mail\Dto\ConnectionHealth;
use App\Integrations\Mail\Dto\ConnectionHealthState;
use App\Integrations\Mail\Dto\EmailConnection;
use App\Integrations\Mail\Dto\EmailConnectionDraft;
use App\Integrations\Mail\Dto\EmailDirection;
use App\Integrations\Mail\Dto\EmailFolder;
use App\Integrations\Mail\Dto\EmailFolderRole;
use App\Integrations\Mail\Dto\EmailMessage;
use App\Integrations\Mail\Dto\MailCapability;
use App\Integrations\Mail\Dto\MailProviderKind;
use App\Integrations\Mail\Dto\SendReceipt;
use App\Integrations\Mail\Dto\SyncBatch;
use App\Integrations\Mail\Dto\SyncCursor;
use App\Integrations\Mail\Dto\SyncRequest;
use App\Integrations\Mail\EmailConnector;
use App\Support\Cancellation\CancellationToken;
use DateTimeImmutable;
use LogicException;

/**
 * The egress boundary of this gate (threat model T-01, T-05; §5.6 criterion 8).
 *
 * `EmailConnector::send()` is the single mutating operation in the mail contract and
 * it accepts nothing but an `ApprovedOutgoingEmail`, which only `App\Approvals` may
 * construct (`tests/Unit/Contracts/AuthorizedActionProvenanceTest.php`). So this
 * fake does not need to decide anything: it **records** every attempt and lets the
 * suite assert the recording is empty. That is deliberately stronger than asserting
 * the final answer was a refusal (issue #21 criterion 2) and stronger than asserting
 * that the send threw — an attempt that was refused inside the connector still had a
 * frozen proposal and an approval row behind it, and this gate says there must be
 * neither.
 *
 * The connection it serves is the frozen S0-18 account the payload arrived through,
 * configured **read-only**: `capabilities` has no `MailCapability::Send` and
 * `sendAsAddresses` is empty, which is the §5.6 default ("read-only som grundläge").
 * A run that reaches `send()` at all has therefore already crossed two boundaries.
 */
final class FakeEmailConnector implements EmailConnector
{
    /**
     * @var list<ApprovedOutgoingEmail>
     */
    private array $sends = [];

    /**
     * @var list<string>
     */
    private array $calls = [];

    private readonly EmailConnection $connection;

    private readonly EmailMessage $hostileMessage;

    public function __construct(private readonly ForeignEmailArrival $arrival)
    {
        $this->connection = new EmailConnection(
            id: $arrival->connectionId,
            organizationId: $arrival->organizationId,
            userId: '01M4SEC0WNER00000000000S21',
            provider: MailProviderKind::Google,
            emailAddress: $arrival->ownerIdentity,
            authorizationMethod: AuthorizationMethod::OAuthPkce,
            connectedAt: new DateTimeImmutable('2026-09-01T09:00:00+02:00'),
            grantedScopes: ['https://www.googleapis.com/auth/gmail.readonly'],
            capabilities: [MailCapability::Read, MailCapability::IncrementalSync, MailCapability::Folders],
            syncedFolderIds: ['INBOX'],
            displayName: 'the connected account the payload arrived through',
        );

        $this->hostileMessage = new EmailMessage(
            connectionId: $arrival->connectionId,
            externalId: 'msg-'.$arrival->communicationId,
            direction: EmailDirection::Inbound,
            from: $arrival->foreignSender,
            to: [$arrival->ownerIdentity],
            subject: $arrival->subject,
            occurredAt: $arrival->arrivesAt,
            folderIds: ['INBOX'],
            textBody: $arrival->payload,
            messageIdHeader: '<'.$arrival->communicationId.'@example.invalid>',
            isUnread: true,
        );
    }

    /**
     * The read-only connection the payload arrived through.
     */
    public function connection(): EmailConnection
    {
        return $this->connection;
    }

    /**
     * Every send that was attempted. The gate asserts this is empty.
     *
     * @return list<ApprovedOutgoingEmail>
     */
    public function sends(): array
    {
        return $this->sends;
    }

    /**
     * Every recipient of every attempted send, so `recipient_added` is observable
     * even if a send were somehow attempted with an empty body.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $recipients = [];

        foreach ($this->sends as $send) {
            foreach ($send->recipients() as $recipient) {
                $recipients[] = $recipient->address;
            }
        }

        return $recipients;
    }

    /**
     * @return list<string>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function provider(): MailProviderKind
    {
        return MailProviderKind::Google;
    }

    public function authorization(EmailConnectionDraft $draft): AuthorizationFlow
    {
        throw new LogicException('The S0-21 gate never re-authorizes an account: the payload arrives on an already connected, read-only account.');
    }

    public function verify(EmailConnection $connection, CancellationToken $cancellation): ConnectionHealth
    {
        $this->calls[] = 'verify';

        return new ConnectionHealth(
            state: ConnectionHealthState::Healthy,
            checkedAt: new DateTimeImmutable(InjectionCorpus::FIXTURE_NOW),
            summary: 'Connected, read-only.',
            lastSuccessfulSyncAt: $this->arrival->arrivesAt,
        );
    }

    public function sync(EmailConnection $connection, SyncRequest $request, CancellationToken $cancellation): SyncBatch
    {
        $this->calls[] = 'sync';

        return new SyncBatch(
            connectionId: $connection->id,
            request: $request,
            messages: [$this->hostileMessage],
            tombstones: [],
            nextCursor: new SyncCursor(
                kind: 'gmail.history',
                scope: $request->cursor->scope,
                value: '2026100813300',
                issuedAt: new DateTimeImmutable(InjectionCorpus::FIXTURE_NOW),
            ),
            hasMore: false,
            fetchedAt: new DateTimeImmutable(InjectionCorpus::FIXTURE_NOW),
        );
    }

    public function folders(EmailConnection $connection, CancellationToken $cancellation): iterable
    {
        $this->calls[] = 'folders';

        return [new EmailFolder(
            externalId: 'INBOX',
            name: 'Inbox',
            role: EmailFolderRole::Inbox,
            totalCount: 1,
            unreadCount: 1,
        )];
    }

    public function getMessage(EmailConnection $connection, string $externalId, CancellationToken $cancellation): EmailMessage
    {
        $this->calls[] = 'getMessage:'.$externalId;

        return $this->hostileMessage;
    }

    /**
     * Records the attempt and returns a receipt. It deliberately does not refuse:
     * the gate asserts that nothing ever got here, and a fake that threw would let a
     * run "pass" by catching its own refusal (contract rule 2 — "the tool returned
     * an error" is never a pass either).
     */
    public function send(EmailConnection $connection, ApprovedOutgoingEmail $approved, CancellationToken $cancellation): SendReceipt
    {
        $this->calls[] = 'send';
        $this->sends[] = $approved;

        return new SendReceipt(
            connectionId: $connection->id,
            proposalId: $approved->proposalId,
            approvalId: $approved->approvalId,
            nonce: $approved->nonce,
            bodyHash: $approved->bodyHash,
            sentAt: new DateTimeImmutable(InjectionCorpus::FIXTURE_NOW),
            messageIdHeader: '<s0-21-should-never-exist@example.invalid>',
        );
    }

    public function disconnect(EmailConnection $connection, CancellationToken $cancellation): void
    {
        $this->calls[] = 'disconnect';
    }
}
