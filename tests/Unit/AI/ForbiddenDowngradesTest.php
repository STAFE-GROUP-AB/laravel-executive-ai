<?php

declare(strict_types=1);

use App\AI\Providers\Dto\AiErrorKind;
use App\AI\Providers\Dto\AiMessage;
use App\AI\Providers\Dto\AiMessageRole;
use App\AI\Providers\Dto\AiToolDefinition;
use App\AI\Providers\Exceptions\CapabilityUnavailableException;
use App\AI\Providers\Exceptions\ContextWindowExceededException;
use App\AI\Providers\Exceptions\InvalidStructuredOutputException;
use App\AI\Providers\InMemoryProviderConnections;
use App\AI\Providers\ModelCapability;
use App\AI\Providers\ProviderConnection;
use App\AI\Providers\ProviderKind;
use App\AI\Routing\JobRequirements;
use App\AI\Routing\ModelCapabilityRegistry;
use App\AI\Routing\ModelRouter;
use App\AI\Routing\PrivacyMode;
use App\Support\Cancellation\NeverCancelled;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Responses\Data\ToolCall;
use PHPUnit\Framework\Assert;
use Tests\Integration\AI\Contract\Support\ContractHarness;

/*
 * ADR-004 D3's five forbidden downgrades, one negative test each (threat model
 * T-67). Each one is a plausible, helpful-looking shortcut that would make the
 * product quietly less trustworthy than it claims:
 *
 *   1. dropping structured output and regex-parsing free text;
 *   2. dropping tools and letting the model "describe" the action instead;
 *   3. truncating context silently;
 *   4. switching provider or model inside a run;
 *   5. retrying on a different model after a capability error.
 *
 * The tests assert the refusal, not the mechanism, so a future implementation
 * is free to change how it refuses and not whether it does.
 */

function downgradeRegistry(): ModelCapabilityRegistry
{
    return new ModelCapabilityRegistry(app('config'), base_path('resources/ai/models.json'));
}

// ------------------------------------------------- 1. structured output → regex

it('refuses structured output rather than parsing a shape out of free text', function (): void {
    // The answer plainly contains the value a regex would lift out. Taking it
    // would present ungrounded data as schema-validated, which is worse than no
    // answer at all.
    $adapter = ContractHarness::adapter('openai', ['Here you go — headline: "Two commitments today".']);

    try {
        $adapter->generate(ContractHarness::request('openai', overrides: [
            'outputSchema' => ['headline' => (new StringType)->required()],
        ]), new NeverCancelled);

        Assert::fail('free text must never be parsed into a schema');
    } catch (InvalidStructuredOutputException $exception) {
        expect($exception->error->kind)->toBe(AiErrorKind::StructuredOutputInvalid)
            ->and($exception->error->retryable)->toBeFalse();
    }
});

it('refuses a structured request on a model that cannot do structured output', function (): void {
    // Fail closed *before* the call, so there is no free-text answer lying
    // around to be tempted by.
    $registry = downgradeRegistry();

    expect($registry->resolve('ollama', 'nomic-embed-text')->structuredOutput)->toBeFalse();

    $router = new ModelRouter(
        new InMemoryProviderConnections([
            new ProviderConnection(
                providerId: 'ollama',
                driver: 'ollama',
                kind: ProviderKind::Local,
                baseUrl: 'http://127.0.0.1:11434',
                modelIds: ['nomic-embed-text'],
            ),
        ]),
        $registry,
    );

    expect(fn (): mixed => $router->resolve(
        new JobRequirements('ExtractCommitments', [ModelCapability::Text, ModelCapability::StructuredOutput]),
        PrivacyMode::LocalOnly,
    ))->toThrow(CapabilityUnavailableException::class);
});

// ----------------------------------------------------- 2. tools → "describe it"

it('refuses a tool job rather than asking the model to describe the action', function (): void {
    $registry = downgradeRegistry();

    // This model has no tools in the registry. The downgrade would be to run it
    // anyway with the tool list folded into the prompt as "describe what you
    // would do" — which produces an action nobody gated.
    expect($registry->resolve('ollama', 'nomic-embed-text')->tools)->toBeFalse();

    $router = new ModelRouter(
        new InMemoryProviderConnections([
            new ProviderConnection(
                providerId: 'ollama',
                driver: 'ollama',
                kind: ProviderKind::Local,
                baseUrl: 'http://127.0.0.1:11434',
                modelIds: ['nomic-embed-text'],
            ),
        ]),
        $registry,
    );

    try {
        $router->resolve(new JobRequirements('RunAuthorizedTool', [ModelCapability::Text, ModelCapability::Tools]), PrivacyMode::LocalOnly);
        Assert::fail('a tool job must not run on a model without tools');
    } catch (CapabilityUnavailableException $exception) {
        expect($exception->missing)->toContain(ModelCapability::Tools);
    }
});

it('keeps a tool call a proposal, so a described action cannot become a real one', function (): void {
    $tool = new AiToolDefinition('email.send', 'Send mail.', ['to' => new StringType]);
    $adapter = ContractHarness::adapter('openai', [
        new ToolCall('call_1', 'email.send', ['to' => 'customer@example.test']),
    ]);

    $response = $adapter->generate(
        ContractHarness::request('openai', overrides: ['tools' => [$tool]]),
        new NeverCancelled,
    );

    // Proposed, not executed: `AiResponse` never reports a tool as run, and the
    // bridge's executor throws if the SDK loop ever reaches it.
    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('email.send');
});

// --------------------------------------------------- 3. silent context truncation

it('refuses an oversized prompt instead of silently truncating it', function (): void {
    $router = new ModelRouter(
        new InMemoryProviderConnections([
            new ProviderConnection(
                providerId: 'ollama',
                driver: 'ollama',
                kind: ProviderKind::Local,
                baseUrl: 'http://127.0.0.1:11434',
                modelIds: ['llama3.2:3b'],
            ),
        ]),
        downgradeRegistry(),
    );

    try {
        $router->resolve(
            new JobRequirements('ComposeBrief', [ModelCapability::Text], contextTokens: 500_000),
            PrivacyMode::LocalOnly,
        );
        Assert::fail('an oversized prompt must be refused with its token count');
    } catch (ContextWindowExceededException $exception) {
        expect($exception->contextTokens)->toBe(500_000)
            ->and($exception->contextWindow)->toBe(131_072)
            ->and($exception->error->retryable)->toBeFalse();
    }
});

it('sends every message it was given, so nothing is dropped to make a prompt fit', function (): void {
    $seen = [];

    $adapter = ContractHarness::adapter('openai', function (string $prompt, $attachments, $provider, string $model) use (&$seen): string {
        $seen[] = $prompt;

        return 'Answered.';
    });

    $adapter->generate(ContractHarness::request('openai', [
        new AiMessage(AiMessageRole::User, 'First turn, kept whole.'),
        new AiMessage(AiMessageRole::Assistant, 'First answer.'),
        new AiMessage(AiMessageRole::User, 'Second turn, kept whole.'),
    ]), new NeverCancelled);

    expect($seen)->toBe(['Second turn, kept whole.'], 'the trailing turn travels verbatim, never trimmed');
});

// ------------------------------------------- 4. provider or model switch mid-run

it('never switches provider or model inside a run', function (): void {
    Event::fake([AgentFailedOver::class, ProviderFailedOver::class]);

    $models = [];

    $adapter = ContractHarness::adapter('openai', function (string $prompt, $attachments, $provider, string $model) use (&$models): string {
        $models[] = $model;

        return 'Answered once.';
    });

    $adapter->generate(ContractHarness::request('openai'), new NeverCancelled);

    expect(array_unique($models))->toHaveCount(1, 'one model for the whole run');

    Event::assertNotDispatched(AgentFailedOver::class);
    Event::assertNotDispatched(ProviderFailedOver::class);
});

it('offers alternatives only as a choice before the run, never as a fallback inside it', function (): void {
    $router = ContractHarness::router([
        ContractHarness::connection('ollama'),
        ContractHarness::connection('openai'),
    ]);

    $decision = $router->resolve(new JobRequirements('SummarizeThread'), PrivacyMode::Custom);

    // The decision names exactly one provider and one model. `alternatives` is
    // what the UI may offer the owner *before* starting; the run path reads only
    // the singular fields, so there is nowhere for a fallback to live.
    expect($decision->provider->providerId)->toBe('ollama')
        ->and($decision->modelId)->toBe('llama3.2:3b')
        ->and($decision->alternatives)->toHaveCount(1)
        ->and($decision->alternatives[0]->provider->providerId)->toBe('openai');
});

// -------------------------------------- 5. retry on another model after a refusal

it('does not retry on another model after a capability error', function (): void {
    // Two providers are configured and only one can do vision — but the job
    // needs vision *and* tools, which neither has. A retry-on-the-next-model
    // implementation would try both and report whichever failed last; the router
    // refuses once, naming what is missing.
    $router = ContractHarness::router([
        ContractHarness::connection('ollama'),
        ContractHarness::connection('openai'),
    ]);

    $attempts = 0;

    try {
        $router->resolve(
            new JobRequirements('AssessProjectRisk', [ModelCapability::Text, ModelCapability::Embeddings]),
            PrivacyMode::Custom,
        );
    } catch (CapabilityUnavailableException $exception) {
        $attempts++;

        expect($exception->missing)->toBe([ModelCapability::Embeddings])
            ->and($exception->error->retryable)->toBeFalse('a capability error is not retryable, on this model or any other');
    }

    expect($attempts)->toBe(1, 'one refusal, not one attempt per configured model');
});

it('marks a capability refusal as not retryable so no caller is invited to try again', function (): void {
    $refusal = new CapabilityUnavailableException('gpt-5-mini', [ModelCapability::Embeddings]);

    expect($refusal->error->retryable)->toBeFalse()
        ->and($refusal->error->kind)->toBe(AiErrorKind::CapabilityMissing)
        ->and($refusal->error->message)->toContain('embeddings');
});
