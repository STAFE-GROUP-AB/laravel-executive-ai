<?php

declare(strict_types=1);

namespace Tests\Security\Support;

use App\Security\Egress\HostResolver;
use App\Security\Egress\LoopbackHostResolver;

/**
 * A resolver with a script instead of a network, so the SSRF table test says
 * what every name answers and no row depends on the machine it runs on.
 *
 * `rebinding()` is the one that matters: it hands out a **different** answer on
 * the second lookup for the same name, which is threat **T-25** exactly — the
 * name resolves to a public address for the check and to loopback for the
 * connection. A guard that resolves twice passes its own check and then
 * connects somewhere else; this resolver is how that is proven not to happen.
 */
final class FakeHostResolver implements HostResolver
{
    /** @var array<string, list<string>> */
    private array $answers;

    /** @var array<string, list<list<string>>> */
    private array $sequences = [];

    /** @var array<string, int> */
    private array $lookups = [];

    /** @param array<string, list<string>> $answers */
    public function __construct(array $answers = [])
    {
        $this->answers = $answers;
    }

    /** @param list<string> $addresses */
    public function answer(string $host, array $addresses): self
    {
        $this->answers[$host] = $addresses;

        return $this;
    }

    /**
     * A name whose answer changes between lookups. The first list is the first
     * lookup's answer, the second list every lookup after it.
     *
     * @param  list<string>  $first
     * @param  list<string>  $then
     */
    public function rebinding(string $host, array $first, array $then): self
    {
        $this->sequences[$host] = [$first, $then];

        return $this;
    }

    /** @return list<string> */
    public function resolve(string $canonicalHost): array
    {
        $this->lookups[$canonicalHost] = ($this->lookups[$canonicalHost] ?? 0) + 1;

        // RFC 6761's reserved loopback names answer from a constant in the
        // product, so they answer from a constant here too — a fake that made
        // `localhost` scriptable would let a test assert behaviour the product
        // cannot have. A row may still override one deliberately.
        $reserved = new LoopbackHostResolver;

        if ($reserved->isReservedLoopbackName($canonicalHost) && ! isset($this->answers[$canonicalHost], $this->sequences[$canonicalHost])) {
            return $reserved->resolve($canonicalHost);
        }

        if (isset($this->sequences[$canonicalHost])) {
            [$first, $then] = $this->sequences[$canonicalHost];

            return $this->lookups[$canonicalHost] === 1 ? $first : $then;
        }

        return $this->answers[$canonicalHost] ?? [];
    }

    /** How many times a name was looked up — "resolve once" is an assertion, not a hope. */
    public function lookupsFor(string $canonicalHost): int
    {
        return $this->lookups[$canonicalHost] ?? 0;
    }
}
