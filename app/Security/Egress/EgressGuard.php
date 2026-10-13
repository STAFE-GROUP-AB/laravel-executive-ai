<?php

declare(strict_types=1);

namespace App\Security\Egress;

use App\AI\Providers\Exceptions\EgressBlockedException;
use App\AI\Routing\PrivacyMode;

/**
 * The one shared SSRF and egress guard (OPEN-QUESTIONS **A15**, closed by this
 * class; threat model §3.4.1 rows 1–16, threats T-23 – T-29; ADR-004 D4.2,
 * ADR-005 D4.2(b) and D4.11).
 *
 * Four properties are the whole design, and each exists because the obvious
 * cheaper version is a hole:
 *
 * 1. **Normalise, then decide.** Every spelling of an address is reduced by
 *    `AddressNormalizer` to the textual form of its binary value, and the deny
 *    ranges are tested against that. A rule written against the string would
 *    pass `2130706433`, `0177.0.0.1`, `0x7f.1`, `127.1`, `::ffff:127.0.0.1` and
 *    `64:ff9b::7f00:1` — six spellings of the address it refuses (T-28).
 * 2. **Resolve once, then connect to that address.** The guard resolves a name
 *    through `HostResolver`, judges **every** A and AAAA record, and returns a
 *    `PinnedTarget` whose `requestOptions()` pre-seed curl's resolver cache with
 *    the judged address. The connection goes to the address that was checked,
 *    not to a second answer for the same name (T-25).
 * 3. **Every private range, not just loopback.** `10/8`, `172.16/12`,
 *    `192.168/16`, `100.64/10`, `169.254/16`, `127/8`, `0/8`, `::1`, `fc00::/7`,
 *    `fe80::/10`, the five named cloud-metadata endpoints, and the reserved,
 *    documentation and benchmarking ranges that are none of the above.
 * 4. **Fail closed.** An address that matches no classification at all is
 *    refused with `Unclassifiable`, and the refusal names the rule. There is no
 *    branch in this class that permits a target because nothing matched it.
 *
 * What the guard is *not*: it does not decide whether a provider carries the
 * ADR-004 D5 evidence `EU_RESTRICTED` needs — `RegionEvidence::missingFor()` is
 * the single implementation of that rule and `ProviderEligibility` composes the
 * two. A hostname never implies a region here or anywhere (D5).
 */
final class EgressGuard
{
    /** Row 3. `0.0.0.0/8` is "this host" and reaches loopback on every OS we ship for. */
    public const array LOOPBACK = ['127.0.0.0/8', '::1/128', '0.0.0.0/8'];

    /** Row 4, first three: the RFC 1918 ranges, and the whole of what the LAN opt-in widens to. */
    public const array PRIVATE_V4 = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /** Row 4: shared address space. Also the tailnet range, which is why the LAN opt-in never includes it. */
    public const array SHARED = ['100.64.0.0/10'];

    /** Row 5. */
    public const array LINK_LOCAL = ['169.254.0.0/16', 'fe80::/10'];

    /** Row 6. */
    public const array UNIQUE_LOCAL = ['fc00::/7'];

    /**
     * Row 7, by address. The address check is the control; the name check below
     * is the convenience that makes the refusal legible before resolution.
     */
    public const array METADATA_ADDRESSES = ['169.254.169.254', '169.254.170.2', 'fd00:ec2::254', '100.100.100.200'];

    /** Row 7, by name. `metadata.google.internal` is the fifth named endpoint. */
    public const array METADATA_HOSTS = ['metadata.google.internal', 'metadata.goog', 'instance-data'];

    /**
     * Reserved, documentation, benchmarking, multicast and broadcast space.
     * None of it is a legitimate target, and leaving it out would mean the guard
     * classified it as "public" — the fail-open shape this class refuses to have.
     */
    public const array RESERVED = [
        '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '100::/64', '2001:db8::/32', '2001::/32', 'ff00::/8',
    ];

    /**
     * Row 8's wrappers. A connection to one of these reaches an IPv6 address a
     * relay translates, so the embedded IPv4 is not what the socket reaches and
     * the pin could not describe it. They are refused whole rather than
     * unwrapped and judged — `64:ff9b::7f00:1` is never a legitimate way to say
     * `127.0.0.1`, and `::0.0.0.1` is never a legitimate way to say anything.
     */
    public const array WRAPPED_IPV4 = ['64:ff9b::/96', '2002::/16', '::/96'];

    /**
     * Row 10 and row 1. A scheme that is not TCP-to-a-host is refused as a
     * transport, not as a scheme, because the refusal is about the socket:
     * `unix:`, `http+unix:` and a named pipe are the shapes that turn an HTTP
     * client into a bridge to our own privileged sockets (T-27).
     */
    public const array NON_TCP_SCHEMES = ['unix', 'http+unix', 'https+unix', 'npipe', 'file', 'php', 'zip', 'glob', 'data', 'phar'];

    private readonly AddressNormalizer $normalizer;

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly EgressDenialRecorder $recorder = new NullEgressDenialRecorder,
        ?AddressNormalizer $normalizer = null,
        private readonly ?int $ownCorePort = null,
    ) {
        $this->normalizer = $normalizer ?? new AddressNormalizer;
    }

    /**
     * Permit the request and pin its address, or refuse it: the call every
     * outbound path makes. A refusal is recorded (checklist row 16) and thrown
     * as `EgressBlockedException`, which ADR-004 D4.4 deliberately keeps out of
     * the SDK's failover set so a blocked cloud call is never retried elsewhere.
     *
     * @throws EgressBlockedException
     */
    public function authorize(string $url, EgressPurpose $purpose, PrivacyMode $mode, bool $allowLan = false): PinnedTarget
    {
        $decision = $this->decide($url, $purpose, $mode, $allowLan);

        if ($decision->target !== null) {
            return $decision->target;
        }

        $rule = $decision->rule ?? EgressRule::Unclassifiable;

        $this->recorder->denied($rule, $decision->requestedHost, $decision->resolvedAddress, $purpose);

        throw new EgressBlockedException(
            blockedHost: $decision->requestedHost,
            rule: $rule->value,
            reason: $rule->reason(),
            resolvedAddress: $decision->resolvedAddress,
        );
    }

    /**
     * The same decision without recording it and without throwing: for the
     * surfaces that ask in order to explain, not in order to connect.
     */
    public function refusalFor(string $url, EgressPurpose $purpose, PrivacyMode $mode, bool $allowLan = false): ?EgressRule
    {
        return $this->decide($url, $purpose, $mode, $allowLan)->rule;
    }

    public function permits(string $url, EgressPurpose $purpose, PrivacyMode $mode, bool $allowLan = false): bool
    {
        return $this->decide($url, $purpose, $mode, $allowLan)->permitted;
    }

    /**
     * Checklist row 13: the same decision for a redirect hop. There is no
     * separate, gentler path for a hop — a chain is refused at the hop that
     * fails, which is the only way a first URL that passes cannot be used to
     * reach one that would not.
     */
    public function authorizeRedirect(string $url, EgressPurpose $purpose, PrivacyMode $mode, bool $allowLan = false): PinnedTarget
    {
        $decision = $this->decide($url, $purpose, $mode, $allowLan);

        if ($decision->target !== null) {
            return $decision->target;
        }

        $this->recorder->denied(EgressRule::RedirectRefused, $decision->requestedHost, $decision->resolvedAddress, $purpose);

        throw new EgressBlockedException(
            blockedHost: $decision->requestedHost,
            rule: EgressRule::RedirectRefused->value,
            reason: EgressRule::RedirectRefused->reason().' ('.($decision->rule ?? EgressRule::Unclassifiable)->value.')',
            resolvedAddress: $decision->resolvedAddress,
        );
    }

    /** The whole checklist, in one pass, with no side effects. */
    public function decide(string $url, EgressPurpose $purpose, PrivacyMode $mode, bool $allowLan = false): EgressDecision
    {
        $url = trim($url);
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        // A named pipe has no scheme at all, and neither does a bare path that
        // someone hoped the client would resolve relative to a socket.
        if ($scheme === '' || str_starts_with($url, '\\\\') || str_starts_with($url, '/')) {
            return EgressDecision::refuse(EgressRule::NonTcpTransport, $this->shortenForAudit($url), null, $purpose);
        }

        if (in_array($scheme, self::NON_TCP_SCHEMES, true)) {
            return EgressDecision::refuse(EgressRule::NonTcpTransport, $scheme.':', null, $purpose);
        }

        if (! in_array($scheme, $purpose->schemes(), true)) {
            return EgressDecision::refuse(EgressRule::SchemeNotAllowed, $scheme.':', null, $purpose);
        }

        $authority = $this->authorityOf($url);

        // Row 2: a userinfo component makes `http://allowed@127.0.0.1/` read as
        // "allowed" to a human and as `127.0.0.1` to the client, and more than
        // one `@` makes even the parsers disagree. Both are refused, never
        // stripped: stripping would silently connect to the second reading.
        //
        // The check is on the authority **as written**, before `parse_url()`
        // sees it, because `parse_url()` rewrites a control character to `_`
        // rather than failing — so a host carrying a newline would arrive here
        // looking like an ordinary name and be refused for the wrong reason.
        // Bytes above `0x7f` are allowed through: they are a Unicode host, and
        // `AddressNormalizer` converts it to punycode so both spellings reach
        // the same decision. Everything else outside the authority's own
        // character set — including `%`, so a percent-encoded host cannot be a
        // second spelling — is refused here.
        if ($authority === null || str_contains($authority, '@') || preg_match('/[^A-Za-z0-9\-._~:\[\]\x80-\xff]/', $authority) === 1) {
            return EgressDecision::refuse(EgressRule::MalformedAuthority, $scheme.':', null, $purpose);
        }

        $rawHost = parse_url($url, PHP_URL_HOST);
        $host = is_string($rawHost) ? $this->normalizer->host($rawHost) : null;

        if ($host === null) {
            return EgressDecision::refuse(EgressRule::MalformedAuthority, $this->shortenForAudit((string) $rawHost), null, $purpose);
        }

        // `parse_url()` yields a port only when it is a valid one, so an
        // out-of-range port is already a parse failure rather than a port rule.
        $port = parse_url($url, PHP_URL_PORT);
        $port = is_int($port) ? $port : ($scheme === 'https' ? 443 : 80);

        // Row 15: Local Only does not narrow `Browser/Fetch` and registry
        // discovery, it switches them off. There is no target to allowlist.
        if ($mode === PrivacyMode::LocalOnly && ! $purpose->permittedUnderLocalOnly()) {
            return EgressDecision::refuse(EgressRule::LocalOnly, $host, null, $purpose);
        }

        if (in_array($host, self::METADATA_HOSTS, true) || str_ends_with($host, '.internal')) {
            return EgressDecision::refuse(EgressRule::CloudMetadata, $host, null, $purpose);
        }

        $literal = $this->normalizer->literal($host);

        if ($literal === null && $this->normalizer->isNumericForm($host)) {
            // All-numeric and not a parseable address: not a name either, so
            // "does not resolve" would be the wrong answer. Fail closed.
            return EgressDecision::refuse(EgressRule::ObfuscatedAddress, $host, null, $purpose);
        }

        $addresses = $literal !== null ? [$literal] : $this->resolveAll($host);

        if ($addresses === null) {
            return EgressDecision::refuse(EgressRule::Unclassifiable, $host, null, $purpose);
        }

        if ($addresses === []) {
            return EgressDecision::refuse(EgressRule::Unresolvable, $host, null, $purpose);
        }

        $refusals = [];
        $permitted = [];

        foreach ($addresses as $address) {
            $rule = $this->classify($address, $port, $purpose, $mode, $allowLan);

            $rule === null ? $permitted[] = $address : $refusals[$address] = $rule;
        }

        if ($refusals !== [] && $permitted !== []) {
            // Row 12: a host answering with one public and one private address is
            // refused outright. Picking the permitted record would make the
            // attacker's second record a retry away.
            $address = array_key_first($refusals);

            return EgressDecision::refuse(EgressRule::ResolutionDisagrees, $host, (string) $address, $purpose);
        }

        if ($permitted === []) {
            $address = array_key_first($refusals);

            return EgressDecision::refuse($refusals[$address], $host, (string) $address, $purpose);
        }

        $address = $permitted[0];

        // Row 12 again, from the other side: a name has to be pinned for the
        // connection or the check is advisory. Without curl the Laravel client
        // falls back to a stream handler that re-resolves, so a *name* is
        // refused there; a literal has nothing to re-resolve and is fine.
        if ($literal === null && ! extension_loaded('curl')) {
            return EgressDecision::refuse(EgressRule::Unpinnable, $host, $address, $purpose);
        }

        return EgressDecision::permit(new PinnedTarget(
            scheme: $scheme,
            host: $host,
            port: $port,
            address: $address,
            purpose: $purpose,
        ));
    }

    /**
     * Which rule refuses this address, or null when it is permitted. Every
     * branch ends in a rule; the last one is `Unclassifiable`, so an address
     * this method does not recognise is refused rather than allowed.
     */
    private function classify(string $address, int $port, EgressPurpose $purpose, PrivacyMode $mode, bool $allowLan): ?EgressRule
    {
        if (in_array($address, self::METADATA_ADDRESSES, true)) {
            return EgressRule::CloudMetadata;
        }

        if ($this->inAny($address, self::LOOPBACK)) {
            return $this->classifyLoopback($port, $purpose);
        }

        if ($this->inAny($address, self::RESERVED)) {
            return EgressRule::Unclassifiable;
        }

        if ($this->inAny($address, self::WRAPPED_IPV4)) {
            return EgressRule::ObfuscatedAddress;
        }

        if ($this->inAny($address, self::SHARED)) {
            return EgressRule::SharedAddressSpace;
        }

        if ($this->inAny($address, self::PRIVATE_V4)) {
            return $this->classifyLan($purpose, $mode, $allowLan, EgressRule::PrivateNetwork);
        }

        if ($this->inAny($address, self::LINK_LOCAL)) {
            return $this->classifyLan($purpose, $mode, $allowLan, EgressRule::LinkLocal);
        }

        if ($this->inAny($address, self::UNIQUE_LOCAL)) {
            // Deliberately not widened by the LAN opt-in: ADR-004 D4.2 names
            // RFC 1918 and link-local, and a toggle must widen exactly what its
            // warning says it widens.
            return EgressRule::UniqueLocal;
        }

        if (! $this->isGlobalUnicast($address)) {
            return EgressRule::Unclassifiable;
        }

        if ($mode === PrivacyMode::LocalOnly && $purpose->restrictedByLocalOnly()) {
            return EgressRule::LocalOnly;
        }

        // A local runtime is local by definition, in every mode. Without this the
        // purpose would be the loosest in the table outside Local Only — loopback
        // permitted *and* no public port rule — which its name promises it is not.
        if (! $purpose->allowsPublic()) {
            return EgressRule::LocalOnly;
        }

        $ports = $purpose->publicPorts();

        if ($ports !== [] && ! in_array($port, $ports, true)) {
            return EgressRule::PortNotAllowed;
        }

        return null;
    }

    /** Loopback is legitimate for exactly three purposes, and never on our own core port but one. */
    private function classifyLoopback(int $port, EgressPurpose $purpose): ?EgressRule
    {
        if (! $purpose->allowsLoopback()) {
            return EgressRule::Loopback;
        }

        if ($port === $this->corePort() && ! $purpose->allowsOwnCorePort()) {
            return EgressRule::PortNotAllowed;
        }

        return null;
    }

    /**
     * RFC 1918 and link-local are permitted only behind `LOCAL_ONLY_ALLOW_LAN`,
     * and only for a purpose that may talk to a local runtime at all. The toggle
     * is storable as true only in `LOCAL_ONLY` (`PrivacyMode::supportsLanToggle()`,
     * enforced by `PrivacyModeStore`), so this one branch is the whole of what
     * RR-18 widens.
     */
    private function classifyLan(EgressPurpose $purpose, PrivacyMode $mode, bool $allowLan, EgressRule $range): ?EgressRule
    {
        if (! $purpose->allowsLoopback()) {
            return $range;
        }

        if ($allowLan) {
            return null;
        }

        // In Local Only the actionable refusal names the toggle that would admit
        // this address and the warning it carries; in the other modes no such
        // toggle exists, so naming it would be a false suggestion.
        return $mode === PrivacyMode::LocalOnly ? EgressRule::LanNotAllowed : $range;
    }

    /** @param  list<string>  $ranges */
    private function inAny(string $address, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->normalizer->inCidr($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Global unicast, positively: IPv4 outside the reserved first octets, or
     * IPv6 inside `2000::/3`. Written as an allowlist so a family or a range
     * nobody thought about lands in `Unclassifiable` instead of in "public".
     */
    private function isGlobalUnicast(string $address): bool
    {
        if ($this->normalizer->isIpv4($address)) {
            $firstOctet = (int) explode('.', $address)[0];

            return $firstOctet >= 1 && $firstOctet <= 223;
        }

        return $this->normalizer->inCidr($address, '2000::/3');
    }

    /**
     * Every record, normalised. Null means a record came back that the
     * normaliser could not reduce, which is a refusal and not an address to
     * skip past.
     *
     * @return ?list<string>
     */
    private function resolveAll(string $host): ?array
    {
        $addresses = [];

        foreach ($this->resolver->resolve($host) as $answer) {
            $canonical = $this->normalizer->literal(mb_strtolower(trim($answer)));

            if ($canonical === null) {
                return null;
            }

            $addresses[] = $canonical;
        }

        return array_values(array_unique($addresses));
    }

    /** The authority as written, so the userinfo trap is tested on the raw text. */
    private function authorityOf(string $url): ?string
    {
        $afterScheme = strstr($url, '://');

        if ($afterScheme === false) {
            return null;
        }

        $rest = substr($afterScheme, 3);
        $end = strcspn($rest, '/?#');

        return substr($rest, 0, $end);
    }

    private function corePort(): int
    {
        return $this->ownCorePort ?? (int) config('ipc.port', 8787);
    }

    /**
     * What a refusal may say about an unparseable target: the first characters
     * only, and never the query. A full URL can carry a token (checklist row 16).
     */
    private function shortenForAudit(string $value): string
    {
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $value) ?? '', 0, 40);
    }
}
