<?php

declare(strict_types=1);

namespace App\AI\Compliance;

/**
 * ADR-004 **D5**'s eight region, DPA and retention evidence fields, mapped to the
 * `ai_providers` columns that hold them. A provider is eligible under
 * `EU_RESTRICTED` only when all eight are present and unexpired; missing or expired
 * evidence means the router refuses and names the fields that are missing — it never
 * infers a region from a hostname.
 *
 * The *rule* is `App\AI\Providers\RegionEvidence::missingFor()` (S2-13) and is not
 * duplicated here; this enum is the storage half, the mapping from a D5 field to the
 * column that holds it. It exists so the mapping is machine-checked rather than
 * prose: `tests/Integration/BackendContract/AiAndApprovalsSchemaContractTest.php`
 * walks every case and asserts the columns on both ADR-002 D1 profiles.
 *
 * Two columns carry a name of their own: D5's `verified_by` is
 * `verified_by_user_id`, because it is a foreign key to the person who confirmed the
 * evidence, and D5's `expires_at` is `evidence_expires_at`, because four other
 * tables in this schema use `expires_at` for a grant or an approval and the evidence's expiry is a different thing.
 */
enum RegionEvidenceField: string
{
    case ProcessingRegion = 'processing_region';
    case RegionMechanism = 'region_mechanism';
    case RegionEvidence = 'region_evidence';
    case Dpa = 'dpa';
    case Subprocessors = 'subprocessors';
    case TransferImpact = 'transfer_impact';
    case ZeroDataRetention = 'zero_data_retention';
    case Verification = 'verification';

    /**
     * The `ai_providers` columns that carry this field.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::ProcessingRegion => ['processing_region'],
            self::RegionMechanism => ['region_mechanism'],
            self::RegionEvidence => ['region_evidence_url', 'region_verified_at'],
            self::Dpa => ['dpa_reference', 'dpa_signed_at'],
            self::Subprocessors => ['subprocessors_url'],
            self::TransferImpact => ['transfer_impact_ref'],
            self::ZeroDataRetention => [
                'zero_data_retention',
                'zero_data_retention_evidence_url',
                'zero_data_retention_verified_at',
            ],
            self::Verification => ['verified_by_user_id', 'evidence_expires_at'],
        };
    }

    /**
     * Every column named by D5, in case order.
     *
     * @return list<string>
     */
    public static function allColumns(): array
    {
        return array_merge(...array_map(
            fn (self $field): array => $field->columns(),
            self::cases(),
        ));
    }
}
