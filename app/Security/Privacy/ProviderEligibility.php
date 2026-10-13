<?php

declare(strict_types=1);

namespace App\Security\Privacy;

use App\AI\Providers\ProviderConnection;
use App\AI\Providers\RegionEvidence;
use App\AI\Routing\PrivacyMode;
use App\Security\Egress\EgressGuard;
use App\Security\Egress\EgressPurpose;
use App\Security\Egress\EgressRule;
use DateTimeImmutable;

/**
 * "May this configured provider be used under this policy?" — asked once, in
 * one place, by the router (ADR-004 D3), the registry gate (D4 layer 1) and the
 * `/settings/ai` screen that greys a provider out with a reason.
 *
 * It composes the two rules rather than restating either:
 *
 * - `LOCAL_ONLY` is an **address** question, so it goes to `EgressGuard`. S2-13
 *   answered it with a literal allowlist of the three loopback spellings, which
 *   was deliberately a placeholder: `127.1`, `0177.0.0.1` and a hostname that
 *   resolves to loopback all pass the real rule and failed the literal one, and
 *   a hostname that resolves *away* from loopback passed the literal one and
 *   fails the real rule. There is now one implementation and the router
 *   delegates to it.
 * - `EU_RESTRICTED` is an **evidence** question, so it goes to
 *   `RegionEvidence::missingFor()` — the single implementation of ADR-004 D5 —
 *   and the refusal carries the missing field names. No hostname is ever
 *   consulted for a region (D5; `NoSdkTypeLeakArchTest` fails on a regex over
 *   provider hosts in `app/AI`).
 *
 * The modes compose as a conjunction when both apply, exactly as D5 says.
 */
final readonly class ProviderEligibility
{
    /** The drivers that can be local at all (ADR-004 D4 layer 1). */
    public const array LOCAL_DRIVERS = ['ollama', 'openai-compatible'];

    public function __construct(private EgressGuard $guard) {}

    /** Whether the provider may be built and called under the mode. */
    public function allows(ProviderConnection $provider, PrivacyMode $mode, bool $allowLan = false, ?DateTimeImmutable $now = null): bool
    {
        return $this->refusalFor($provider, $mode, $allowLan, $now) === null;
    }

    /**
     * Why not, in the vocabulary the surface needs: an `EgressRule` for an
     * address refusal, the literal string `region_evidence_missing` for an
     * evidence refusal (the field names come from `RegionEvidence` itself, which
     * the caller already holds), or null when the provider is eligible.
     */
    public function refusalFor(ProviderConnection $provider, PrivacyMode $mode, bool $allowLan = false, ?DateTimeImmutable $now = null): ?string
    {
        $now ??= new DateTimeImmutable;

        if ($mode === PrivacyMode::EuRestricted) {
            return ($provider->regionEvidence ?? RegionEvidence::none())->missingFor($now) === []
                ? null
                : 'region_evidence_missing';
        }

        if ($mode !== PrivacyMode::LocalOnly) {
            return null;
        }

        if (! in_array($provider->driver, self::LOCAL_DRIVERS, true)) {
            return EgressRule::LocalOnly->value;
        }

        $url = $this->endpointOf($provider);

        if ($url === null) {
            return EgressRule::MalformedAuthority->value;
        }

        return $this->guard->refusalFor($url, EgressPurpose::LocalRuntime, $mode, $allowLan)?->value;
    }

    /**
     * The endpoint the adapter will dial. `ollama` without a configured URL uses
     * the SDK's own default, which is loopback on its documented port, so the
     * check is made against that rather than against nothing — a provider whose
     * URL we cannot name is refused, never assumed local.
     */
    private function endpointOf(ProviderConnection $provider): ?string
    {
        if ($provider->baseUrl !== null && trim($provider->baseUrl) !== '') {
            return $provider->baseUrl;
        }

        return $provider->driver === 'ollama' ? 'http://localhost:11434' : null;
    }
}
