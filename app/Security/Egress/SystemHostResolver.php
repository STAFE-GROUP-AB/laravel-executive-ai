<?php

declare(strict_types=1);

namespace App\Security\Egress;

/**
 * The resolver the product actually uses, with two properties that matter more
 * than which syscall it picks.
 *
 * **One answer per host per process.** Every lookup is memoised, so the address
 * the guard judged is the address the connection is pinned to even if the guard
 * is asked twice. A resolver that answered differently on the second lookup is
 * T-25 — the DNS-rebinding window — and the memo closes it on our side; the
 * pin in `PinnedTarget` closes it on curl's.
 *
 * **`localhost` is not a DNS question.** RFC 6761 §6.3 reserves the name and
 * says resolvers "SHOULD" answer it from the loopback addresses without
 * consulting DNS. We do not make it a SHOULD: `LoopbackHostResolver` answers it
 * from a constant before this class asks anything, so a hostile DNS answer for
 * the one name our own local model endpoints are configured under cannot exist.
 */
final class SystemHostResolver implements HostResolver
{
    /** @var array<string, list<string>> */
    private array $answers = [];

    private readonly LoopbackHostResolver $reserved;

    public function __construct(?LoopbackHostResolver $reserved = null)
    {
        $this->reserved = $reserved ?? new LoopbackHostResolver;
    }

    /** @return list<string> */
    public function resolve(string $canonicalHost): array
    {
        if (array_key_exists($canonicalHost, $this->answers)) {
            return $this->answers[$canonicalHost];
        }

        return $this->answers[$canonicalHost] = $this->lookUp($canonicalHost);
    }

    /** @return list<string> */
    private function lookUp(string $canonicalHost): array
    {
        if ($this->reserved->isReservedLoopbackName($canonicalHost)) {
            return $this->reserved->resolve($canonicalHost);
        }

        $addresses = [];

        $ipv4 = gethostbynamel($canonicalHost);

        if (is_array($ipv4)) {
            foreach ($ipv4 as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $address;
                }
            }
        }

        foreach ($this->aaaa($canonicalHost) as $address) {
            $addresses[] = $address;
        }

        return array_values(array_unique($addresses));
    }

    /**
     * The AAAA half. `dns_get_record()` is the only portable way to ask for it,
     * and a failure is silence rather than an exception: a host with no AAAA
     * record is normal, and a host with **only** an AAAA record must still be
     * evaluated, which is why the IPv4 answer alone is never enough.
     *
     * @return list<string>
     */
    private function aaaa(string $canonicalHost): array
    {
        $records = @dns_get_record($canonicalHost, DNS_AAAA);

        if (! is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ipv6'] ?? null;

            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
