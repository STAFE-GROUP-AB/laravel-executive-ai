<?php

declare(strict_types=1);

namespace App\AI\Routing;

/**
 * Privacy mode of an AI run (blueprint §4; ADR-004 D4 and D5). The modes compose as a
 * conjunction when both restrictions apply; `Custom` means the user accepted the
 * provider's own terms with no additional routing restriction.
 */
enum PrivacyMode: string
{
    case LocalOnly = 'local_only';
    case EuRestricted = 'eu_restricted';
    case Custom = 'custom';

    /**
     * The mode as `lea.privacy_mode` and the health contract spell it
     * (`LOCAL_ONLY`, `EU_RESTRICTED`, `CUSTOM`).
     */
    public function configValue(): string
    {
        return strtoupper($this->value);
    }

    /**
     * Read a configured mode. Anything unrecognised — a typo, a missing value, a
     * mode from a newer build — resolves to `LocalOnly`, the strictest of the
     * three: a privacy setting nobody can parse must not widen what leaves the
     * Mac (§4 "Local Only-läge är strikt").
     */
    public static function fromConfigValue(mixed $value): self
    {
        return is_string($value)
            ? self::tryFrom(strtolower(trim($value))) ?? self::LocalOnly
            : self::LocalOnly;
    }

    /**
     * Whether `LOCAL_ONLY_ALLOW_LAN` is meaningful in this mode. The toggle widens
     * the Local Only egress boundary to RFC 1918 and link-local hosts and nothing
     * else (ADR-004 D4.2, residual risk RR-18); in the other two modes it has
     * nothing to widen, so `privacy_policies.allow_lan` is only ever true here
     * (S2-03, OPEN-QUESTIONS A18).
     */
    public function supportsLanToggle(): bool
    {
        return $this === self::LocalOnly;
    }

    /**
     * Whether moving from `$from` to this mode widens where model traffic may go —
     * the change threat model **T-68** calls a downgrade, and the one S2-14 (#96)
     * gates behind the S2-05 re-authentication and a UI re-confirmation.
     */
    public function wouldWiden(self $from): bool
    {
        return $this->breadth() > $from->breadth();
    }

    private function breadth(): int
    {
        return match ($this) {
            self::LocalOnly => 0,
            self::EuRestricted => 1,
            self::Custom => 2,
        };
    }
}
