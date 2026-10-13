<?php

declare(strict_types=1);

namespace App\AI\Providers\LaravelAi;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\HasStructuredOutput;
use LogicException;

/**
 * The bridge used when, and only when, the request carries an `outputSchema`.
 *
 * Structured output is a separate class rather than a flag because the SDK keys
 * the behaviour off the interface: a gateway asks for a schema when the agent
 * `instanceof HasStructuredOutput`, and `StreamsText::stream()` refuses such an
 * agent outright. An always-structured bridge would therefore make every stream
 * throw, and an always-plain one would quietly drop the schema — which is how a
 * schema-less answer gets regex-parsed into one, the first forbidden downgrade
 * of ADR-004 D3.
 */
final class StructuredSdkAgentBridge extends SdkAgentBridge implements HasStructuredOutput
{
    /**
     * The request's schema, unchanged: it is already
     * `array<string, Illuminate\JsonSchema\Types\Type>`, exactly what
     * `HasStructuredOutput::schema()` returns, and the SDK wraps it in its own
     * `ObjectSchema` before sending.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->request->outputSchema
            ?? throw new LogicException('A structured bridge was built for a request without an output schema.');
    }
}
