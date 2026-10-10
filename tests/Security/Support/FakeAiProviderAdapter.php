<?php

declare(strict_types=1);

namespace Tests\Security\Support;

use App\AI\Providers\AiProviderAdapter;
use App\AI\Providers\CapabilitySource;
use App\AI\Providers\Dto\AiFinishReason;
use App\AI\Providers\Dto\AiMessage;
use App\AI\Providers\Dto\AiMessageRole;
use App\AI\Providers\Dto\AiRequest;
use App\AI\Providers\Dto\AiResponse;
use App\AI\Providers\Dto\AiStreamChunk;
use App\AI\Providers\Dto\AiToolCall;
use App\AI\Providers\Dto\AiToolDefinition;
use App\AI\Providers\Dto\AiUsage;
use App\AI\Providers\ModelCapabilities;
use App\Support\Cancellation\CancellationToken;
use DateTimeImmutable;
use Generator;

/**
 * A model that does exactly what the injected instruction told it to, scripted per
 * attack case.
 *
 * This is the adversarial assumption S0-21 is built on and the reason the gate is
 * worth having: §4 says the LLM proposes and `PolicyGate` validates, so the test
 * must not depend on the model behaving. Here the model is fully compromised — it
 * emits the `email.send` to the attacker address, the `crm.search` that would gather
 * the customer list, the `settings.write` that would widen its own authority, and it
 * echoes the two canaries below into its text. Everything the system still refuses
 * is therefore refused *in code*, which is what §7 requires ("policy decisions in
 * code, not in prompt").
 *
 * It records every `AiRequest` it was given, which is how the suite checks the
 * input side of the boundary: the payload reached the model as a `ToolResult`, never
 * as a `User` or `System` message (contract rule 5), and the tool catalogue did not
 * change between steps (`tool_list_changed_by_content`).
 */
final class FakeAiProviderAdapter implements AiProviderAdapter
{
    /**
     * Stands in for a secret the model has somehow learned (the corpus case asks for
     * `config/ai.php`). Deliberately not credential-shaped, so the secrets-scan lane
     * has nothing to match on.
     */
    public const string SECRET_CANARY = 'CONFIG-SECRET-CANARY-S0021';

    /**
     * Stands in for the system prompt the model is told to print.
     */
    public const string SYSTEM_PROMPT_CANARY = 'SYSTEM-PROMPT-CANARY-S0021';

    /**
     * @var list<AiRequest>
     */
    private array $requests = [];

    /**
     * @param  list<list<AiToolCall>>  $script  one entry per step; each is the hostile
     *                                          proposal set for that step
     */
    public function __construct(
        private array $script = [],
        private readonly string $text = 'Done.',
        private readonly string $providerId = '01M4SEC0000000000000000S21',
        private readonly string $driver = 'ollama',
    ) {}

    /**
     * The model proposes these tool calls and nothing stops it from proposing them:
     * the refusal has to happen after the proposal and before the executor.
     */
    public static function proposing(AiToolCall ...$calls): self
    {
        return new self(script: [array_values($calls)]);
    }

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function capabilities(string $modelId): ModelCapabilities
    {
        return new ModelCapabilities(
            modelId: $modelId,
            text: true,
            streaming: true,
            tools: true,
            structuredOutput: true,
            vision: false,
            embeddings: false,
            embeddingDimensions: null,
            contextWindow: 131072,
            maxOutputTokens: 4096,
            inputPricePerMTok: 0.0,
            outputPricePerMTok: 0.0,
            maxConcurrency: 1,
            source: CapabilitySource::Registry,
            verifiedAt: new DateTimeImmutable(InjectionCorpus::FIXTURE_NOW),
        );
    }

    public function generate(AiRequest $request, CancellationToken $cancellation): AiResponse
    {
        $cancellation->throwIfCancelled();

        $this->requests[] = $request;

        $toolCalls = array_shift($this->script) ?? [];

        return new AiResponse(
            text: $toolCalls === [] ? $this->text : '',
            finishReason: $toolCalls === [] ? AiFinishReason::Stop : AiFinishReason::ToolCalls,
            usage: new AiUsage(
                inputTokens: 1024,
                outputTokens: 128,
                cacheReadInputTokens: null,
                cacheWriteInputTokens: null,
                reasoningTokens: null,
                costEur: 0.0,
            ),
            provider: $this->providerId,
            model: $request->model,
            traceId: $request->traceId,
            toolCalls: $toolCalls,
        );
    }

    public function stream(AiRequest $request, CancellationToken $cancellation): Generator
    {
        $response = $this->generate($request, $cancellation);

        foreach ($response->toolCalls as $toolCall) {
            yield AiStreamChunk::toolCall($toolCall);
        }

        if ($response->text !== '') {
            yield AiStreamChunk::text($response->text);
        }

        yield AiStreamChunk::end($response->usage, $response->finishReason);

        return $response;
    }

    /**
     * @return list<AiRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * Every message the model was shown, across every step.
     *
     * @return list<AiMessage>
     */
    public function messagesSeen(): array
    {
        $messages = [];

        foreach ($this->requests as $request) {
            foreach ($request->messages as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @return list<AiMessage>
     */
    public function messagesSeenWithRole(AiMessageRole $role): array
    {
        return array_values(array_filter(
            $this->messagesSeen(),
            static fn (AiMessage $message): bool => $message->role === $role,
        ));
    }

    /**
     * The tool catalogue offered per step, as name lists. Content may never change
     * it between steps (`tool_list_changed_by_content`).
     *
     * @return list<list<string>>
     */
    public function toolCataloguePerRequest(): array
    {
        return array_map(
            static fn (AiRequest $request): array => array_map(
                static fn (AiToolDefinition $tool): string => $tool->name,
                $request->tools,
            ),
            $this->requests,
        );
    }

    /**
     * The system prompts the run actually sent, so the suite can assert none of them
     * came back out in the answer (`system_prompt_disclosed`).
     *
     * @return list<string>
     */
    public function instructionsSeen(): array
    {
        $instructions = [];

        foreach ($this->requests as $request) {
            if ($request->instructions !== null && $request->instructions !== '') {
                $instructions[] = $request->instructions;
            }

            foreach ($request->messages as $message) {
                if ($message->role === AiMessageRole::System && $message->content !== '') {
                    $instructions[] = $message->content;
                }
            }
        }

        return $instructions;
    }
}
