<?php

declare(strict_types=1);

use App\Support\Health\AiCompletionSuccessMarker;
use App\Support\Health\AiReadiness;
use App\Support\Health\AiState;
use App\Support\Health\HealthService;
use App\Support\Health\RecordAiCompletionSuccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoreReadyState;
use Tests\Support\Ipc\IpcClient;

use function Pest\Laravel\travelTo;

/*
 * Health contract §3 / blueprint §0, §12.2: `ai_ready` is true only after a real
 * model completion succeeded on the currently selected provider. Selecting a
 * provider never gets there; RecordAiCompletionSuccess is the one writer, and
 * until the AI run path (Sprint 2) calls it, no production code can.
 */

uses(DatabaseTransactions::class);

/** /health/ready is IPC-authenticated (health contract §6); the Supervisor is its reader. */
function readyBody(): array
{
    /** @var TestResponse $response */
    $response = IpcClient::send('GET', '/health/ready', null, ['client' => 'supervisor']);

    return $response->json();
}

beforeEach(function (): void {
    app(HealthService::class)->forgetCached();
    app(AiCompletionSuccessMarker::class)->forget();
    config(['lea.ai.provider' => null]);
});

it('stays configured_untested with ai_ready false when a provider is only selected', function (): void {
    config(['lea.ai.provider' => 'ollama']);

    $report = app(AiReadiness::class)->report();

    expect($report->state)->toBe(AiState::ConfiguredUntested)
        ->and($report->isReady())->toBeFalse()
        ->and($report->provider)->toBe('ollama')
        ->and($report->lastSuccessAt)->toBeNull();

    $body = readyBody();

    expect($body['ai_ready'])->toBeFalse()
        ->and($body['ai_state'])->toBe('configured_untested')
        ->and($body['ai_provider'])->toBe('ollama')
        ->and($body['ai_last_success_at'])->toBeNull();
});

it('flips to ready only after a recorded completion success and reproduces the ai-ready fixture', function (): void {
    travelTo(CarbonImmutable::parse('2026-10-10T18:00:05+02:00'));
    CoreReadyState::create();
    $fixture = json_decode((string) file_get_contents(base_path('packaging/fixtures/health/ready-ai-ready.json')), true, 512, JSON_THROW_ON_ERROR);

    expect(readyBody()['ai_ready'])->toBeFalse('core-ready alone is never AI ready');

    CoreReadyState::aiReady();

    $report = app(AiReadiness::class)->report();
    $body = readyBody();

    expect($report->state)->toBe(AiState::Ready)
        ->and($report->isReady())->toBeTrue()
        ->and($body['ready'])->toBeTrue()
        ->and($body['ai_ready'])->toBe($fixture['ai_ready'])
        ->and($body['ai_state'])->toBe($fixture['ai_state'])
        ->and($body['ai_provider'])->toBe($fixture['ai_provider'])
        ->and($body['ai_last_success_at'])->toBe($fixture['ai_last_success_at']);
});

it('ignores a success recorded on a provider other than the selected one', function (): void {
    config(['lea.ai.provider' => 'ollama']);
    app(RecordAiCompletionSuccess::class)->record('openai', 'gpt-5-mini');

    $report = app(AiReadiness::class)->report();

    expect($report->state)->toBe(AiState::ConfiguredUntested)
        ->and($report->lastSuccessAt)->toBeNull();
});

it('drops back to configured_untested when the provider changes after a success', function (): void {
    CoreReadyState::aiReady('ollama');

    expect(app(AiReadiness::class)->report()->state)->toBe(AiState::Ready);

    config(['lea.ai.provider' => 'anthropic']);
    app(HealthService::class)->forgetCached();

    expect(app(AiReadiness::class)->report()->state)->toBe(AiState::ConfiguredUntested)
        ->and(readyBody()['ai_ready'])->toBeFalse();
});

it('is not_configured without a selected provider even when a success is recorded', function (): void {
    app(RecordAiCompletionSuccess::class)->record('ollama', 'llama3.2:3b');

    $report = app(AiReadiness::class)->report();

    expect($report->state)->toBe(AiState::NotConfigured)
        ->and($report->provider)->toBeNull()
        ->and($report->lastSuccessAt)->toBeNull();
});

it('resets to configured_untested when the success marker is cleared', function (): void {
    CoreReadyState::aiReady('ollama');

    app(RecordAiCompletionSuccess::class)->reset();

    expect(app(AiReadiness::class)->report()->state)->toBe(AiState::ConfiguredUntested)
        ->and(app(AiCompletionSuccessMarker::class)->read())->toBeNull();
});

it('refuses a success without a valid provider or model id', function (): void {
    $recorder = app(RecordAiCompletionSuccess::class);

    expect(fn () => $recorder->record('Ollama!', 'llama3.2:3b'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $recorder->record('ollama', '   '))->toThrow(InvalidArgumentException::class)
        ->and(app(AiCompletionSuccessMarker::class)->read())->toBeNull();
});

it('keeps the success marker free of anything but provider, model and time', function (): void {
    $success = app(RecordAiCompletionSuccess::class)->record('ollama', 'llama3.2:3b', CarbonImmutable::parse('2026-10-10T17:45:12+02:00'));

    expect(array_keys($success->toArray()))->toBe(['provider', 'model', 'at'])
        ->and($success->toArray()['at'])->toBe('2026-10-10T17:45:12+02:00')
        ->and(app(AiCompletionSuccessMarker::class)->read()?->toArray())->toBe($success->toArray());
});

it('has no production caller yet, so no install can reach ai_ready before the AI run path lands', function (): void {
    $writer = app_path('Support/Health/RecordAiCompletionSuccess.php');
    $callers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php' || $file->getPathname() === $writer) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, 'RecordAiCompletionSuccess::class') || str_contains($source, 'use App\Support\Health\RecordAiCompletionSuccess;')) {
            $callers[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    // Sprint 2: replace this assertion with the run loop's own test when it becomes the caller.
    expect($callers)->toBe([]);
});
