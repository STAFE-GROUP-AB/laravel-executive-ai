<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

use App\AI\Providers\ModelCapability;
use App\AI\Routing\PrivacyMode;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;

/**
 * One model invocation (blueprint §3 `generate(AiRequest)` / `stream(AiRequest)`).
 *
 * Maps onto `Laravel\Ai\Promptable::prompt(...)` / `stream(...)` inside the adapter
 * (ADR-004 D2): `model` is always passed explicitly (D1, contract row 12), `messages`
 * become SDK messages, `tools` become `SdkToolBridge` instances, `outputSchema` goes
 * to `HasStructuredOutput::schema()`, and the bounds map to the `MaxSteps`,
 * `MaxTokens`, `Timeout`, `Temperature`, `TopP` attributes. The request never names
 * more than one provider: the adapter *is* the provider, and failover is forbidden.
 */
final readonly class AiRequest
{
    /**
     * @param  list<AiMessage>  $messages
     * @param  list<AiToolDefinition>  $tools
     * @param  array<string, Type>|null  $outputSchema
     * @param  string  $traceId  ULID of the owning `ai_runs` row; propagated to tool and MCP calls, never sent to external providers
     */
    public function __construct(
        public string $model,
        public array $messages,
        public PrivacyMode $privacyMode,
        public string $traceId,
        public ?string $instructions = null,
        public array $tools = [],
        public ?array $outputSchema = null,
        public ?int $maxOutputTokens = null,
        public ?int $maxSteps = null,
        public ?int $timeoutSeconds = null,
        public ?float $temperature = null,
        public ?float $topP = null,
    ) {
        if (trim($this->model) === '') {
            throw new InvalidArgumentException('An AI request must name its model explicitly (ADR-004 D1).');
        }

        if ($this->messages === []) {
            throw new InvalidArgumentException('An AI request must carry at least one message.');
        }
    }

    /**
     * @return list<ModelCapability>
     */
    public function requiredCapabilities(): array
    {
        $required = [ModelCapability::Text];

        if ($this->tools !== []) {
            $required[] = ModelCapability::Tools;
        }

        if ($this->outputSchema !== null) {
            $required[] = ModelCapability::StructuredOutput;
        }

        foreach ($this->messages as $message) {
            if ($message->attachments !== []) {
                $required[] = ModelCapability::Vision;

                break;
            }
        }

        return $required;
    }
}
