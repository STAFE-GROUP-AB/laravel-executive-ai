<?php

declare(strict_types=1);

namespace App\AI\Providers;

use DateTimeImmutable;

/**
 * The ADR-004 D5 evidence a provider must carry before it is eligible under
 * `EU_RESTRICTED`. Every field is supplied by the owner from a vendor page or a
 * contract clause; **nothing here may be inferred from a hostname** (§4, D5) and
 * `tests/Security/NoSdkTypeLeakArchTest.php` fails on a regex over provider
 * hosts anywhere in `app/AI`.
 *
 * `local_machine` satisfies inference locality by construction; the model's own
 * licence is a separate question (ADR-004 D8, ADR-007).
 */
final readonly class RegionEvidence
{
    public function __construct(
        public ProcessingRegion $processingRegion,
        public RegionMechanism $regionMechanism,
        public ?string $evidenceUrl = null,
        public ?DateTimeImmutable $verifiedAt = null,
        public ?string $dpaReference = null,
        public ?DateTimeImmutable $dpaSignedAt = null,
        public ?string $subprocessorsUrl = null,
        public ?string $transferImpactRef = null,
        public bool $zeroDataRetention = false,
        public ?string $verifiedBy = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    /**
     * Inference on the owner's own Mac: D5 "Local runtimes satisfy EU_RESTRICTED
     * for inference by construction (region_mechanism = local_machine)".
     */
    public static function localMachine(): self
    {
        return new self(
            processingRegion: ProcessingRegion::Eu,
            regionMechanism: RegionMechanism::LocalMachine,
            transferImpactRef: 'none_required',
            zeroDataRetention: true,
        );
    }

    /**
     * No evidence recorded at all. Asking this for its missing fields lists
     * every D5 field, which is what the owner actually needs to see: "no
     * evidence" is not an actionable sentence, "these nine fields are missing"
     * is.
     */
    public static function none(): self
    {
        return new self(
            processingRegion: ProcessingRegion::Unknown,
            regionMechanism: RegionMechanism::AccountSetting,
        );
    }

    /**
     * The evidence fields that are missing or expired, in D5's own field names,
     * so the router's refusal can name them. Empty means eligible.
     *
     * @return list<string>
     */
    public function missingFor(DateTimeImmutable $now): array
    {
        if ($this->regionMechanism === RegionMechanism::LocalMachine) {
            return [];
        }

        $missing = [];

        if (! in_array($this->processingRegion, [ProcessingRegion::Eu, ProcessingRegion::EeaPlusCh], true)) {
            $missing[] = 'processing_region';
        }

        foreach ([
            'region_evidence_url' => $this->evidenceUrl,
            'region_verified_at' => $this->verifiedAt,
            'dpa_reference' => $this->dpaReference,
            'dpa_signed_at' => $this->dpaSignedAt,
            'subprocessors_url' => $this->subprocessorsUrl,
            'transfer_impact_ref' => $this->transferImpactRef,
            'verified_by' => $this->verifiedBy,
        ] as $field => $value) {
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $field;
            }
        }

        if (! $this->zeroDataRetention) {
            $missing[] = 'zero_data_retention';
        }

        if ($this->expiresAt === null) {
            $missing[] = 'expires_at';
        } elseif ($this->expiresAt <= $now) {
            $missing[] = 'expires_at (expired)';
        }

        return $missing;
    }
}
