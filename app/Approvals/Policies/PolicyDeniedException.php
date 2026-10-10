<?php

declare(strict_types=1);

namespace App\Approvals\Policies;

use RuntimeException;

/**
 * Base failure for every server-side policy refusal (blueprint §4: the LLM proposes,
 * a server-side PolicyGate validates tool name, schema, org/account/scope, data
 * class, destination, budget and approval before anything executes; §11).
 *
 * Module-specific refusals extend this class so a single catch at the agent loop
 * can turn any denial into a tool result the model may read without executing.
 * The message is user-facing and must never echo external text verbatim.
 */
class PolicyDeniedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $policy,
    ) {
        parent::__construct($message);
    }
}
