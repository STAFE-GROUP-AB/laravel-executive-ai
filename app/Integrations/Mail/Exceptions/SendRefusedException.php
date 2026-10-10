<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Exceptions;

use App\Approvals\Policies\PolicyDeniedException;

/**
 * The connector refused to send before anything left the machine (blueprint §5.6
 * criterion 8, §11 "fail closed"): the approval names another connection, the From
 * address is not bound to the connection, the approval has expired, the body hash
 * recomputed from the content about to be sent does not match the approved hash,
 * or the connection lacks the `send` capability.
 *
 * Extends `PolicyDeniedException` so the agent loop's single catch turns it into a
 * tool result the model can read; `reason` is a stable category for the audit event.
 */
final class SendRefusedException extends PolicyDeniedException
{
    public function __construct(
        string $message,
        public readonly SendRefusalReason $reason,
        public readonly string $connectionId,
        public readonly string $proposalId,
    ) {
        parent::__construct($message, 'mail.send');
    }
}
