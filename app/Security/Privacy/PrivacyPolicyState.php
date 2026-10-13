<?php

declare(strict_types=1);

namespace App\Security\Privacy;

use App\AI\Routing\PrivacyMode;
use Carbon\CarbonImmutable;

/**
 * The live privacy policy: which mode is in force, whether the Local Only LAN
 * opt-in is on, and the attribution that makes a change visible after the fact
 * (`privacy_policies`, S2-03; OPEN-QUESTIONS **A18**).
 *
 * `allowLan` is true only in `LOCAL_ONLY` — the store refuses to record it
 * otherwise, because the toggle widens the Local Only boundary and has nothing
 * to widen in the other two modes (`PrivacyMode::supportsLanToggle()`).
 */
final readonly class PrivacyPolicyState
{
    /** `source` for the row the installer writes before any owner exists. */
    public const string SOURCE_INSTALL_DEFAULT = 'install_default';

    /** `source` for a change an owner made in the `/admin` security-rules pane (S2-19). */
    public const string SOURCE_OWNER = 'owner';

    /** `source` for the fallback state used when no row has been written yet. */
    public const string SOURCE_FALLBACK = 'fallback';

    public function __construct(
        public PrivacyMode $mode,
        public bool $allowLan = false,
        public string $source = self::SOURCE_FALLBACK,
        public ?string $policyId = null,
        public ?string $changedByUserId = null,
        public ?string $auditEventId = null,
        public ?CarbonImmutable $reauthenticatedAt = null,
        public ?CarbonImmutable $effectiveFrom = null,
    ) {}

    /**
     * The state a build falls back to when nothing has been stored yet: the
     * strictest mode, no LAN. Not "whatever the config says" — a privacy
     * setting nobody has recorded must not widen what leaves the Mac (§4).
     */
    public static function strictest(): self
    {
        return new self(PrivacyMode::LocalOnly);
    }

    /** The mode as `lea.privacy_mode`, `/health/ready` and the brief footer spell it. */
    public function configValue(): string
    {
        return $this->mode->configValue();
    }
}
