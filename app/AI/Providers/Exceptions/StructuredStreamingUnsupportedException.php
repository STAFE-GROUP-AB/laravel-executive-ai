<?php

declare(strict_types=1);

namespace App\AI\Providers\Exceptions;

use App\AI\Providers\Dto\AiError;
use App\AI\Providers\Dto\AiErrorKind;

/**
 * A streaming request asked for schema-validated output, which `laravel/ai`
 * 1.2.0 cannot do: `StreamsText::stream()` throws
 * `InvalidArgumentException('Streaming structured output is not currently
 * supported.')` for any agent implementing `HasStructuredOutput`.
 *
 * It is a transport limit of the pinned SDK, not a property of the model, so it
 * says so rather than reporting the model as incapable. The caller's options are
 * `generate()` or an unschema'd stream — never a stream whose free text is then
 * parsed into the schema, which is the first of D3's forbidden downgrades.
 */
final class StructuredStreamingUnsupportedException extends AiProviderException
{
    public function __construct(public readonly string $modelId)
    {
        parent::__construct(new AiError(
            kind: AiErrorKind::CapabilityMissing,
            message: 'Schema-validated output cannot be streamed on this SDK version; request it without streaming.',
            retryable: false,
        ));
    }
}
