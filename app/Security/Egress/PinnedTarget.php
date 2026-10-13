<?php

declare(strict_types=1);

namespace App\Security\Egress;

/**
 * A target the guard permitted, together with the address the connection must
 * actually go to.
 *
 * This object is the whole point of the item. Checking a URL and then handing
 * the *name* to the HTTP client resolves twice, and the second answer is the
 * one that gets connected to — threat T-25, DNS rebinding, and the reason the
 * guard is a separate piece of work from the Local Only host allowlist.
 *
 * `requestOptions()` is therefore not a convenience. It carries
 * `CURLOPT_RESOLVE`, which pre-seeds curl's own DNS cache for exactly this
 * `host:port` with exactly the address the guard judged, so the socket goes to
 * the checked address while the `Host` header and the TLS SNI keep the name the
 * certificate is issued for. The decision and the connection become the same
 * fact instead of two facts that usually agree.
 */
final readonly class PinnedTarget
{
    /**
     * @param  string  $host  the canonical host, as it must appear in `Host` and SNI
     * @param  string  $address  the canonical address the socket must connect to
     */
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $address,
        public EgressPurpose $purpose,
    ) {}

    /**
     * `host:port:address` — curl's pre-resolve syntax. An IPv6 address is
     * bracketed, which is what curl expects in this option.
     */
    public function resolveEntry(): string
    {
        $address = str_contains($this->address, ':') ? "[{$this->address}]" : $this->address;

        return "{$this->host}:{$this->port}:{$address}";
    }

    /**
     * The Guzzle options that make the pin real at the socket.
     *
     * `allow_redirects` defaults to false here: ADR-005 D4.2(c) makes any 3xx an
     * error for MCP and OAuth discovery, and model traffic has no reason to
     * follow one. A caller that genuinely needs to follow redirects passes its
     * own `allow_redirects`; `EgressGuard`'s middleware then re-runs the whole
     * checklist and re-pins on **every** hop, because it sits inside Guzzle's
     * redirect middleware rather than outside it.
     *
     * @return array{curl: array<int, mixed>, allow_redirects: false}
     */
    public function requestOptions(): array
    {
        return [
            'curl' => [CURLOPT_RESOLVE => [$this->resolveEntry()]],
            'allow_redirects' => false,
        ];
    }

    /** `scheme://host:port` — never the path or the query, which may carry a token. */
    public function origin(): string
    {
        return "{$this->scheme}://{$this->host}:{$this->port}";
    }
}
