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
}
