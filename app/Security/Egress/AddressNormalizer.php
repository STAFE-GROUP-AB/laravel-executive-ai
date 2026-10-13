<?php

declare(strict_types=1);

namespace App\Security\Egress;

/**
 * Normalisation before any decision (threat model §3.4.1 rows 2, 8 and 9;
 * T-28). This class exists because a deny list matched against a *string* is
 * not a control: `127.0.0.1`, `2130706433`, `0177.0.0.1`, `0x7f.1`, `127.1`,
 * `::ffff:127.0.0.1`, `::ffff:7f00:1`, `[::1]`, `64:ff9b::7f00:1`,
 * `2002:7f00:1::` and `LOCALHOST.` are eleven spellings a resolver, curl and a
 * human all read differently, and ten of them pass a naive `str_starts_with`
 * check for `127.`.
 *
 * So every spelling is reduced here, once, to one canonical form — the textual
 * rendering of the **binary** address — and `EgressGuard` decides on that and
 * on nothing else. The reverse mistake matters as much: a host that merely
 * *looks* local (`localhost.example.com`, `127.0.0.1.nip.io`) is a name, is
 * resolved, and is judged on what it resolves to.
 *
 * Nothing here reaches the network. Resolution is `HostResolver`'s job; this is
 * pure text and bytes, which is why it can be a table test.
 */
final class AddressNormalizer
{
    /**
     * The IPv6 prefixes that wrap an IPv4 address and must be unwrapped before
     * the IPv4 deny ranges can apply (checklist row 8). Keyed by the byte
     * offset at which the embedded IPv4 address starts.
     */
    private const array EMBEDDED_IPV4 = [
        // ::ffff:0:0/96 — IPv4-mapped IPv6, the form `::ffff:127.0.0.1` writes.
        // It is the one wrapper that is unwrapped rather than refused, because a
        // socket opened to it genuinely goes to the embedded IPv4 address, so
        // judging the IPv4 address is judging what will be connected to.
        //
        // The NAT64 (`64:ff9b::/96`) and 6to4 (`2002::/16`) wrappers are *not*
        // unwrapped here. A connection to one of those goes to an IPv6 address
        // that a relay translates, so the embedded IPv4 is not what the socket
        // reaches and pinning the unwrapped address would pin the wrong thing.
        // `EgressGuard::WRAPPED_IPV4` refuses the whole prefix instead.
        '00000000000000000000ffff' => 12,
    ];

    /**
     * The host as everything downstream must see it: lowercase, de-bracketed,
     * trailing dots removed, Unicode converted to punycode once.
     *
     * Null means the authority is not a host at all — an empty string, a
     * control character, whitespace, a stray `@` or `/`, an IDN that will not
     * convert. A caller that gets null refuses with `MalformedAuthority`; it
     * never falls back to the raw string.
     */
    public function host(string $host): ?string
    {
        $host = trim($host);

        if ($host === '' || mb_strlen($host) > 253) {
            return null;
        }

        // Row 2: control characters, whitespace and the characters that let one
        // authority be read as two. A host containing them is refused, never
        // stripped down to something that passes.
        if (preg_match('/[\x00-\x20\x7f@\/\\\\?#%]/', $host) === 1) {
            return null;
        }

        if (str_starts_with($host, '[')) {
            if (! str_ends_with($host, ']')) {
                return null;
            }

            $host = substr($host, 1, -1);
        }

        $host = mb_strtolower($host);

        // A trailing dot is the root label; `127.0.0.1.` and `evil.com.` are the
        // same targets as their undotted forms and must not be a second spelling.
        $host = rtrim($host, '.');

        if ($host === '') {
            return null;
        }

        if (preg_match('/^[\x21-\x7e]+$/', $host) !== 1) {
            // No `intl` means no way to know what a Unicode host resolves to, and
            // guessing is how a homograph gets through: refuse instead.
            if (! function_exists('idn_to_ascii')) {
                return null;
            }

            $converted = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (! is_string($converted) || $converted === '') {
                return null;
            }

            $host = mb_strtolower($converted);
        }

        return $host;
    }

    /**
     * The canonical address a host denotes, in **any** spelling, or null when
     * the host is a name that has to be resolved.
     *
     * The return value is always `inet_ntop(inet_pton($address))` of the real
     * bytes, with an IPv4 address wrapped in IPv6 unwrapped to its IPv4 form —
     * so `::ffff:127.0.0.1` and `0x7f000001` both come back as `127.0.0.1` and
     * the deny ranges are written once.
     */
    public function literal(string $canonicalHost): ?string
    {
        if (str_contains($canonicalHost, ':')) {
            return $this->canonicalIpv6($canonicalHost);
        }

        return $this->ipv4From($canonicalHost);
    }

    /**
     * Whether the host is built only from numeric labels — decimal, octal or
     * hexadecimal — and is therefore an address literal attempt rather than a
     * name, even when it does not parse.
     *
     * The guard needs this to fail closed: `4294967296` and `0x1.0x2.0x3.0x4.0x5`
     * are not valid addresses and are not names either, and "does not resolve"
     * is the wrong refusal for them.
     */
    public function isNumericForm(string $canonicalHost): bool
    {
        if ($canonicalHost === '') {
            return false;
        }

        if (str_contains($canonicalHost, ':')) {
            return preg_match('/^[0-9a-f:.]+$/', $canonicalHost) === 1;
        }

        foreach (explode('.', $canonicalHost) as $part) {
            if (preg_match('/^(0x[0-9a-f]+|[0-9]+)$/', $part) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** Whether the canonical address is IPv4 rather than IPv6. */
    public function isIpv4(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Whether the canonical address falls inside the CIDR block, decided on the
     * packed bytes and the prefix length. Both sides must be the same family;
     * a mixed comparison is false rather than an error, because the guard asks
     * every range about every address.
     */
    public function inCidr(string $address, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);

        $addressBytes = @inet_pton($address);
        $networkBytes = @inet_pton($network);

        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $bits = (int) $prefix;
        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($addressBytes, $networkBytes, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }

    /**
     * IPv4 in every spelling `inet_aton()` accepts, which is the set curl and
     * the resolver accept too: one to four parts, each decimal, octal (leading
     * zero) or hexadecimal (`0x`), with the last part absorbing the bytes the
     * earlier parts did not supply (`127.1` is `127.0.0.1`).
     *
     * Deliberately hand-written. `ip2long()` rejects the short forms and
     * `filter_var()` rejects the octal and hex ones, so relying on either would
     * classify exactly the obfuscated spellings as "a hostname" and send them to
     * the resolver, which reads them as addresses — T-28's bypass in one step.
     */
    private function ipv4From(string $host): ?string
    {
        $parts = explode('.', $host);

        if (count($parts) > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            $value = $this->numericPart($part);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        $count = count($values);
        $last = $values[$count - 1];

        // Every part but the last is one byte; the last absorbs the rest.
        for ($i = 0; $i < $count - 1; $i++) {
            if ($values[$i] > 0xFF) {
                return null;
            }
        }

        $remainingBytes = 4 - ($count - 1);

        if ($last > (2 ** (8 * $remainingBytes)) - 1) {
            return null;
        }

        $packed = 0;

        for ($i = 0; $i < $count - 1; $i++) {
            $packed |= $values[$i] << (8 * (3 - $i));
        }

        $packed |= $last;

        return long2ip($packed);
    }

    /** One decimal, octal or hexadecimal part, or null when it is not numeric at all. */
    private function numericPart(string $part): ?int
    {
        if ($part === '') {
            return null;
        }

        if (preg_match('/^0x[0-9a-f]+$/', $part) === 1) {
            $value = hexdec(substr($part, 2));

            return is_int($value) && $value <= 0xFFFFFFFF ? $value : null;
        }

        if (preg_match('/^0[0-7]+$/', $part) === 1) {
            $value = octdec($part);

            return is_int($value) && $value <= 0xFFFFFFFF ? $value : null;
        }

        if (preg_match('/^(0|[1-9][0-9]*)$/', $part) !== 1) {
            return null;
        }

        // A part with a leading zero that is not valid octal (`08`, `0177.0.0.09`)
        // is ambiguous between the two readings, so it is not an address at all.
        $value = (int) $part;

        return (string) $value === $part && $value <= 0xFFFFFFFF ? $value : null;
    }

    /**
     * IPv6 reduced to its canonical form, with an embedded IPv4 address
     * unwrapped so the IPv4 deny ranges apply to it (checklist row 8). The 6to4
     * prefix `2002::/16` carries the IPv4 address in bytes 2–5 rather than at
     * the end, so it is unwrapped separately.
     */
    private function canonicalIpv6(string $host): ?string
    {
        $bytes = @inet_pton($host);

        if ($bytes === false || strlen($bytes) !== 16) {
            return null;
        }

        $hex = bin2hex($bytes);

        foreach (self::EMBEDDED_IPV4 as $prefix => $offset) {
            if (str_starts_with($hex, $prefix)) {
                return long2ip((int) hexdec(substr($hex, $offset * 2, 8)));
            }
        }

        $canonical = inet_ntop($bytes);

        return $canonical === false ? null : $canonical;
    }
}
