<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\AI\Compliance\DpaStatus;
use App\AI\Compliance\TiaStatus;
use App\AI\Providers\ProcessingRegion;
use App\AI\Providers\ProviderConnection;
use App\AI\Providers\ProviderKind;
use App\AI\Providers\RegionEvidence;
use App\AI\Providers\RegionMechanism;
use App\Models\Concerns\BusinessObject;
use App\Models\Organization;
use App\Models\User;
use App\Security\Secrets\SecretRef;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One configured AI provider account — ADR-007 D6's provider register (§3.1
 * `ai_providers`).
 *
 * The row never holds a credential (ADR-003 D1): `secret_service`,
 * `secret_account` and `secret_version` are the coordinates of a Keychain
 * generic-password item, and all three are hidden from serialisation so no
 * accidental `toArray()` puts them in a response, a log line or a prompt.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $provider_id
 * @property string $display_name
 * @property ProviderKind $kind
 * @property string $driver
 * @property ?string $base_url
 * @property bool $enabled
 * @property ?string $disabled_reason
 * @property ?CarbonImmutable $last_used_at
 * @property ?string $secret_service
 * @property ?string $secret_account
 * @property ?int $secret_version
 * @property ProcessingRegion $processing_region
 * @property list<string> $region_mechanism
 * @property ?string $region_evidence_url
 * @property ?CarbonImmutable $region_verified_at
 * @property ?string $dpa_reference
 * @property ?CarbonImmutable $dpa_signed_at
 * @property ?string $subprocessors_url
 * @property ?CarbonImmutable $subprocessors_checked_at
 * @property ?string $transfer_impact_ref
 * @property bool $zero_data_retention
 * @property ?string $zero_data_retention_evidence_url
 * @property ?CarbonImmutable $zero_data_retention_verified_at
 * @property ?string $retention_statement
 * @property ?string $retention_evidence_url
 * @property ?CarbonImmutable $retention_verified_at
 * @property ?string $verified_by_user_id
 * @property ?CarbonImmutable $evidence_expires_at
 * @property ?string $dpa_url
 * @property DpaStatus $dpa_status
 * @property TiaStatus $tia_status
 * @property ?string $license_name
 * @property ?string $license_url
 * @property ?string $license_text_hash
 * @property ?CarbonImmutable $license_accepted_at
 * @property ?string $license_accepted_by_user_id
 */
class AiProvider extends Model
{
    use BusinessObject;
    use SoftDeletes;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['secret_service', 'secret_account', 'secret_version'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            ...$this->businessObjectCasts(),
            'kind' => ProviderKind::class,
            'enabled' => 'boolean',
            'last_used_at' => 'immutable_datetime',
            'secret_version' => 'integer',
            'processing_region' => ProcessingRegion::class,
            'region_mechanism' => 'array',
            'region_verified_at' => 'immutable_datetime',
            'dpa_signed_at' => 'immutable_datetime',
            'subprocessors_checked_at' => 'immutable_datetime',
            'zero_data_retention' => 'boolean',
            'zero_data_retention_verified_at' => 'immutable_datetime',
            'retention_verified_at' => 'immutable_datetime',
            'evidence_expires_at' => 'immutable_datetime',
            'dpa_status' => DpaStatus::class,
            'tia_status' => TiaStatus::class,
            'license_accepted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<AiModel, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class, 'ai_provider_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * The row as ADR-004 D5's evidence DTO — the shape S2-13's `ModelRouter`
     * already decides `EU_RESTRICTED` eligibility from. The storage half does not
     * get a second opinion on the rule: `RegionEvidence::missingFor()` is the one
     * implementation, and a local runtime is recorded the way D5 prescribes, by
     * `region_mechanism = local_machine` rather than by a region string (which is
     * also what ADR-007 D6 R1's `this-mac` literal means).
     */
    public function regionEvidence(): RegionEvidence
    {
        $mechanisms = array_values(array_filter(array_map(
            RegionMechanism::tryFrom(...),
            $this->region_mechanism,
        )));

        // D5 lets a provider bind its region by more than one mechanism (OpenAI by
        // endpoint *and* by project setting), so the column is a list while the DTO
        // takes one. `local_machine` wins when present, because it is the mechanism
        // that makes the other evidence unnecessary.
        $mechanism = in_array(RegionMechanism::LocalMachine, $mechanisms, true)
            ? RegionMechanism::LocalMachine
            : ($mechanisms[0] ?? RegionMechanism::AccountSetting);

        return new RegionEvidence(
            processingRegion: $this->processing_region,
            regionMechanism: $mechanism,
            evidenceUrl: $this->region_evidence_url,
            verifiedAt: self::instant($this->region_verified_at),
            dpaReference: $this->dpa_reference,
            dpaSignedAt: self::instant($this->dpa_signed_at),
            subprocessorsUrl: $this->subprocessors_url,
            transferImpactRef: $this->transfer_impact_ref,
            zeroDataRetention: $this->zero_data_retention,
            verifiedBy: $this->verified_by_user_id,
            expiresAt: self::instant($this->evidence_expires_at),
        );
    }

    /**
     * The ADR-004 D5 evidence fields this row leaves empty or lets expire, in D5's
     * own field names. An empty list is the precondition for `EU_RESTRICTED`
     * eligibility; a non-empty one is what the router shows the owner instead of
     * refusing silently. `App\AI\Compliance\RegionEvidenceField` maps each field
     * to the column that holds it.
     *
     * @return list<string>
     */
    public function missingRegionEvidence(DateTimeImmutable $now): array
    {
        return $this->regionEvidence()->missingFor($now);
    }

    /**
     * ADR-004 D5 and ADR-007 D6: eligible under `EU_RESTRICTED` only with complete,
     * unexpired region evidence, a DPA at least reviewed (R3) and a TIA that is done
     * or not required (R4). The region half is D5's and belongs to `RegionEvidence`;
     * the two attestations are D6's and belong to this row.
     */
    public function isEuRestrictedEligible(DateTimeImmutable $now): bool
    {
        if ($this->missingRegionEvidence($now) !== []) {
            return false;
        }

        if (! $this->dpa_status->satisfiesEuRestricted()) {
            return false;
        }

        return $this->tia_status->satisfiesEuRestricted();
    }

    /**
     * The row as S2-13's `ProviderRegistry` input. `ProviderConnection` was written
     * as a DTO precisely so that this mapping is the only thing that changes when
     * `ai_providers` lands; nothing in the adapter, the registry or the router does.
     *
     * The key is a `SecretRef` built from the row's Keychain coordinates and
     * resolved at call time — a value never passes through here. `headers` is empty
     * by construction: there is no column for it, because arbitrary headers on the
     * `openai-compatible` driver are where a bearer token would be stored.
     *
     * @param  list<string>  $modelIds
     */
    public function toConnection(array $modelIds = []): ProviderConnection
    {
        return new ProviderConnection(
            providerId: $this->provider_id,
            driver: $this->driver,
            kind: $this->kind,
            baseUrl: $this->base_url,
            keyRef: $this->secret_service === null
                ? null
                : new SecretRef($this->secret_service, $this->secret_account ?? SecretRef::DEFAULT_ACCOUNT),
            modelIds: $modelIds,
            regionEvidence: $this->regionEvidence(),
        );
    }

    private static function instant(?CarbonImmutable $at): ?DateTimeImmutable
    {
        return $at === null ? null : new DateTimeImmutable($at->toIso8601String());
    }
}
