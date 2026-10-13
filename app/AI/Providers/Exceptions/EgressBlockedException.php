<?php

declare(strict_types=1);

namespace App\AI\Providers\Exceptions;

use App\AI\Providers\Dto\AiError;
use App\AI\Providers\Dto\AiErrorKind;

/**
 * An outbound request was refused before any socket opened (ADR-004 D4,
 * ADR-005 D4.11). Deliberately not retryable and deliberately **not** a
 * `FailoverableException`, so a blocked cloud call is never silently retried on
 * another provider (§4, threat model T-66).
 *
 * S2-13 threw this for one reason — a remote provider under `LOCAL_ONLY`. S2-14
 * made the guard shared, so it now carries the `EgressRule` that refused: a
 * refusal that cannot say *which* rule fired is one a reviewer cannot audit and
 * an owner cannot act on. The namespace is the one ADR-004 D4.3 names and is
 * kept for that reason even though `Browser/Fetch` and MCP raise it too.
 *
 * What it never carries is the full URL. A query string can hold a token, and
 * an exception is logged (checklist row 16): the host, the resolved address and
 * the rule are the three facts, and that is all.
 */
final class EgressBlockedException extends AiProviderException
{
    public function __construct(
        public readonly string $blockedHost,
        public readonly ?string $rule = null,
        ?string $reason = null,
        public readonly ?string $resolvedAddress = null,
    ) {
        parent::__construct(new AiError(
            kind: AiErrorKind::EgressBlocked,
            message: $this->describe($blockedHost, $rule, $reason),
            retryable: false,
        ));
    }

    private function describe(string $host, ?string $rule, ?string $reason): string
    {
        if ($rule === null) {
            return 'Local Only mode blocked a request to a host outside this Mac.';
        }

        $resolved = $this->resolvedAddress === null ? '' : " (resolved to {$this->resolvedAddress})";

        return "Egress to [{$host}]{$resolved} was refused by rule [{$rule}]: {$reason}.";
    }
}
