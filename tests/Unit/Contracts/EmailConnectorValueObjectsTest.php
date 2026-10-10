<?php

declare(strict_types=1);

use App\Approvals\Policies\PolicyDeniedException;
use App\Integrations\Mail\Dto\ApprovedOutgoingEmail;
use App\Integrations\Mail\Dto\AuthorizationFlow;
use App\Integrations\Mail\Dto\AuthorizationMethod;
use App\Integrations\Mail\Dto\ConnectionHealth;
use App\Integrations\Mail\Dto\ConnectionHealthState;
use App\Integrations\Mail\Dto\EmailAddress;
use App\Integrations\Mail\Dto\EmailAttachmentRef;
use App\Integrations\Mail\Dto\EmailConnection;
use App\Integrations\Mail\Dto\EmailConnectionDraft;
use App\Integrations\Mail\Dto\EmailDirection;
use App\Integrations\Mail\Dto\EmailMessage;
use App\Integrations\Mail\Dto\MailCapability;
use App\Integrations\Mail\Dto\MailProviderKind;
use App\Integrations\Mail\Dto\MailServerSettings;
use App\Integrations\Mail\Dto\SendReceipt;
use App\Integrations\Mail\Dto\SyncBatch;
use App\Integrations\Mail\Dto\SyncCursor;
use App\Integrations\Mail\Dto\SyncRequest;
use App\Integrations\Mail\Exceptions\AuthorizationRequiredReason;
use App\Integrations\Mail\Exceptions\SendRefusalReason;
use App\Integrations\Mail\Exceptions\SendRefusedException;
use App\Integrations\Mail\Exceptions\SyncCursorInvalidException;

/*
|--------------------------------------------------------------------------
| S0-17 — the behaviour the EmailConnector DTOs carry
|--------------------------------------------------------------------------
|
| Constructor validation S3 adapter authors and the policy layer rely on:
| the SyncCursor round-trips the Gmail, Graph and IMAP positions without a
| provider branch, an ApprovedOutgoingEmail cannot exist without every §11
| approval field, recipients are canonical, and a SyncBatch never claims more
| than it was asked for. No database is touched.
|
*/

const S0_17_APPROVED_AT = '2026-10-09T09:00:00+02:00';

/**
 * @param  array<string, mixed>  $overrides
 */
function s0_17_approved_email(array $overrides = []): ApprovedOutgoingEmail
{
    $attachments = $overrides['attachments'] ?? [];
    $textBody = $overrides['textBody'] ?? "Hej Karin,\r\n\r\nbifogat offerten.\r\n";
    $htmlBody = array_key_exists('htmlBody', $overrides) ? $overrides['htmlBody'] : '<p>Hej Karin,</p><p>bifogat offerten.</p>';

    $arguments = [
        'proposalId' => '01M4PROPOSAL0000000000000A',
        'approvalId' => '01M4APPROVAL0000000000000A',
        'approverUserId' => '01M1DRV4R0N9670DRZXA824K45',
        'connectionId' => '01M4FJ7YKGT1R7QHBVEY0MNKEC',
        'from' => new EmailAddress('andreas@example.se'),
        'to' => [new EmailAddress('karin.nordlund@nordlund.example.se', 'Karin Nordlund')],
        'cc' => [],
        'bcc' => [],
        'subject' => 'Offert 2026-114 ombyggnad lager',
        'textBody' => $textBody,
        'htmlBody' => $htmlBody,
        'attachments' => $attachments,
        'bodyHash' => ApprovedOutgoingEmail::hashBody($textBody, $htmlBody, $attachments),
        'nonce' => 'nonce-01M4APPROVAL',
        'approvedAt' => new DateTimeImmutable(S0_17_APPROVED_AT),
        'expiresAt' => new DateTimeImmutable('2026-10-09T09:15:00+02:00'),
    ];

    return new ApprovedOutgoingEmail(...array_replace($arguments, $overrides));
}

function s0_17_connection(MailProviderKind $provider = MailProviderKind::Google, bool $canSend = true): EmailConnection
{
    $address = new EmailAddress('andreas@example.se');

    return new EmailConnection(
        id: '01M4FJ7YKGT1R7QHBVEY0MNKEC',
        organizationId: '01M1DRV4R0PA37N135HTBQ3Y60',
        userId: '01M1DRV4R0N9670DRZXA824K45',
        provider: $provider,
        emailAddress: $address,
        authorizationMethod: $provider->authorizationMethods()[0],
        connectedAt: new DateTimeImmutable('2026-10-01T08:00:00+02:00'),
        grantedScopes: ['https://www.googleapis.com/auth/gmail.readonly'],
        capabilities: $canSend ? [MailCapability::Read, MailCapability::IncrementalSync, MailCapability::Send] : [MailCapability::Read],
        sendAsAddresses: $canSend ? [$address, new EmailAddress('sales@example.se')] : [],
        serverSettings: $provider->requiresServerSettings() ? new MailServerSettings('imap.example.se', 993, 'smtp.example.se', 587) : null,
    );
}

function s0_17_message(string $connectionId = '01M4FJ7YKGT1R7QHBVEY0MNKEC', string $externalId = 'msg-1'): EmailMessage
{
    return new EmailMessage(
        connectionId: $connectionId,
        externalId: $externalId,
        direction: EmailDirection::Inbound,
        from: new EmailAddress('karin.nordlund@nordlund.example.se', 'Karin Nordlund'),
        to: [new EmailAddress('andreas@example.se'), new EmailAddress('andreas@example.se')],
        subject: 'Re: Offert 2026-114',
        occurredAt: new DateTimeImmutable('2026-10-08T14:48:00+02:00'),
        htmlBody: "<p>Hej   Andreas,</p>\n<p>vi behöver ert svar <b>senast fredag</b>.</p>",
    );
}

/*
|--------------------------------------------------------------------------
| SyncCursor
|--------------------------------------------------------------------------
*/

it('starts every scope from the initial cursor and recognises it', function (): void {
    $account = SyncCursor::initial();
    $folder = SyncCursor::initial('AQMkADAwATM3ZmYAZS1kNzI4LTY1ZTEtMDACLTAwCgAuAAAD');

    expect($account->isInitial())->toBeTrue()
        ->and($account->scope)->toBe('*')
        ->and($account->value)->toBe('')
        ->and($folder->isInitial())->toBeTrue()
        ->and($folder->belongsTo('gmail'))->toBeTrue()
        ->and($folder->belongsTo('imap'))->toBeTrue()
        ->and(fn () => new SyncCursor(SyncCursor::INITIAL_KIND, '*', '12345'))->toThrow(InvalidArgumentException::class);
});

it('round-trips the Gmail, Graph and IMAP positions through one opaque shape', function (SyncCursor $cursor, string $owner): void {
    $decoded = SyncCursor::decode($cursor->encode());

    expect($decoded->equals($cursor))->toBeTrue()
        ->and($decoded->kind)->toBe($cursor->kind)
        ->and($decoded->scope)->toBe($cursor->scope)
        ->and($decoded->value)->toBe($cursor->value)
        ->and($decoded->parts)->toEqualCanonicalizing($cursor->parts)
        ->and($decoded->issuedAt?->format(DATE_RFC3339_EXTENDED))->toBe($cursor->issuedAt?->format(DATE_RFC3339_EXTENDED))
        ->and($decoded->isInitial())->toBeFalse()
        ->and($decoded->belongsTo($owner))->toBeTrue()
        ->and($decoded->belongsTo('somebody_else'))->toBeFalse();
})->with([
    'gmail history id' => [
        new SyncCursor('gmail.history', '*', '1844674407370955', issuedAt: new DateTimeImmutable('2026-10-08T09:12:00+02:00')),
        'gmail',
    ],
    'graph delta link per folder' => [
        new SyncCursor(
            'graph.delta',
            'AQMkADAwATM3ZmYAZS1kNzI4LTY1ZTEtMDACLTAwCgAuAAAD',
            'https://graph.microsoft.com/v1.0/me/mailFolders/AQMkADAwATM3ZmYAZS1kNzI4LTY1ZTEtMDACLTAwCgAuAAAD/messages/delta?$deltatoken=deltadeltadelta0001',
            ['link' => 'delta'],
        ),
        'graph',
    ],
    'imap uidvalidity uid modseq' => [
        new SyncCursor('imap.qresync', 'INBOX', '1698765432:4821:90210', ['uidvalidity' => 1698765432, 'uid' => 4821, 'modseq' => 90210]),
        'imap',
    ],
    'jmap state' => [
        new SyncCursor('jmap.state', 'u12345678', 'S9f2a7', version: 1),
        'jmap',
    ],
]);

it('keeps the IMAP triple addressable as typed parts', function (): void {
    $cursor = new SyncCursor('imap.qresync', 'Sent Messages', '1698765432:4821:90210', ['uidvalidity' => 1698765432, 'uid' => 4821, 'modseq' => 90210]);

    expect($cursor->part('uidvalidity'))->toBe(1698765432)
        ->and($cursor->part('uid'))->toBe(4821)
        ->and($cursor->part('modseq'))->toBe(90210)
        ->and($cursor->part('missing'))->toBeNull();
});

it('refuses malformed cursors', function (): void {
    expect(fn () => new SyncCursor('gmail.history', '*', ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SyncCursor('Gmail History', '*', '1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SyncCursor('gmail.history', '', '1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SyncCursor('gmail.history', '*', str_repeat('x', SyncCursor::MAX_VALUE_LENGTH + 1)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SyncCursor('imap.qresync', 'INBOX', '1:2:3', ['UidValidity' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SyncCursor('gmail.history', '*', '1', version: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SyncCursor::decode('not json'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => SyncCursor::decode('{"kind":"imap.qresync","scope":"INBOX","value":"1:2:3","parts":{"uid":1.5}}'))->toThrow(InvalidArgumentException::class, 'ints or strings')
        ->and(fn () => SyncCursor::decode('{"kind":"imap.qresync","scope":"INBOX","value":"1:2:3","parts":[1,2]}'))->toThrow(InvalidArgumentException::class, 'ints or strings')
        ->and(fn () => SyncCursor::decode('{"kind":"gmail.history"}'))->toThrow(InvalidArgumentException::class);
});

it('says "cursor expired, full resync required" through SyncCursorInvalidException with an initial restart point', function (): void {
    $rejected = new SyncCursor('imap.qresync', 'INBOX', '1698765432:4821:90210');
    $exception = new SyncCursorInvalidException('01M4FJ7YKGT1R7QHBVEY0MNKEC', $rejected, SyncCursor::initial('INBOX'));

    expect($exception->restartFrom->isInitial())->toBeTrue()
        ->and($exception->restartFrom->scope)->toBe('INBOX')
        ->and($exception->rejected)->toBe($rejected)
        ->and($exception->getMessage())->toContain('full resync');
});

/*
|--------------------------------------------------------------------------
| SyncRequest / SyncBatch
|--------------------------------------------------------------------------
*/

it('bounds a sync page to the ADR-002 batch size', function (): void {
    expect((new SyncRequest(SyncCursor::initial()))->limit)->toBe(SyncRequest::DEFAULT_LIMIT)
        ->and(fn () => new SyncRequest(SyncCursor::initial(), limit: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SyncRequest(SyncCursor::initial(), limit: SyncRequest::MAX_LIMIT + 1))->toThrow(InvalidArgumentException::class);
});

it('never lets a sync batch claim more than requested, leave the cursor initial, change scope or mix connections', function (): void {
    $request = new SyncRequest(SyncCursor::initial('INBOX'), limit: 2);
    $next = new SyncCursor('imap.qresync', 'INBOX', '1:10:5');
    $fetchedAt = new DateTimeImmutable('2026-10-08T15:00:00+02:00');

    $batch = new SyncBatch('01M4FJ7YKGT1R7QHBVEY0MNKEC', $request, [s0_17_message()], ['msg-gone'], $next, hasMore: true, fetchedAt: $fetchedAt);

    expect($batch->count())->toBe(2)
        ->and($batch->isEmpty())->toBeFalse()
        ->and(fn () => new SyncBatch('01M4FJ7YKGT1R7QHBVEY0MNKEC', $request, [s0_17_message(), s0_17_message(externalId: 'msg-2')], ['msg-gone'], $next, false, $fetchedAt))
        ->toThrow(InvalidArgumentException::class, 'more items than the request')
        ->and(fn () => new SyncBatch('01M4FJ7YKGT1R7QHBVEY0MNKEC', $request, [], [], SyncCursor::initial('INBOX'), false, $fetchedAt))
        ->toThrow(InvalidArgumentException::class, 'advance the cursor')
        ->and(fn () => new SyncBatch('01M4FJ7YKGT1R7QHBVEY0MNKEC', $request, [], [], new SyncCursor('imap.qresync', 'Archive', '1:1:1'), false, $fetchedAt))
        ->toThrow(InvalidArgumentException::class, 'same scope')
        ->and(fn () => new SyncBatch('01M4FJ7YKGT1R7QHBVEY0MNKEC', $request, [s0_17_message(connectionId: '01M1GEYWB0YATT98XYZJ2ETBM2')], [], $next, false, $fetchedAt))
        ->toThrow(InvalidArgumentException::class, 'criterion 4');
});

/*
|--------------------------------------------------------------------------
| EmailAddress / EmailMessage
|--------------------------------------------------------------------------
*/

it('canonicalises addresses and refuses anything that could inject a header', function (): void {
    $normalized = EmailAddress::normalize(' <Karin.Nordlund@Nordlund.Example.SE> ', "Karin\r\nBcc: evil@example.net");

    expect($normalized->address)->toBe('karin.nordlund@nordlund.example.se')
        ->and($normalized->name)->toBe('Karin Bcc: evil@example.net')
        ->and($normalized->domain())->toBe('nordlund.example.se')
        ->and(fn () => new EmailAddress('Karin@example.se'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EmailAddress('karin @example.se'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EmailAddress('karin@example.se, evil@example.net'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EmailAddress("karin@example.se\r\nbcc: x@example.net"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EmailAddress('karin@example.se', "Karin\nNordlund"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EmailAddress(''))->toThrow(InvalidArgumentException::class);
});

it('feeds the S0-18 communications projection from a message and its connection', function (): void {
    $message = s0_17_message();
    $connection = s0_17_connection();

    expect($connection->label())->toBe('andreas@example.se (Google Workspace)')
        ->and($message->direction->value)->toBe('inbound')
        ->and($message->from->address)->toBe('karin.nordlund@nordlund.example.se')
        ->and($message->toAddresses())->toBe(['andreas@example.se'])
        ->and($message->excerpt())->toBe('Hej Andreas, vi behöver ert svar senast fredag.')
        ->and($message->excerpt(12))->toBe('Hej Andreas…')
        ->and(fn () => s0_17_message(externalId: ''))->toThrow(InvalidArgumentException::class);
});

it('labels every provider the way the fixture pack does', function (): void {
    expect(MailProviderKind::Microsoft->label())->toBe('Microsoft 365')
        ->and(MailProviderKind::ICloud->label())->toBe('iCloud Mail')
        ->and(MailProviderKind::ProtonBridge->value)->toBe('proton_bridge')
        ->and(MailProviderKind::Google->authorizationMethods())->toBe([AuthorizationMethod::OAuthPkce])
        ->and(MailProviderKind::Microsoft->authorizationMethods())->toBe([AuthorizationMethod::OAuthPkce])
        ->and(MailProviderKind::Hey->authorizationMethods())->toBe([AuthorizationMethod::CliSession]);
});

/*
|--------------------------------------------------------------------------
| EmailConnectionDraft / AuthorizationFlow / EmailConnection / ConnectionHealth
|--------------------------------------------------------------------------
*/

it('refuses a password flow for Google or Microsoft and an OAuth draft without explicit scopes', function (): void {
    $address = new EmailAddress('andreas@example.de');

    expect(fn () => new EmailConnectionDraft('org', 'user', MailProviderKind::Microsoft, $address, AuthorizationMethod::AppPassword))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EmailConnectionDraft('org', 'user', MailProviderKind::Microsoft, $address, AuthorizationMethod::OAuthPkce, [], 'lxai://oauth/callback'))
        ->toThrow(InvalidArgumentException::class, 'scopes')
        ->and(fn () => new EmailConnectionDraft('org', 'user', MailProviderKind::Microsoft, $address, AuthorizationMethod::OAuthPkce, ['Mail.Read', 'offline_access']))
        ->toThrow(InvalidArgumentException::class, 'callback')
        ->and(fn () => new EmailConnectionDraft('org', 'user', MailProviderKind::Imap, $address, AuthorizationMethod::AppPassword))
        ->toThrow(InvalidArgumentException::class, 'server settings')
        ->and(fn () => new EmailConnectionDraft('org', 'user', MailProviderKind::ProtonBridge, $address, AuthorizationMethod::AppPassword, serverSettings: new MailServerSettings('imap.example.net', 993, 'smtp.example.net', 587)))
        ->toThrow(InvalidArgumentException::class, 'Bridge');

    $draft = new EmailConnectionDraft('org', 'user', MailProviderKind::Microsoft, $address, AuthorizationMethod::OAuthPkce, ['Mail.Read', 'offline_access'], 'lxai://oauth/callback');

    expect($draft->requestedScopes)->toBe(['Mail.Read', 'offline_access']);
});

it('never lets an authorization flow carry a secret and insists on PKCE S256 with the matching state', function (): void {
    $expiresAt = new DateTimeImmutable('2026-10-09T09:10:00+02:00');
    $url = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?client_id=x&response_type=code&redirect_uri=lxai%3A%2F%2Foauth%2Fcallback&scope=Mail.Read&state=st_01&code_challenge=abc&code_challenge_method=S256';

    $flow = new AuthorizationFlow(AuthorizationMethod::OAuthPkce, 'st_01', $expiresAt, $url, ['Mail.Read']);

    expect($flow->authorizeUrl)->toBe($url)
        ->and($flow->isExpired(new DateTimeImmutable('2026-10-09T09:10:00+02:00')))->toBeTrue()
        ->and(fn () => new AuthorizationFlow(AuthorizationMethod::OAuthPkce, 'st_01', $expiresAt, $url.'&client_secret=shh'))->toThrow(InvalidArgumentException::class, 'secret')
        ->and(fn () => new AuthorizationFlow(AuthorizationMethod::OAuthPkce, 'st_01', $expiresAt, $url.'&code_verifier=v'))->toThrow(InvalidArgumentException::class, 'secret')
        ->and(fn () => new AuthorizationFlow(AuthorizationMethod::OAuthPkce, 'st_01', $expiresAt, str_replace('S256', 'plain', $url)))->toThrow(InvalidArgumentException::class, 'S256')
        ->and(fn () => new AuthorizationFlow(AuthorizationMethod::OAuthPkce, 'st_02', $expiresAt, $url))->toThrow(InvalidArgumentException::class, 'state')
        ->and(fn () => new AuthorizationFlow(AuthorizationMethod::OAuthPkce, 'st_01', $expiresAt, str_replace('https://', 'http://', $url)))->toThrow(InvalidArgumentException::class, 'https')
        ->and(fn () => new AuthorizationFlow(AuthorizationMethod::AppPassword, 'st_03', $expiresAt))->toThrow(InvalidArgumentException::class, 'SecretStore')
        ->and(fn () => new AuthorizationFlow(AuthorizationMethod::AppPassword, 'st_03', $expiresAt, $url, secretAccount: '01M4'))->toThrow(InvalidArgumentException::class, 'Only an OAuth');

    $password = new AuthorizationFlow(AuthorizationMethod::AppPassword, 'st_03', $expiresAt, secretAccount: '01M1GENQC0H10CAG1VZ6PZCXTW', instructions: ['Create an app-specific password.']);

    expect($password->secretService)->toBe(AuthorizationFlow::DEFAULT_SECRET_SERVICE)
        ->and($password->secretAccount)->toBe('01M1GENQC0H10CAG1VZ6PZCXTW');
});

it('binds send identities to the connection and defaults the Keychain account to the connection id', function (): void {
    $connection = s0_17_connection();

    expect($connection->secretAccount())->toBe($connection->id)
        ->and($connection->canSendAs(new EmailAddress('sales@example.se')))->toBeTrue()
        ->and($connection->canSendAs(new EmailAddress('ceo@example.se')))->toBeFalse()
        ->and($connection->owns(new EmailAddress('andreas@example.se')))->toBeTrue()
        ->and(s0_17_connection(canSend: false)->canSendAs(new EmailAddress('andreas@example.se')))->toBeFalse()
        ->and(fn () => new EmailConnection('id', 'org', 'user', MailProviderKind::Google, new EmailAddress('a@example.se'), AuthorizationMethod::OAuthPkce, new DateTimeImmutable, capabilities: [MailCapability::Send]))
        ->toThrow(InvalidArgumentException::class, 'send as')
        ->and(fn () => new EmailConnection('id', 'org', 'user', MailProviderKind::ICloud, new EmailAddress('a@example.it'), AuthorizationMethod::AppPassword, new DateTimeImmutable))
        ->toThrow(InvalidArgumentException::class, 'server settings');
});

it('ties the authorization reason to the authorization_required state', function (): void {
    $checkedAt = new DateTimeImmutable('2026-10-09T09:00:00+02:00');

    $health = new ConnectionHealth(ConnectionHealthState::AuthorizationRequired, $checkedAt, 'Reconnect this account.', authorizationReason: AuthorizationRequiredReason::TokenRevoked);

    expect($health->isUsable())->toBeFalse()
        ->and($health->state->needsReauthorization())->toBeTrue()
        ->and((new ConnectionHealth(ConnectionHealthState::Degraded, $checkedAt, 'Slow but working.'))->isUsable())->toBeTrue()
        ->and(fn () => new ConnectionHealth(ConnectionHealthState::Healthy, $checkedAt, 'ok', authorizationReason: AuthorizationRequiredReason::TokenExpired))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ConnectionHealth(ConnectionHealthState::AuthorizationRequired, $checkedAt, 'Reconnect.'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ConnectionHealth(ConnectionHealthState::Healthy, $checkedAt, ' '))->toThrow(InvalidArgumentException::class);
});

it('pins a Bridge certificate only on loopback and refuses plaintext-looking hosts', function (): void {
    $fingerprint = str_repeat('ab', 32);

    expect(MailServerSettings::protonBridgeDefaults($fingerprint)->isLoopback())->toBeTrue()
        ->and(fn () => new MailServerSettings('imap.example.net', 993, 'smtp.example.net', 587, bridgeCertificateFingerprint: $fingerprint))->toThrow(InvalidArgumentException::class, 'loopback')
        ->and(fn () => new MailServerSettings('127.0.0.1', 1143, '127.0.0.1', 1025, bridgeCertificateFingerprint: 'ABC'))->toThrow(InvalidArgumentException::class, 'hex')
        ->and(fn () => new MailServerSettings('imaps://imap.example.net', 993, 'smtp.example.net', 587))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new MailServerSettings('imap.example.net', 0, 'smtp.example.net', 587))->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| ApprovedOutgoingEmail / SendReceipt / SendRefusedException
|--------------------------------------------------------------------------
*/

it('requires every approval field on an approved outgoing email', function (string $field): void {
    expect(fn () => s0_17_approved_email([$field => ' ']))->toThrow(InvalidArgumentException::class, $field);
})->with(['proposalId', 'approvalId', 'approverUserId', 'connectionId', 'nonce']);

it('freezes recipients, subject and expiry on an approved outgoing email', function (): void {
    $approved = s0_17_approved_email(['cc' => [new EmailAddress('sales@example.se')]]);

    expect($approved->recipients())->toHaveCount(2)
        ->and($approved->isExpired(new DateTimeImmutable('2026-10-09T09:15:00+02:00')))->toBeTrue()
        ->and($approved->isExpired(new DateTimeImmutable('2026-10-09T09:14:59+02:00')))->toBeFalse()
        ->and(fn () => s0_17_approved_email(['to' => []]))->toThrow(InvalidArgumentException::class, 'To recipient')
        ->and(fn () => s0_17_approved_email(['subject' => "Hi\r\nBcc: evil@example.net"]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => s0_17_approved_email(['expiresAt' => new DateTimeImmutable(S0_17_APPROVED_AT)]))->toThrow(InvalidArgumentException::class, 'expire')
        ->and(fn () => s0_17_approved_email(['to' => [new EmailAddress('Karin@example.se')]]))->toThrow(InvalidArgumentException::class, 'lower-case');
});

it('binds the approved body hash to the content and the attachment manifest', function (): void {
    $pdf = new EmailAttachmentRef('att-1', 'offert-2026-114.pdf', 'application/pdf', 184000, sha256: str_repeat('0a', 32));
    $xlsx = new EmailAttachmentRef('att-2', 'kalkyl.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 2048, sha256: str_repeat('0b', 32));

    $hash = ApprovedOutgoingEmail::hashBody("Hej\r\nKarin\r\n", '<p>Hej</p>', [$pdf, $xlsx]);

    expect($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and(ApprovedOutgoingEmail::hashBody("Hej\nKarin", '<p>Hej</p>', [$xlsx, $pdf]))->toBe($hash)
        ->and(ApprovedOutgoingEmail::hashBody("Hej\nKarin", '<p>Hej</p>', [$pdf]))->not->toBe($hash)
        ->and(ApprovedOutgoingEmail::hashBody("Hej\nKarin!", '<p>Hej</p>', [$pdf, $xlsx]))->not->toBe($hash)
        ->and(ApprovedOutgoingEmail::hashBody("Hej\nKarin", null, [$pdf, $xlsx]))->not->toBe($hash)
        ->and(fn () => s0_17_approved_email(['bodyHash' => str_repeat('ff', 32)]))->toThrow(InvalidArgumentException::class, 'hash')
        ->and(fn () => s0_17_approved_email(['attachments' => [new EmailAttachmentRef('att-3', 'x.txt', 'text/plain', 1)]]))->toThrow(InvalidArgumentException::class, 'sha256')
        ->and(fn () => new EmailAttachmentRef('att-4', '../../etc/passwd', 'text/plain', 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EmailAttachmentRef('att-5', 'x.txt', 'not a mime', 1))->toThrow(InvalidArgumentException::class);

    $approved = s0_17_approved_email(['attachments' => [$pdf]]);

    expect($approved->bodyHash)->toBe(ApprovedOutgoingEmail::hashBody($approved->textBody, $approved->htmlBody, $approved->attachments));
});

it('requires a receipt to point at the sent copy and a refusal to be a policy denial', function (): void {
    $sentAt = new DateTimeImmutable('2026-10-09T09:01:00+02:00');

    $receipt = new SendReceipt('conn', 'prop', 'appr', 'nonce', str_repeat('0c', 32), $sentAt, messageIdHeader: '<01M4@example.se>');

    expect($receipt->providerMessageId)->toBeNull()
        ->and(fn () => new SendReceipt('conn', 'prop', 'appr', 'nonce', str_repeat('0c', 32), $sentAt))->toThrow(InvalidArgumentException::class, 'Message-ID')
        ->and(fn () => new SendReceipt('conn', 'prop', 'appr', 'nonce', 'nothex', $sentAt, providerMessageId: 'x'))->toThrow(InvalidArgumentException::class, 'SHA-256')
        ->and(fn () => new SendReceipt('conn', '', 'appr', 'nonce', str_repeat('0c', 32), $sentAt, providerMessageId: 'x'))->toThrow(InvalidArgumentException::class, 'proposalId');

    $refusal = new SendRefusedException('The From address is not bound to this account.', SendRefusalReason::FromNotBoundToConnection, 'conn', 'prop');

    expect($refusal)->toBeInstanceOf(PolicyDeniedException::class)
        ->and($refusal->policy)->toBe('mail.send');
});
