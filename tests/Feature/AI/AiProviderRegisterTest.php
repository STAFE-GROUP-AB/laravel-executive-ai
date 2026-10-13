<?php

declare(strict_types=1);

use App\AI\Compliance\DpaStatus;
use App\AI\Compliance\TiaStatus;
use App\AI\Providers\ProcessingRegion;
use App\AI\Providers\ProviderKind;
use App\AI\Providers\RegionMechanism;
use App\Models\AI\AiProvider;
use App\Models\Organization;
use App\Models\User;
use App\Security\Secrets\SecretRef;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

/*
 * S2-02: `ai_providers` is ADR-007 D6's provider register and the storage half of
 * ADR-004 D5's evidence. Two things are worth a test rather than a comment: the row
 * maps onto S2-13's `RegionEvidence` and `ProviderConnection` DTOs without the
 * registry changing (that was the point of making them DTOs), and the row never
 * carries a credential.
 */

$now = new DateTimeImmutable('2026-10-12T06:00:00Z');

$provider = function (Organization $organization, array $overrides = []): AiProvider {
    return AiProvider::query()->create([
        'organization_id' => $organization->id,
        'provider_id' => 'openai',
        'display_name' => 'OpenAI — EU project',
        'kind' => ProviderKind::Remote,
        'driver' => 'openai',
        'base_url' => 'https://eu.api.openai.com/v1',
        'enabled' => true,
        'secret_service' => SecretRef::AI_PROVIDER,
        'secret_account' => '01M1GF81A07RD39TC6A1BRKMKG',
        'secret_version' => 1,
        'processing_region' => ProcessingRegion::Eu,
        'region_mechanism' => [RegionMechanism::Endpoint->value, RegionMechanism::AccountSetting->value],
        'region_evidence_url' => 'https://developers.openai.com/api/docs/guides/your-data',
        'region_verified_at' => CarbonImmutable::parse('2026-10-10T00:00:00Z'),
        'dpa_reference' => 'DPA-2026-0041',
        'dpa_signed_at' => CarbonImmutable::parse('2026-09-01T00:00:00Z'),
        'subprocessors_url' => 'https://openai.com/subprocessors',
        'subprocessors_checked_at' => CarbonImmutable::parse('2026-10-10T00:00:00Z'),
        'transfer_impact_ref' => 'TIA-2026-0007',
        'zero_data_retention' => true,
        'zero_data_retention_evidence_url' => 'https://openai.com/enterprise-privacy',
        'zero_data_retention_verified_at' => CarbonImmutable::parse('2026-10-10T00:00:00Z'),
        'retention_statement' => '0 days — zero data retention tier',
        'retention_evidence_url' => 'https://openai.com/enterprise-privacy',
        'retention_verified_at' => CarbonImmutable::parse('2026-10-10T00:00:00Z'),
        'evidence_expires_at' => CarbonImmutable::parse('2027-10-10T00:00:00Z'),
        'dpa_url' => 'https://openai.com/policies/data-processing-addendum',
        'dpa_status' => DpaStatus::Executed,
        'tia_status' => TiaStatus::Done,
        'sensitivity_class' => 'internal',
        'provenance' => Provenance::job('ConfigureAiProvider', ProvenanceOrigin::Manual),
        ...$overrides,
    ]);
};

it('is EU_RESTRICTED eligible only with complete, unexpired evidence and both attestations', function () use ($provider, $now): void {
    $organization = Organization::factory()->create();
    $row = $provider($organization, ['verified_by_user_id' => User::factory()->create()->id]);

    expect($row->missingRegionEvidence($now))->toBe([])
        ->and($row->isEuRestrictedEligible($now))->toBeTrue();

    // The region half is D5's and belongs to RegionEvidence...
    $row->region_verified_at = null;
    expect($row->missingRegionEvidence($now))->toContain('region_verified_at')
        ->and($row->isEuRestrictedEligible($now))->toBeFalse();

    // ...the two attestations are D6's and belong to the row.
    $row->region_verified_at = CarbonImmutable::parse('2026-10-10T00:00:00Z');
    $row->dpa_status = DpaStatus::NotReviewed;
    expect($row->isEuRestrictedEligible($now))->toBeFalse();

    $row->dpa_status = DpaStatus::Reviewed;
    $row->tia_status = TiaStatus::Pending;
    expect($row->isEuRestrictedEligible($now))->toBeFalse();

    $row->tia_status = TiaStatus::NotRequired;
    expect($row->isEuRestrictedEligible($now))->toBeTrue();
});

it('lapses when the evidence expires', function () use ($provider): void {
    $organization = Organization::factory()->create();
    $row = $provider($organization, [
        'verified_by_user_id' => User::factory()->create()->id,
        'evidence_expires_at' => CarbonImmutable::parse('2026-10-11T00:00:00Z'),
    ]);

    expect($row->isEuRestrictedEligible(new DateTimeImmutable('2026-10-10T00:00:00Z')))->toBeTrue()
        ->and($row->isEuRestrictedEligible(new DateTimeImmutable('2026-10-12T00:00:00Z')))->toBeFalse();
});

it('treats a local runtime as satisfying the region by mechanism, not by a region string', function () use ($provider, $now): void {
    $organization = Organization::factory()->create();

    // ADR-004 D5: a local runtime satisfies EU_RESTRICTED for inference by
    // construction. ADR-007 D6 R1's `this-mac` literal is this mechanism.
    $row = $provider($organization, [
        'provider_id' => 'ollama',
        'display_name' => 'Ollama',
        'kind' => ProviderKind::Local,
        'driver' => 'ollama',
        'base_url' => 'http://127.0.0.1:11434',
        'secret_service' => null,
        'secret_account' => null,
        'secret_version' => null,
        'processing_region' => ProcessingRegion::Unknown,
        'region_mechanism' => [RegionMechanism::LocalMachine->value],
        'region_evidence_url' => null,
        'region_verified_at' => null,
        'dpa_reference' => null,
        'dpa_signed_at' => null,
        'dpa_status' => DpaStatus::Reviewed,
        'tia_status' => TiaStatus::NotRequired,
        'evidence_expires_at' => null,
    ]);

    expect($row->regionEvidence()->regionMechanism)->toBe(RegionMechanism::LocalMachine)
        ->and($row->missingRegionEvidence($now))->toBe([])
        ->and($row->isEuRestrictedEligible($now))->toBeTrue();
});

it('maps onto the ProviderConnection the registry already builds adapters from', function () use ($provider): void {
    $organization = Organization::factory()->create();
    $row = $provider($organization, ['verified_by_user_id' => User::factory()->create()->id]);

    $connection = $row->toConnection(['gpt-5-mini']);

    expect($connection->providerId)->toBe('openai')
        ->and($connection->driver)->toBe('openai')
        ->and($connection->kind)->toBe(ProviderKind::Remote)
        ->and($connection->baseUrl)->toBe('https://eu.api.openai.com/v1')
        ->and($connection->modelIds)->toBe(['gpt-5-mini'])
        ->and($connection->headers)->toBe([], 'there is no headers column: that is where a bearer token would live')
        ->and($connection->keyRef)->not->toBeNull()
        ->and($connection->keyRef->service)->toBe(SecretRef::AI_PROVIDER)
        ->and($connection->keyRef->account)->toBe('01M1GF81A07RD39TC6A1BRKMKG')
        ->and($connection->regionEvidence?->processingRegion)->toBe(ProcessingRegion::Eu);
});

it('builds a keyless connection for a local runtime', function () use ($provider): void {
    $organization = Organization::factory()->create();
    $row = $provider($organization, [
        'provider_id' => 'ollama',
        'display_name' => 'Ollama',
        'kind' => ProviderKind::Local,
        'driver' => 'ollama',
        'base_url' => 'http://127.0.0.1:11434',
        'secret_service' => null,
        'secret_account' => null,
        'secret_version' => null,
        'region_mechanism' => [RegionMechanism::LocalMachine->value],
    ]);

    expect($row->toConnection()->keyRef)->toBeNull()
        ->and($row->toConnection()->isLocal())->toBeTrue();
});

it('hides the Keychain coordinates from serialisation', function () use ($provider): void {
    $organization = Organization::factory()->create();
    $keys = array_keys($provider($organization)->toArray());

    expect($keys)->not->toContain('secret_service')
        ->and($keys)->not->toContain('secret_account')
        ->and($keys)->not->toContain('secret_version')
        ->and($keys)->toContain('processing_region');
});
