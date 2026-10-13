<?php

declare(strict_types=1);

namespace App\Security\Egress;

/**
 * The rules that can refuse an outbound request, one per row of the threat
 * model's §3.4.1 SSRF control checklist (threats T-23 – T-29), plus the
 * fail-closed row that catches anything the guard cannot classify.
 *
 * A refusal always names one of these. "Blocked" with no rule is not a refusal
 * an owner can act on, and it is not a refusal a reviewer can audit: the rule
 * is what says whether the guard did the right thing for the right reason
 * (checklist row 16, ADR-005 D4.11).
 */
enum EgressRule: string
{
    /** Row 1: the scheme is not one this purpose accepts. */
    case SchemeNotAllowed = 'scheme_not_allowed';

    /** Row 2: a userinfo component, a control character, or an unparseable authority. */
    case MalformedAuthority = 'malformed_authority';

    /** Row 3: `127.0.0.0/8`, `::1`, `0.0.0.0/8`, or a name that resolves into them. */
    case Loopback = 'loopback';

    /** Row 4: `10/8`, `172.16/12`, `192.168/16`. */
    case PrivateNetwork = 'private_network';

    /** Row 4: `100.64.0.0/10` shared address space — also the tailnet range. */
    case SharedAddressSpace = 'shared_address_space';

    /** Row 5: `169.254.0.0/16` and `fe80::/10`. */
    case LinkLocal = 'link_local';

    /** Row 6: `fc00::/7` unique-local IPv6. */
    case UniqueLocal = 'unique_local';

    /** Row 7: a named or addressed cloud metadata endpoint. */
    case CloudMetadata = 'cloud_metadata';

    /** Rows 8 and 9: the textual form hid a denied binary address. */
    case ObfuscatedAddress = 'obfuscated_address';

    /** Row 10: a Unix socket, a named pipe, or any non-TCP transport. */
    case NonTcpTransport = 'non_tcp_transport';

    /** Row 11: the port is not one this purpose accepts, or it is our own. */
    case PortNotAllowed = 'port_not_allowed';

    /** Row 12: the host's records disagree — one public, one private. */
    case ResolutionDisagrees = 'resolution_disagrees';

    /** Row 12: nothing resolved, so there is no address to judge. */
    case Unresolvable = 'unresolvable';

    /** Row 12: the address cannot be pinned for the connection, so the check could be bypassed. */
    case Unpinnable = 'unpinnable';

    /** Row 13: a hop of a redirect chain was refused, or the chain was too long. */
    case RedirectRefused = 'redirect_refused';

    /** Row 15: `LOCAL_ONLY` permits only the loopback model endpoints. */
    case LocalOnly = 'local_only';

    /** Row 15: `LOCAL_ONLY_ALLOW_LAN` widens the boundary this far and no further. */
    case LanNotAllowed = 'lan_not_allowed';

    /** Anything the guard could not classify. A reserved, documented, benchmarked address is still refused. */
    case Unclassifiable = 'unclassifiable';

    /**
     * The sentence the refusal carries. It names the rule and never the URL:
     * a full URL may carry a token in its query (checklist row 16).
     */
    public function reason(): string
    {
        return match ($this) {
            self::SchemeNotAllowed => 'the URL scheme is not permitted for this kind of request',
            self::MalformedAuthority => 'the URL authority carries userinfo, a control character or more than one "@"',
            self::Loopback => 'the address is loopback',
            self::PrivateNetwork => 'the address is in a private network range',
            self::SharedAddressSpace => 'the address is in the shared address space 100.64.0.0/10',
            self::LinkLocal => 'the address is link-local',
            self::UniqueLocal => 'the address is unique-local IPv6',
            self::CloudMetadata => 'the target is a cloud metadata endpoint',
            self::ObfuscatedAddress => 'the host spelling hides an address in a denied range',
            self::NonTcpTransport => 'the target is a Unix socket, a named pipe or another non-TCP transport',
            self::PortNotAllowed => 'the port is not permitted for this kind of request',
            self::ResolutionDisagrees => 'the host resolves to both permitted and denied addresses',
            self::Unresolvable => 'the host does not resolve to any address',
            self::Unpinnable => 'the resolved address cannot be pinned for the connection',
            self::RedirectRefused => 'a redirect pointed at a target this rule set refuses',
            self::LocalOnly => 'Local Only permits only the loopback model endpoints on this Mac',
            self::LanNotAllowed => 'Local Only with the LAN opt-in permits private and link-local addresses only',
            self::Unclassifiable => 'the address could not be classified, so it is refused',
        };
    }
}
