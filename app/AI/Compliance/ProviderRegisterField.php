<?php

declare(strict_types=1);

namespace App\AI\Compliance;

/**
 * ADR-007 **D6** R1–R7, the model/provider register the admin UI shows, mapped to
 * the columns that hold each row. §3.1 has no `ai_provider_register` /
 * `ai_model_register` tables: `ai_providers` *is* the provider register and
 * `ai_models` *is* the model register, so the seven rows are split between them —
 * R1–R6 are provider-level, R7 (model id / version / quantisation, written into
 * every run's model provenance) is per model and per run.
 *
 * Columns are qualified `table.column`;
 * `tests/Integration/BackendContract/AiAndApprovalsSchemaContractTest.php` walks
 * every case so the split is machine-checked rather than prose.
 */
enum ProviderRegisterField: string
{
    case ProcessingRegion = 'R1';
    case Subprocessors = 'R2';
    case Dpa = 'R3';
    case Tia = 'R4';
    case Retention = 'R5';
    case License = 'R6';
    case Provenance = 'R7';

    /** @return list<string> */
    public function columns(): array
    {
        return match ($this) {
            self::ProcessingRegion => [
                'ai_providers.processing_region',
                'ai_providers.region_evidence_url',
                'ai_providers.region_verified_at',
            ],
            self::Subprocessors => [
                'ai_providers.subprocessors_url',
                'ai_providers.subprocessors_checked_at',
            ],
            self::Dpa => [
                'ai_providers.dpa_url',
                'ai_providers.dpa_status',
            ],
            self::Tia => ['ai_providers.tia_status'],
            self::Retention => [
                'ai_providers.retention_statement',
                'ai_providers.retention_evidence_url',
                'ai_providers.retention_verified_at',
            ],
            self::License => [
                'ai_providers.license_name',
                'ai_providers.license_url',
                'ai_providers.license_text_hash',
                'ai_providers.license_accepted_at',
                'ai_providers.license_accepted_by_user_id',
                'ai_models.license_name',
                'ai_models.license_url',
                'ai_models.license_text_hash',
                'ai_models.license_accepted_at',
                'ai_models.license_accepted_by_user_id',
            ],
            self::Provenance => [
                'ai_models.model_id',
                'ai_models.model_version',
                'ai_models.model_digest',
                'ai_models.quantization',
                'ai_runs.model_id',
                'ai_runs.model_digest',
                'ai_runs.quantization',
            ],
        };
    }

    public function describe(): string
    {
        return match ($this) {
            self::ProcessingRegion => 'Region(s) where prompts are processed and stored, with the document relied on and when it was checked',
            self::Subprocessors => 'The provider\'s current subprocessor list, re-checked every 90 days',
            self::Dpa => 'The provider\'s DPA and whether the owner\'s organisation has executed it',
            self::Tia => 'Transfer impact assessment for third-country transfers',
            self::Retention => 'The provider\'s stated prompt and output retention',
            self::License => 'Licence name, URL, text hash and acceptance record for the provider\'s terms and for each model',
            self::Provenance => 'Model id, version, digest and quantisation, copied into every run',
        };
    }
}
