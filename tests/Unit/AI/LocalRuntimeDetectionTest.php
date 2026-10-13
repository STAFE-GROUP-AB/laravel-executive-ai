<?php

declare(strict_types=1);

use App\AI\Providers\Detection\LmStudioDetector;
use App\AI\Providers\Detection\LocalRuntimeDetector;
use App\AI\Providers\Detection\LoopbackProbe;
use App\AI\Providers\Detection\OllamaDetector;
use App\AI\Providers\ModelCapability;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * §12.1 and §12.3.5: the installer detects an installed Ollama or LM Studio and
 * *reuses* it, listing what it found as options with nothing preselected
 * (ADR-004 D3).
 *
 * Every probe here is faked. Detection talks to loopback only, and these tests
 * prove it: `Http::preventStrayRequests()` is armed, and the recorded requests
 * are asserted to be `127.0.0.1` — the literal address, not a hostname, because
 * a name that resolves elsewhere is the DNS-rebinding hole the Local Only
 * boundary exists to close.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function ollamaDetector(): OllamaDetector
{
    return new OllamaDetector(new LoopbackProbe(app(Factory::class)));
}

function lmStudioDetector(): LmStudioDetector
{
    return new LmStudioDetector(new LoopbackProbe(app(Factory::class)));
}

// ------------------------------------------------------------------- presence

it('reports Ollama as absent when nothing answers on loopback', function (): void {
    Http::fake(['127.0.0.1:11434/*' => Http::response(status: 500)]);

    $runtime = ollamaDetector()->detect();

    expect($runtime->running)->toBeFalse()
        ->and($runtime->providerId)->toBe('ollama')
        ->and($runtime->models)->toBe([])
        ->and($runtime->tokenRequired)->toBeFalse();
});

it('never throws when the connection is refused, so detection cannot fail the install', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('Connection refused'));

    expect(ollamaDetector()->detect()->running)->toBeFalse()
        ->and(lmStudioDetector()->detect()->running)->toBeFalse();
});

it('lists the models an installed Ollama reports, marking the loaded ones', function (): void {
    Http::fake([
        '127.0.0.1:11434/api/version' => Http::response(['version' => '0.12.3']),
        '127.0.0.1:11434/api/ps' => Http::response(['models' => [['model' => 'llama3.2:3b']]]),
        '127.0.0.1:11434/api/tags' => Http::response(['models' => [
            ['model' => 'llama3.2:3b', 'digest' => 'sha256:abc', 'details' => ['parameter_size' => '3.2B', 'quantization_level' => 'Q4_K_M']],
            ['model' => 'phi4-mini:latest', 'details' => ['parameter_size' => '3.8B', 'quantization_level' => 'Q4_K_M']],
        ]]),
    ]);

    $runtime = ollamaDetector()->detect();

    expect($runtime->running)->toBeTrue()
        ->and($runtime->version)->toBe('0.12.3')
        ->and($runtime->modelIds())->toBe(['llama3.2:3b', 'phi4-mini:latest'])
        ->and($runtime->models[0]->loaded)->toBeTrue()
        ->and($runtime->models[0]->quantization)->toBe('Q4_K_M')
        ->and($runtime->models[0]->digest)->toBe('sha256:abc')
        ->and($runtime->models[1]->loaded)->toBeFalse();

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://127.0.0.1:11434/'));
});

it('turns the Ollama capability report into a probe overlay', function (): void {
    Http::fake([
        '127.0.0.1:11434/api/show' => Http::response([
            'capabilities' => ['completion', 'tools', 'vision'],
            'details' => ['quantization_level' => 'Q4_K_M'],
            'model_info' => ['llama.context_length' => 131072],
        ]),
    ]);

    $probe = ollamaDetector()->probeModel('llama3.2:3b');

    expect($probe)->not->toBeNull()
        ->and($probe->driver)->toBe('ollama')
        ->and($probe->reports(ModelCapability::Text))->toBeTrue()
        ->and($probe->reports(ModelCapability::Tools))->toBeTrue()
        ->and($probe->reports(ModelCapability::Vision))->toBeTrue()
        ->and($probe->reports(ModelCapability::Embeddings))->toBeFalse()
        // Structured output is not something Ollama reports, so the probe stays
        // silent about it rather than implying it.
        ->and($probe->reports(ModelCapability::StructuredOutput))->toBeFalse()
        ->and($probe->contextWindow)->toBe(131072)
        ->and($probe->quantization)->toBe('Q4_K_M');
});

// ----------------------------------------------------------------- LM Studio

it('reads the richer LM Studio record when the v0 API answers', function (): void {
    Http::fake([
        '127.0.0.1:1234/v1/models' => Http::response(['data' => [['id' => 'qwen2.5-7b-instruct']]]),
        '127.0.0.1:1234/api/v0/models' => Http::response(['data' => [
            ['id' => 'qwen2.5-7b-instruct', 'type' => 'llm', 'state' => 'loaded', 'quantization' => 'Q4_K_M', 'max_context_length' => 32768],
            ['id' => 'nomic-embed-text-v1.5', 'type' => 'embeddings', 'state' => 'not-loaded'],
        ]]),
    ]);

    $runtime = lmStudioDetector()->detect();

    expect($runtime->running)->toBeTrue()
        ->and($runtime->driver)->toBe('openai-compatible')
        ->and($runtime->baseUrl)->toBe('http://127.0.0.1:1234/v1')
        ->and($runtime->modelIds())->toBe(['qwen2.5-7b-instruct', 'nomic-embed-text-v1.5'])
        ->and($runtime->models[0]->loaded)->toBeTrue()
        ->and($runtime->models[0]->contextWindow)->toBe(32768)
        ->and($runtime->models[1]->loaded)->toBeFalse();
});

it('still offers LM Studio when only the OpenAI-compatible endpoint answers', function (): void {
    // An LM Studio older than 0.3.6 has no /api/v0; the runtime is still there
    // and still usable, so it must still be offered.
    Http::fake([
        '127.0.0.1:1234/v1/models' => Http::response(['data' => [['id' => 'local-model']]]),
        '127.0.0.1:1234/api/v0/models' => Http::response(status: 404),
    ]);

    $runtime = lmStudioDetector()->detect();

    expect($runtime->running)->toBeTrue()
        ->and($runtime->modelIds())->toBe(['local-model'])
        ->and($runtime->models[0]->contextWindow)->toBeNull('nothing is invented for a runtime that did not report it');
});

it('treats a 401 as running and needing a token, never as not installed', function (): void {
    Http::fake(['127.0.0.1:1234/v1/models' => Http::response(status: 401)]);

    $runtime = lmStudioDetector()->detect();

    // Telling the owner LM Studio is missing would send them to reinstall
    // something they already have; the honest answer is "running, token
    // required" and a prompt for the token.
    expect($runtime->running)->toBeTrue()
        ->and($runtime->tokenRequired)->toBeTrue()
        ->and($runtime->models)->toBe([]);
});

it('treats a 401 from the v0 API alone as needing a token too', function (): void {
    Http::fake([
        '127.0.0.1:1234/v1/models' => Http::response(['data' => []]),
        '127.0.0.1:1234/api/v0/models' => Http::response(status: 401),
    ]);

    expect(lmStudioDetector()->detect()->tokenRequired)->toBeTrue();
});

it('maps the LM Studio model type to capabilities and nothing more', function (): void {
    Http::fake([
        '127.0.0.1:1234/api/v0/models' => Http::response(['data' => [
            ['id' => 'a-vlm', 'type' => 'vlm', 'max_context_length' => 8192],
            ['id' => 'an-embedder', 'type' => 'embeddings'],
            ['id' => 'a-mystery', 'type' => 'something-new'],
        ]]),
    ]);

    $detector = lmStudioDetector();

    expect($detector->probeModel('a-vlm')?->reports(ModelCapability::Vision))->toBeTrue()
        ->and($detector->probeModel('a-vlm')?->reports(ModelCapability::Tools))->toBeFalse()
        ->and($detector->probeModel('a-vlm')?->contextWindow)->toBe(8192)
        ->and($detector->probeModel('an-embedder')?->reports(ModelCapability::Embeddings))->toBeTrue()
        ->and($detector->probeModel('an-embedder')?->reports(ModelCapability::Text))->toBeFalse()
        // An unrecognised type grants nothing: a future LM Studio category must
        // not be read as a capability nobody has verified.
        ->and($detector->probeModel('a-mystery')?->capabilities)->toBe([])
        ->and($detector->probeModel('not-installed'))->toBeNull();
});

// ----------------------------------------------------------------- boundaries

it('dials the literal loopback address with the ADR-004 D3 timeouts', function (): void {
    expect(LoopbackProbe::HOST)->toBe('127.0.0.1')
        ->and(LoopbackProbe::CONNECT_TIMEOUT_SECONDS)->toBe(0.5)
        ->and(LoopbackProbe::TOTAL_TIMEOUT_SECONDS)->toBe(2)
        ->and((new LoopbackProbe(app(Factory::class)))->baseUrl(11434))->toBe('http://127.0.0.1:11434');
});

it('offers the detectors as a tagged set so the installer does not name them', function (): void {
    $detectors = iterator_to_array(app()->tagged(LocalRuntimeDetector::class));

    expect(array_map(fn (object $detector): string => $detector->providerId(), $detectors))
        ->toBe(['ollama', 'lmstudio']);
});
