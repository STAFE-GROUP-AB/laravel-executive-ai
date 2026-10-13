<?php

declare(strict_types=1);

namespace App\AI\Providers\LaravelAi;

use App\AI\Providers\AiProviderAdapter;
use App\AI\Providers\Dto\AiError;
use App\AI\Providers\Dto\AiErrorKind;
use App\AI\Providers\Dto\AiFinishReason;
use App\AI\Providers\Dto\AiRequest;
use App\AI\Providers\Dto\AiResponse;
use App\AI\Providers\Dto\AiStreamChunk;
use App\AI\Providers\Dto\AiToolCall;
use App\AI\Providers\Exceptions\CapabilityUnavailableException;
use App\AI\Providers\Exceptions\InvalidStructuredOutputException;
use App\AI\Providers\Exceptions\StructuredStreamingUnsupportedException;
use App\AI\Providers\ModelCapabilities;
use App\AI\Providers\ProviderConnection;
use App\AI\Routing\ModelCapabilityRegistry;
use App\Support\Cancellation\CancellationToken;
use App\Support\Cancellation\OperationCancelledException;
use Generator;
use Illuminate\JsonSchema\Types\ObjectType;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Streaming\Events\Error as SdkErrorEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Streaming\Events\ToolCall as SdkToolCallEvent;
use Throwable;

/**
 * The only class in the product that calls `Laravel\Ai` to run a model
 * (ADR-004 D2). Everything it returns is one of our own DTOs, so agents,
 * routing, memory, briefs and tools never see an SDK type —
 * `tests/Security/NoSdkTypeLeakArchTest.php` fails the build otherwise.
 *
 * Four properties of this class are load-bearing:
 *
 * 1. **One provider, always.** `$providerName` is passed to `prompt()` and
 *    `stream()` as a single string, never an array, so
 *    `Promptable::withModelFailover()` — which silently moves to the next
 *    provider on any `FailoverableException` — has nothing to iterate and
 *    `AgentFailedOver` can never fire (threat model T-66).
 * 2. **Always an explicit model.** `AiRequest` refuses to exist without one, and
 *    it is passed on every call, so `OllamaProvider::defaultTextModel()`'s
 *    hard-coded fallback is unreachable (D1, matrix row 12).
 * 3. **Capabilities are checked before the SDK is touched.** A request the model
 *    cannot serve is refused here, not attempted and patched up afterwards
 *    (D3, matrix rows 4 and 5).
 * 4. **Tool calls come back as proposals.** The SDK's loop would execute them;
 *    `SdkToolBridge` makes every one approval-gated so the loop stops instead,
 *    and what this adapter returns has run nothing (§4, §11, matrix row 3).
 */
final readonly class LaravelAiAdapter implements AiProviderAdapter
{
    public function __construct(
        private ProviderConnection $connection,
        private string $providerName,
        private ModelCapabilityRegistry $capabilities,
        private SdkMessageMapper $messages,
        private UsageMapper $usage,
        private ErrorMapper $errors,
    ) {}

    public function providerId(): string
    {
        return $this->connection->providerId;
    }

    public function driver(): string
    {
        return $this->connection->driver;
    }

    public function capabilities(string $modelId): ModelCapabilities
    {
        return $this->capabilities->resolve($this->connection->driver, $modelId, $this->connection->kind);
    }

    public function generate(AiRequest $request, CancellationToken $cancellation): AiResponse
    {
        $cancellation->throwIfCancelled();

        $capabilities = $this->guardCapabilities($request);
        $bridge = $this->bridgeFor($request);

        try {
            $response = $bridge
                ->withMessages($this->messages->history($request))
                ->prompt(
                    prompt: $this->messages->prompt($request),
                    attachments: $this->messages->attachments($request),
                    provider: $this->providerName,
                    model: $request->model,
                    timeout: $request->timeoutSeconds,
                );
        } catch (Throwable $exception) {
            throw $this->errors->toException($exception);
        }

        $cancellation->throwIfCancelled();

        return $this->mapResponse($request, $response, $capabilities);
    }

    /**
     * @return Generator<int, AiStreamChunk, null, AiResponse>
     */
    public function stream(AiRequest $request, CancellationToken $cancellation): Generator
    {
        $cancellation->throwIfCancelled();

        if ($request->outputSchema !== null) {
            throw new StructuredStreamingUnsupportedException($request->model);
        }

        $capabilities = $this->guardCapabilities($request);
        $bridge = new SdkAgentBridge($request, $this->messages->instructions($request));

        try {
            $stream = $bridge
                ->withMessages($this->messages->history($request))
                ->stream(
                    prompt: $this->messages->prompt($request),
                    attachments: $this->messages->attachments($request),
                    provider: $this->providerName,
                    model: $request->model,
                    timeout: $request->timeoutSeconds,
                );
        } catch (Throwable $exception) {
            throw $this->errors->toException($exception);
        }

        $text = '';
        $reasoning = '';
        $proposals = [];
        $usage = new TextUsage;
        $finishReason = AiFinishReason::Unknown;
        $yielded = false;
        $failed = false;

        try {
            foreach ($stream as $event) {
                // Checked per event, so a cancellation lands within one chunk
                // (ADR-004 D2 row 6) rather than at the end of the run.
                if ($cancellation->isCancelled()) {
                    if (! $yielded) {
                        throw new OperationCancelledException($cancellation->reason());
                    }

                    $finishReason = AiFinishReason::Cancelled;

                    break;
                }

                if ($event instanceof TextDelta) {
                    $text .= $event->delta;
                    $yielded = true;

                    yield AiStreamChunk::text($event->delta);

                    continue;
                }

                if ($event instanceof ReasoningDelta) {
                    $reasoning .= $event->delta;
                    $yielded = true;

                    yield AiStreamChunk::reasoning($event->delta);

                    continue;
                }

                if ($event instanceof SdkToolCallEvent) {
                    $proposal = new AiToolCall(
                        id: $event->toolCall->id,
                        name: $event->toolCall->name,
                        arguments: $event->toolCall->arguments,
                    );
                    $proposals[$proposal->id] = $proposal;
                    $yielded = true;

                    yield AiStreamChunk::toolCall($proposal);

                    continue;
                }

                if ($event instanceof ToolApprovalRequest) {
                    // The loop stopped at the gate; every pending approval is a
                    // proposal, and nothing has run.
                    foreach ($event->pendingApprovals as $pending) {
                        if (isset($proposals[$pending->id])) {
                            continue;
                        }

                        $proposal = new AiToolCall($pending->id, $pending->tool, $pending->arguments);
                        $proposals[$proposal->id] = $proposal;
                        $yielded = true;

                        yield AiStreamChunk::toolCall($proposal);
                    }

                    $finishReason = AiFinishReason::ToolCalls;

                    continue;
                }

                if ($event instanceof StreamEnd) {
                    $usage = $event->usage;
                    $finishReason = $proposals === []
                        ? $this->mapFinishReason(FinishReason::tryFrom($event->reason))
                        : AiFinishReason::ToolCalls;

                    continue;
                }

                if ($event instanceof SdkErrorEvent) {
                    $error = $this->errors->map(new StreamErrorException($event));
                    $yielded = true;
                    $failed = true;

                    yield AiStreamChunk::error($error);

                    $finishReason = AiFinishReason::Error;

                    break;
                }
            }
        } catch (OperationCancelledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->errors->toException($exception);
        }

        $mappedUsage = $this->usage->map($usage, $capabilities);

        // The terminal chunk carries usage and the finish reason (row 2), so a
        // streaming caller never has to reassemble either — except after an
        // `Error` chunk, which `AiStreamChunk` documents as always being the
        // last one. A run that ended in an error still returns its partial
        // response, so the caller can record what it got; it just does not get a
        // tidy `End` chunk implying the stream finished normally.
        //
        // The in-stream error path is only reachable against a real provider:
        // the SDK's own gateways emit `Streaming\Events\Error`, while
        // `FakeTextGateway` never does, so this branch is verified in the lab
        // half of matrix row 7 rather than in CI.
        if (! $failed) {
            yield AiStreamChunk::end($mappedUsage, $finishReason);
        }

        return new AiResponse(
            text: $text,
            finishReason: $finishReason,
            usage: $mappedUsage,
            provider: $this->connection->providerId,
            model: $request->model,
            traceId: $request->traceId,
            toolCalls: array_values($proposals),
            reasoning: $reasoning === '' ? null : $reasoning,
        );
    }

    /**
     * Fail closed before the SDK is called: a model that cannot do what the
     * request needs is refused with the missing capabilities named, and is
     * never attempted in the hope that it manages anyway (D3).
     *
     * @throws CapabilityUnavailableException
     */
    private function guardCapabilities(AiRequest $request): ModelCapabilities
    {
        $capabilities = $this->capabilities($request->model);
        $missing = $capabilities->missing($request->requiredCapabilities());

        if ($missing !== []) {
            throw new CapabilityUnavailableException($request->model, $missing);
        }

        return $capabilities;
    }

    private function bridgeFor(AiRequest $request): SdkAgentBridge
    {
        $instructions = $this->messages->instructions($request);

        return $request->outputSchema === null
            ? new SdkAgentBridge($request, $instructions)
            : new StructuredSdkAgentBridge($request, $instructions);
    }

    private function mapResponse(AiRequest $request, AgentResponse $response, ModelCapabilities $capabilities): AiResponse
    {
        $proposals = $this->proposalsFrom($response);
        $structured = $request->outputSchema === null ? null : $this->structuredFrom($request, $response);

        return new AiResponse(
            text: $response->text,
            finishReason: $proposals === [] ? $this->finishReasonOf($response) : AiFinishReason::ToolCalls,
            usage: $this->usage->map($response->usage, $capabilities),
            provider: $this->connection->providerId,
            model: $request->model,
            traceId: $request->traceId,
            toolCalls: $proposals,
            structuredOutput: $structured,
            reasoning: $response->reasoning === '' ? null : $response->reasoning,
        );
    }

    /**
     * The tool calls the model proposed, read from the approvals the run stopped
     * at. Falling back to `toolCalls` covers a driver that reports the call
     * without reaching the gate; either way these are proposals, and the
     * `AiResponse` contract is that nothing in them has been executed.
     *
     * @return list<AiToolCall>
     */
    private function proposalsFrom(AgentResponse $response): array
    {
        $proposals = [];

        foreach ($response->pendingApprovals as $pending) {
            $proposals[$pending->id] = new AiToolCall($pending->id, $pending->tool, $pending->arguments);
        }

        foreach ($response->toolCalls as $toolCall) {
            if (! isset($proposals[$toolCall->id])) {
                $proposals[$toolCall->id] = new AiToolCall($toolCall->id, $toolCall->name, $toolCall->arguments);
            }
        }

        return array_values($proposals);
    }

    /**
     * Schema-validated output, or a refusal.
     *
     * The validation is deliberately narrow and deliberately present: the schema
     * the caller declared says which top-level properties are required, so a
     * payload missing one is rejected as `structured_output_invalid`. What this
     * must never do is look at `$response->text` and recover a shape from it —
     * regex-parsing free text into a schema is the first forbidden downgrade of
     * D3 and the subject of its own negative test.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidStructuredOutputException
     */
    private function structuredFrom(AiRequest $request, AgentResponse $response): array
    {
        $structured = $this->structuredPayload($response);

        if ($structured === null || $structured === []) {
            throw new InvalidStructuredOutputException($this->structuredError($request));
        }

        foreach ($this->requiredProperties($request) as $property) {
            if (! array_key_exists($property, $structured)) {
                throw new InvalidStructuredOutputException($this->structuredError($request));
            }
        }

        /** @var array<string, mixed> $structured */
        return $structured;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function structuredPayload(AgentResponse $response): ?array
    {
        if (! property_exists($response, 'structured')) {
            return null;
        }

        /** @var mixed $structured */
        $structured = $response->structured;

        /** @var array<string, mixed>|null */
        return is_array($structured) ? $structured : null;
    }

    private function structuredError(AiRequest $request): AiError
    {
        return new AiError(
            kind: AiErrorKind::StructuredOutputInvalid,
            message: "Model {$request->model} did not return output matching the requested schema.",
            retryable: false,
        );
    }

    /**
     * The top-level properties the request's schema marks required. The
     * framework's serializer hoists a property's `required()` flag into the
     * parent object's `required` list rather than leaving it on the property, so
     * the schema has to be serialised to read them.
     *
     * @return list<string>
     */
    private function requiredProperties(AiRequest $request): array
    {
        if ($request->outputSchema === null) {
            return [];
        }

        $serialized = (new ObjectType($request->outputSchema))->toArray();
        $required = $serialized['required'] ?? null;

        if (! is_array($required)) {
            return [];
        }

        $properties = [];

        foreach ($required as $property) {
            if (is_string($property)) {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    private function finishReasonOf(AgentResponse $response): AiFinishReason
    {
        $last = $response->steps->last();

        return $this->mapFinishReason($last instanceof Step ? $last->finishReason : null);
    }

    private function mapFinishReason(?FinishReason $reason): AiFinishReason
    {
        return match ($reason) {
            FinishReason::Stop, FinishReason::Continue => AiFinishReason::Stop,
            FinishReason::ToolCalls => AiFinishReason::ToolCalls,
            FinishReason::Length => AiFinishReason::Length,
            FinishReason::ContentFilter => AiFinishReason::ContentFilter,
            FinishReason::Error => AiFinishReason::Error,
            default => AiFinishReason::Unknown,
        };
    }
}
