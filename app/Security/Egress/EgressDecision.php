<?php

declare(strict_types=1);

namespace App\Security\Egress;

/**
 * What the guard decided, without deciding what to do about it. Permitted
 * carries the pinned target; refused carries the rule that refused and the
 * facts the audit row needs — never the URL, whose query may carry a token.
 *
 * Split from the throwing path on purpose: `/settings/ai` asks "may this
 * provider be used?" on every page render to grey out the ones Local Only
 * disables, and a question is not an attempt. Only an attempt writes an audit
 * row, so the UI cannot flood the trail.
 */
final readonly class EgressDecision
{
    private function __construct(
        public bool $permitted,
        public ?PinnedTarget $target,
        public ?EgressRule $rule,
        public string $requestedHost,
        public ?string $resolvedAddress,
        public EgressPurpose $purpose,
    ) {}

    public static function permit(PinnedTarget $target): self
    {
        return new self(true, $target, null, $target->host, $target->address, $target->purpose);
    }

    public static function refuse(
        EgressRule $rule,
        string $requestedHost,
        ?string $resolvedAddress,
        EgressPurpose $purpose,
    ): self {
        return new self(false, null, $rule, $requestedHost, $resolvedAddress, $purpose);
    }
}
