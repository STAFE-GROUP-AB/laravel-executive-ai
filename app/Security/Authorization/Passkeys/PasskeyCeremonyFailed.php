<?php

declare(strict_types=1);

namespace App\Security\Authorization\Passkeys;

use RuntimeException;

/**
 * A registration that did not verify. `reason` is one of a fixed set of
 * codes the page translates; the message never contains the credential, the
 * challenge or the client data.
 */
final class PasskeyCeremonyFailed extends RuntimeException
{
    public const string NO_PENDING_CEREMONY = 'no_pending_ceremony';

    public const string CEREMONY_EXPIRED = 'ceremony_expired';

    public const string ORIGIN_MISMATCH = 'origin_mismatch';

    public const string MALFORMED_CREDENTIAL = 'malformed_credential';

    public const string VERIFICATION_FAILED = 'verification_failed';

    public const string ALREADY_REGISTERED = 'already_registered';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Passkey ceremony failed ({$reason}).");
    }
}
