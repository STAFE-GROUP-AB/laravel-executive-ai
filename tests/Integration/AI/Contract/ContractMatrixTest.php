<?php

declare(strict_types=1);

use App\AI\Providers\CapabilitySource;
use App\AI\Providers\Dto\AiErrorKind;
use App\AI\Providers\Dto\AiFinishReason;
use App\AI\Providers\Dto\AiMessage;
use App\AI\Providers\Dto\AiMessageRole;
use App\AI\Providers\Dto\AiRequest;
use App\AI\Providers\Dto\AiStreamChunkKind;
use App\AI\Providers\Dto\AiToolDefinition;
use App\AI\Providers\Dto\AiUsage;
use App\AI\Providers\Exceptions\CapabilityUnavailableException;
use App\AI\Providers\Exceptions\EgressBlockedException;
use App\AI\Providers\Exceptions\InvalidStructuredOutputException;
use App\AI\Providers\Exceptions\ProviderUnavailableException;
use App\AI\Providers\LaravelAi\SdkToolBridge;
use App\AI\Providers\LaravelAi\UsageMapper;
use App\AI\Providers\ModelCapabilities;
use App\AI\Providers\ModelCapability;
use App\AI\Routing\JobRequirements;
use App\AI\Routing\PrivacyMode;
use App\Support\Cancellation\CancellationSource;
use App\Support\Cancellation\NeverCancelled;
use App\Support\Cancellation\OperationCancelledException;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall as SdkToolCall;
use Laravel\Ai\Streaming\Events\Error as SdkStreamError;
use Laravel\Ai\Tools\Request as SdkToolRequest;
use PHPUnit\Framework\Assert;
use Tests\Integration\AI\Contract\Support\ContractHarness;

/*
 * The ADR-004 D2 contract matrix: twelve rows, every row against every shipped
 * driver (`ollama`, `lmstudio`, `openai`, `anthropic`, `custom`).
 *
 * Every row here runs against the SDK's own `FakeTextGateway` with
 * `Http::preventStrayRequests()` armed, so no row can reach a network and no API
 * key exists anywhere in this repository. The other half of the matrix — the
 * same rows against a real Ollama, a real LM Studio and a test key — is a
 * Mac-lab run recorded in ADR-004's Evidence section, and CI never claims to
 * have performed it. `docs/architecture/ai-providers.md` carries the per-row
 * status table and the lab protocol.
 *
 * Rows whose assertion is about the `ai_runs` / `tool_runs` ledger are skipped
 * with an explicit reason: those tables are S2-02 (#85) and are not created
 * here. The skip is deliberate and listed in the PR, not a quiet gap.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
});

dataset('drivers', ContractHarness::driverNames());

// ---------------------------------------------------------------- row 1: text

it('row 01 text: returns non-empty text with usage populated', function (string $driver): void {
    $adapter = ContractHarness::adapter($driver, ['The day is calm.']);

    $response = $adapter->generate(ContractHarness::request($driver), new NeverCancelled);

    expect($response->text)->toBe('The day is calm.')
        ->and($response->finishReason)->toBe(AiFinishReason::Stop)
        ->and($response->provider)->toBe(ContractHarness::drivers()[$driver]['providerId'])
        ->and($response->model)->toBe(ContractHarness::modelFor($driver))
        ->and($response->traceId)->toBe(ContractHarness::TRACE_ID)
        ->and($response->usage)->toBeInstanceOf(AiUsage::class)
        ->and($response->usage->inputTokens)->toBeInt()
        ->and($response->usage->outputTokens)->toBeInt()
        ->and($response->toolCalls)->toBe([]);
})->with('drivers');

// -------------------------------------------------------------- row 2: stream

it('row 02 stream: yields at least two chunks and ends with usage and a finish reason', function (string $driver): void {
    $adapter = ContractHarness::adapter($driver, ['Three calm words here.']);

    $stream = $adapter->stream(ContractHarness::request($driver), new NeverCancelled);
    $chunks = [];

    foreach ($stream as $chunk) {
        $chunks[] = $chunk;
    }

    $completed = $stream->getReturn();
    $text = implode('', array_map(
        static fn ($chunk): string => $chunk->delta ?? '',
        array_filter($chunks, static fn ($chunk): bool => $chunk->kind === AiStreamChunkKind::TextDelta),
    ));
    $terminal = $chunks[count($chunks) - 1];

    expect(count($chunks))->toBeGreaterThanOrEqual(2)
        ->and($text)->toBe('Three calm words here.', 'the concatenated deltas are the whole answer')
        ->and($terminal->kind)->toBe(AiStreamChunkKind::End)
        ->and($terminal->usage)->toBeInstanceOf(AiUsage::class)
        ->and($terminal->finishReason)->toBe(AiFinishReason::Stop)
        ->and($completed->text)->toBe($text, 'the generator returns the same answer it streamed')
        ->and($completed->usage)->toEqual($terminal->usage);
})->with('drivers');

// --------------------------------------------------------------- row 3: tools

it('row 03 tools: a tool call comes back as a proposal and nothing is executed', function (string $driver): void {
    $tool = new AiToolDefinition(
        name: 'crm.search',
        description: 'Search the local CRM.',
        parameters: ['query' => new StringType],
    );

    $adapter = ContractHarness::adapter($driver, [
        new SdkToolCall('call_1', 'crm.search', ['query' => 'acme']),
    ]);

    $response = $adapter->generate(
        ContractHarness::request($driver, overrides: ['tools' => [$tool]]),
        new NeverCancelled,
    );

    expect($response->toolCalls)->toHaveCount(1)
        ->and($response->toolCalls[0]->name)->toBe('crm.search')
        ->and($response->toolCalls[0]->arguments)->toBe(['query' => 'acme'])
        ->and($response->finishReason)->toBe(AiFinishReason::ToolCalls);
})->with('drivers');

it('row 03 tools: the gate is consulted before execution and the executor is unreachable', function (): void {
    $bridge = new SdkToolBridge(new AiToolDefinition('email.send', 'Send mail.', ['to' => new StringType]));

    // Every bridged tool is approval-gated, which is what stops the SDK's loop
    // from running it: the PolicyGate (E2-4) decides, the model never executes.
    expect($bridge->shouldRequestApproval(new SdkToolRequest(['to' => 'a@b.test'])))->not->toBeNull()
        ->and(fn (): mixed => $bridge->handle(new SdkToolRequest([])))->toThrow(LogicException::class)
        ->and(fn (): mixed => $bridge->withoutApproval())->toThrow(LogicException::class);
});

it('row 03 tools: a denial reaches the model as a tool result it can read', function (string $driver): void {
    $adapter = ContractHarness::adapter($driver, ['Understood, I will not send it.']);

    // A refusal travels back as a ToolResult turn — data the model reads, never
    // an instruction and never a silent drop of the proposal.
    $response = $adapter->generate(ContractHarness::request($driver, [
        new AiMessage(AiMessageRole::User, 'Mail the summary to the customer.'),
        new AiMessage(AiMessageRole::Assistant, ''),
        new AiMessage(AiMessageRole::ToolResult, 'The owner rejected this tool call.', toolCallId: 'call_1'),
    ]), new NeverCancelled);

    expect($response->text)->toBe('Understood, I will not send it.')
        ->and($response->toolCalls)->toBe([], 'a denial does not re-propose the call');
})->with('drivers');

// --------------------------------------------------- row 4: structured output

it('row 04 structured output: the schema is honoured', function (string $driver): void {
    $adapter = ContractHarness::adapter($driver, [['headline' => 'Two commitments today']]);

    $response = $adapter->generate(
        ContractHarness::request($driver, overrides: ['outputSchema' => ['headline' => (new StringType)->required()]]),
        new NeverCancelled,
    );

    expect($response->structuredOutput)->toBe(['headline' => 'Two commitments today']);
})->with('drivers');

it('row 04 structured output: a payload that misses the schema is a failure, never parsed from free text', function (string $driver): void {
    // The model answers with prose, and the prose does contain the value. A
    // downgrade would regex it out; the adapter refuses instead (D3 forbidden
    // downgrade 1).
    try {
        ContractHarness::adapter($driver, ['The headline is: Two commitments today'])->generate(
            ContractHarness::request($driver, overrides: ['outputSchema' => ['headline' => (new StringType)->required()]]),
            new NeverCancelled,
        );

        Assert::fail('free text must never be parsed into a schema');
    } catch (InvalidStructuredOutputException $exception) {
        expect($exception->error->kind)->toBe(AiErrorKind::StructuredOutputInvalid)
            ->and($exception->error->retryable)->toBeFalse()
            ->and($exception->error->message)->not->toContain('Two commitments today', 'the model text is not echoed back');
    }
})->with('drivers');

// ---------------------------------------------------------- row 5: embeddings

it('row 05 embeddings: dimensions come from the registry, and a text-only model refuses', function (): void {
    $capabilities = ContractHarness::capabilities();

    // The embedding model's dimensions are the registry's, not a guess.
    expect($capabilities->resolve('ollama', 'nomic-embed-text')->embeddingDimensions)->toBe(768)
        ->and($capabilities->resolve('ollama', 'nomic-embed-text')->embeddings)->toBeTrue()
        ->and($capabilities->resolve('ollama', 'nomic-embed-text')->text)->toBeFalse();

    // A text model asked to embed is refused with the capability named; the
    // dedicated EmbeddingAdapter is its own contract (module-contracts.md §3).
    $router = ContractHarness::router([ContractHarness::connection('ollama')]);
    $requirements = new JobRequirements('EmbedThread', [ModelCapability::Embeddings]);

    try {
        $router->resolve($requirements, PrivacyMode::LocalOnly);
        Assert::fail('a text-only model must not be routed an embedding job');
    } catch (CapabilityUnavailableException $exception) {
        expect($exception->error->kind)->toBe(AiErrorKind::CapabilityMissing)
            ->and($exception->missing)->toBe([ModelCapability::Embeddings]);
    }
});

// -------------------------------------------------------- row 6: cancellation

it('row 06 cancellation: a token cancelled mid-stream stops iteration within one chunk', function (string $driver): void {
    $adapter = ContractHarness::adapter($driver, ['one two three four five six']);
    $source = new CancellationSource;

    $stream = $adapter->stream(ContractHarness::request($driver), $source);
    $seen = 0;

    foreach ($stream as $chunk) {
        $seen++;

        if ($chunk->kind === AiStreamChunkKind::TextDelta && $seen === 1) {
            $source->cancel('The owner paused AI actions.');
        }
    }

    expect($stream->getReturn()->finishReason)->toBe(AiFinishReason::Cancelled)
        ->and($seen)->toBeLessThan(7, 'iteration stopped rather than draining the stream');
})->with('drivers');

it('row 06 cancellation: cancelling before the first chunk raises instead of returning a partial answer', function (string $driver): void {
    $adapter = ContractHarness::adapter($driver, ['never read']);
    $source = new CancellationSource;
    $source->cancel('Paused.');

    $call = function () use ($adapter, $driver, $source): void {
        foreach ($adapter->stream(ContractHarness::request($driver), $source) as $chunk) {
            // Nothing may be yielded.
        }
    };

    expect($call)->toThrow(OperationCancelledException::class);
})->with('drivers');

it('row 06 cancellation: the cancelled outcome is recorded in ai_runs', function (): void {
    Assert::markTestSkipped('needs S2-02 tables (#85)');
});

// ------------------------------------------------------ row 7: error mapping

it('row 07 error mapping: every SDK exception class maps to its documented kind', function (string $driver): void {
    $cases = [
        [ProviderConnectionException::forProvider('p'), AiErrorKind::Connection, true],
        [ProviderOverloadedException::forProvider('p'), AiErrorKind::Overloaded, true],
        [RateLimitedException::forProvider('p'), AiErrorKind::RateLimited, true],
        [InsufficientCreditsException::forProvider('p'), AiErrorKind::InsufficientCredits, false],
        [new StreamErrorException(new SdkStreamError('e', 'overloaded_error', 'upstream said no', false, 0)), AiErrorKind::StreamError, true],
        [new NoSuchToolException('crm.search'), AiErrorKind::NoSuchTool, false],
    ];

    foreach ($cases as [$thrown, $kind, $retryable]) {
        $adapter = ContractHarness::adapter($driver, function () use ($thrown): never {
            throw $thrown;
        });

        try {
            $adapter->generate(ContractHarness::request($driver), new NeverCancelled);
            Assert::fail($thrown::class.' must surface as a classified failure');
        } catch (ProviderUnavailableException $exception) {
            expect($exception->error->kind)->toBe($kind, $thrown::class)
                ->and($exception->error->retryable)->toBe($retryable, $thrown::class);
        }
    }
})->with('drivers');

it('row 07 error mapping: an unknown exception maps to unknown and is logged scrubbed', function (string $driver): void {
    $secret = 'sk-FAKE-00000000-leaked-in-a-vendor-message';

    $adapter = ContractHarness::adapter($driver, function () use ($secret): never {
        throw new RuntimeException("The vendor said: {$secret}");
    });

    try {
        $adapter->generate(ContractHarness::request($driver), new NeverCancelled);
        Assert::fail('an unmapped exception must still be raised');
    } catch (ProviderUnavailableException $exception) {
        expect($exception->error->kind)->toBe(AiErrorKind::Unknown)
            ->and($exception->error->retryable)->toBeFalse()
            ->and($exception->error->message)->not->toContain($secret, 'the vendor message never becomes our message');
    }
})->with('drivers');

// ----------------------------------------------------- row 8: cost accounting

it('row 08 cost accounting: tokens equal the SDK usage and local runs cost zero', function (string $driver): void {
    $adapter = ContractHarness::adapter($driver, ['Counted.']);
    $response = $adapter->generate(ContractHarness::request($driver), new NeverCancelled);
    $local = ContractHarness::drivers()[$driver]['local'];

    expect($response->usage->totalTokens())->toBe($response->usage->inputTokens + $response->usage->outputTokens);

    $local
        ? expect($response->usage->costEur)->toBe(0.0, 'a local model records tokens at zero cost')
        // No EUR conversion policy is recorded yet, so an external model's cost is
        // honestly unknown rather than a converted guess (see resources/ai/models.json).
        : expect($response->usage->costEur)->toBeNull('an unpriced external model records no cost, never a guess');
})->with('drivers');

it('row 08 cost accounting: cost is tokens times the per-model price in the registry', function (): void {
    $priced = new ModelCapabilities(
        modelId: 'priced-model',
        text: true, streaming: true, tools: false, structuredOutput: false, vision: false, embeddings: false,
        embeddingDimensions: null, contextWindow: 128000, maxOutputTokens: 4096,
        inputPricePerMTok: 0.25, outputPricePerMTok: 2.0, maxConcurrency: 4,
        source: CapabilitySource::Registry, verifiedAt: null,
    );

    $usage = (new UsageMapper)->map(new TextUsage(1_000_000, 500_000), $priced);

    expect($usage->costEur)->toBe(0.25 + 1.0)
        ->and($usage->inputTokens)->toBe(1_000_000)
        ->and($usage->outputTokens)->toBe(500_000);
});

// -------------------------------------------------------- row 9: no failover

it('row 09 no failover: a failing provider raises and never fails over', function (string $driver): void {
    Event::fake([AgentFailedOver::class, ProviderFailedOver::class]);

    $adapter = ContractHarness::adapter($driver, function (): never {
        // A FailoverableException is exactly what would trigger the SDK's silent
        // move to another provider if `provider` were ever passed as an array.
        throw ProviderConnectionException::forProvider('p');
    });

    expect(fn (): mixed => $adapter->generate(ContractHarness::request($driver), new NeverCancelled))
        ->toThrow(ProviderUnavailableException::class);

    Event::assertNotDispatched(AgentFailedOver::class);
    Event::assertNotDispatched(ProviderFailedOver::class);
})->with('drivers');

// -------------------------------------------------- row 10: LOCAL_ONLY egress

it('row 10 local only: a remote provider is never built and no request is made', function (string $driver): void {
    $local = ContractHarness::drivers()[$driver]['local'];

    if ($local) {
        expect(ContractHarness::adapter($driver, ['Local answer.'], PrivacyMode::LocalOnly))
            ->not->toBeNull('a loopback local runtime is allowed under Local Only');

        return;
    }

    // D4 layer 1: not built, not callable. The adapter object never exists, so
    // there is nothing on which a later mistake could make a cloud call.
    expect(fn (): mixed => ContractHarness::adapter($driver, ['unreachable'], PrivacyMode::LocalOnly))
        ->toThrow(EgressBlockedException::class);

    Http::assertNothingSent();
})->with('drivers');

it('row 10 local only: egress_blocked is not a failover trigger', function (): void {
    $blocked = new EgressBlockedException('api.example.test');

    expect($blocked->error->kind)->toBe(AiErrorKind::EgressBlocked)
        ->and($blocked->error->retryable)->toBeFalse()
        ->and($blocked)->not->toBeInstanceOf(FailoverableException::class);
});

// -------------------------------------------------------- row 11: no SDK tables

it('row 11 no SDK tables: prompting works with agent_conversations absent', function (string $driver): void {
    // ADR-004 D1: the SDK's only migration is never published or run, because
    // conversation and memory are app-owned (§4).
    expect(DB::getSchemaBuilder()->hasTable('agent_conversations'))->toBeFalse();

    $adapter = ContractHarness::adapter($driver, ['No store needed.']);
    $response = $adapter->generate(ContractHarness::request($driver, [
        new AiMessage(AiMessageRole::User, 'First turn.'),
        new AiMessage(AiMessageRole::Assistant, 'First answer.'),
        new AiMessage(AiMessageRole::User, 'Second turn.'),
    ]), new NeverCancelled);

    expect($response->text)->toBe('No store needed.');
})->with('drivers');

it('row 11 no SDK tables: no published migration creates the SDK table', function (): void {
    $published = glob(database_path('migrations/*agent_conversations*')) ?: [];

    expect($published)->toBe([]);
});

// ----------------------------------------------------- row 12: explicit model

it('row 12 explicit model: a request without a model is rejected before the SDK is called', function (string $driver): void {
    $gateways = ContractHarness::fakeGateways(['never reached']);

    foreach ($gateways as $gateway) {
        $gateway->preventStrayPrompts();
    }

    expect(fn (): mixed => new AiRequest(
        model: '   ',
        messages: [new AiMessage(AiMessageRole::User, 'Anything.')],
        privacyMode: PrivacyMode::Custom,
        traceId: ContractHarness::TRACE_ID,
    ))->toThrow(InvalidArgumentException::class);

    // The stray-prompt guard above would have thrown had the SDK been reached
    // for this driver; nothing was sent either.
    Http::assertNothingSent();
})->with('drivers');

it('row 12 explicit model: the driver default model is never relied on', function (): void {
    // `OllamaProvider::defaultTextModel()` returns a hard-coded name when no
    // model is configured. Our config carries no `models` key for any provider
    // and every request names its own model, so that fallback is unreachable.
    $config = require base_path('config/ai.php');

    foreach ($config['providers'] as $provider => $settings) {
        expect($settings)->not->toHaveKey('models', "config/ai.php [{$provider}] must not configure a default model");
    }

    foreach (['default', 'default_for_images', 'default_for_audio', 'default_for_transcription', 'default_for_embeddings', 'default_for_reranking', 'default_for_classification'] as $key) {
        expect($config[$key])->toBe('unconfigured')
            ->and($config['providers'])->not->toHaveKey($config[$key], 'the default must name a provider that is never defined');
    }
});

it('row 12 explicit model: the model travels to the SDK on every call', function (string $driver): void {
    $seen = [];

    $adapter = ContractHarness::adapter($driver, function (string $prompt, $attachments, $provider, string $model) use (&$seen): string {
        $seen[] = $model;

        return 'Answered.';
    });

    $adapter->generate(ContractHarness::request($driver), new NeverCancelled);

    expect($seen)->toBe([ContractHarness::modelFor($driver)]);
})->with('drivers');
