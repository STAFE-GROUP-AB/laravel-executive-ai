<?php

declare(strict_types=1);

namespace App\AI\Runs;

/**
 * ADR-004 **D7**'s thirteen `ai_runs` provenance fields — the `provenance` row's
 * twelve ("provider, model id, model digest, quantization, privacy mode, region
 * evidence id, prompt hash, capabilities snapshot, usage, cost, wall time,
 * outcome") plus the `trace_id` row's ULID per run — mapped to the columns that
 * hold them.
 *
 * The enum exists so the set is machine-checked rather than prose:
 * `tests/Integration/BackendContract/AiAndApprovalsSchemaContractTest.php` walks
 * every case and asserts the columns on both ADR-002 D1 profiles.
 */
enum RunProvenanceField: string
{
    case TraceId = 'trace_id';
    case Provider = 'provider';
    case ModelId = 'model_id';
    case ModelDigest = 'model_digest';
    case Quantization = 'quantization';
    case PrivacyMode = 'privacy_mode';
    case RegionEvidenceId = 'region_evidence_id';
    case PromptHash = 'prompt_hash';
    case CapabilitiesSnapshot = 'capabilities_snapshot';
    case Usage = 'usage';
    case Cost = 'cost';
    case WallTime = 'wall_time';
    case Outcome = 'outcome';

    /**
     * The `ai_runs` columns that carry this field.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::TraceId => ['trace_id'],
            self::Provider => ['provider', 'ai_provider_id'],
            self::ModelId => ['model_id', 'ai_model_id'],
            self::ModelDigest => ['model_digest'],
            self::Quantization => ['quantization'],
            self::PrivacyMode => ['privacy_mode'],
            self::RegionEvidenceId => ['region_evidence_id'],
            self::PromptHash => ['prompt_hash'],
            self::CapabilitiesSnapshot => ['capabilities_snapshot'],
            self::Usage => [
                'input_tokens',
                'output_tokens',
                'cache_read_input_tokens',
                'cache_write_input_tokens',
                'reasoning_tokens',
                'total_tokens',
            ],
            self::Cost => ['cost_eur'],
            self::WallTime => ['wall_time_ms'],
            self::Outcome => ['outcome'],
        };
    }

    /**
     * Every column named by D7, in case order.
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
