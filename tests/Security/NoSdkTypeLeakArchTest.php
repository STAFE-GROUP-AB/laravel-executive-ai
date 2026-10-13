<?php

declare(strict_types=1);

use App\AI\Providers\AiProviderAdapter;
use App\AI\Providers\SupportedProviders;

/*
 * ADR-004 D2's architecture boundary, and the four rules that keep the AI layer
 * honest about what it is. Each one exists because the opposite mistake is easy
 * to make, invisible in review and expensive later:
 *
 * - `use Laravel\Ai\…` outside `app/AI/Providers/LaravelAi/` would make the §3
 *   contract a fiction: agents, briefs, routing and tools would be coupled to a
 *   vendor SDK that the ADR pins precisely so it can be replaced.
 * - A `provider` passed as an **array** silently enables
 *   `Promptable::withModelFailover()`, which moves to the next provider on any
 *   `FailoverableException`. That is the silent cloud fallback §4 forbids and
 *   threat model T-66 describes.
 * - A listener for `AgentFailedOver` / `ProviderFailedOver` would mean someone
 *   expects failover to happen. It cannot, and code that prepares for it invites
 *   someone to enable it.
 * - The string `GoZero` may not appear in `app/AI` in any form (§0, §4, §17,
 *   ADR-004 D6: "never, in any form").
 *
 * Plus D5's rule that region is never inferred from a hostname, which is an
 * arch rule for the same reason: a `.eu` check would look like diligence and be
 * the opposite.
 */

/** @return list<string> every PHP file under app/ */
function phpFilesUnder(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function relativeToRepository(string $path): string
{
    return str_replace(base_path().'/', '', $path);
}

/**
 * The file's PHP **code**, with comments and docblocks removed.
 *
 * The boundary rule is about coupling, not vocabulary: a docblock that says
 * "mirrors `Laravel\Ai\Responses\Data\FinishReason`" is exactly the
 * documentation module-contracts.md asks our DTOs to carry, and a check that
 * banned the words would push that mapping out of the code. What may not exist
 * is an import or a type reference the compiler sees.
 */
function phpCodeOf(string $file): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/** The one directory allowed to import the SDK (ADR-004 D2). */
const SDK_BOUNDARY = 'app/AI/Providers/LaravelAi';

it('imports Laravel\Ai only inside app/AI/Providers/LaravelAi', function (): void {
    $offenders = [];

    foreach (phpFilesUnder(app_path()) as $file) {
        $relative = relativeToRepository($file);

        if (str_starts_with($relative, SDK_BOUNDARY.'/')) {
            continue;
        }

        if (str_contains(phpCodeOf($file), 'Laravel\\Ai\\')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], 'SDK types may not leave '.SDK_BOUNDARY);
});

it('keeps the adapter boundary small enough to review', function (): void {
    $inside = array_map(relativeToRepository(...), phpFilesUnder(app_path('AI/Providers/LaravelAi')));

    expect($inside)->toBe([
        'app/AI/Providers/LaravelAi/ErrorMapper.php',
        'app/AI/Providers/LaravelAi/LaravelAiAdapter.php',
        'app/AI/Providers/LaravelAi/LaravelAiAdapterFactory.php',
        'app/AI/Providers/LaravelAi/SdkAgentBridge.php',
        'app/AI/Providers/LaravelAi/SdkMessageMapper.php',
        'app/AI/Providers/LaravelAi/SdkToolBridge.php',
        'app/AI/Providers/LaravelAi/StructuredSdkAgentBridge.php',
        'app/AI/Providers/LaravelAi/UsageMapper.php',
    ], 'adding a class here widens the only part of the product that knows the SDK; say so in review');
});

it('never passes provider as an array, so failover is unreachable', function (): void {
    foreach (phpFilesUnder(app_path('AI')) as $file) {
        $source = phpCodeOf($file);

        // `provider:` must be given a single value. An array literal or a spread
        // would turn the SDK's failover chain on.
        expect(preg_match('/provider:\s*\[/', $source))->toBe(0, relativeToRepository($file).' passes provider as an array')
            ->and(preg_match('/provider:\s*\.\.\./', $source))->toBe(0, relativeToRepository($file).' spreads into provider');

        expect($source)->not->toContain('withModelFailover', relativeToRepository($file))
            ->and($source)->not->toContain('UseCheapestModel', relativeToRepository($file).' would let the SDK pick a model')
            ->and($source)->not->toContain('UseSmartestModel', relativeToRepository($file).' would let the SDK pick a model');
    }
});

it('registers no listener for a failover event', function (): void {
    foreach (phpFilesUnder(app_path()) as $file) {
        $source = phpCodeOf($file);
        $relative = relativeToRepository($file);

        expect($source)->not->toContain('AgentFailedOver', $relative)
            ->and($source)->not->toContain('ProviderFailedOver', $relative);
    }
});

it('never writes GoZero anywhere in app/AI', function (): void {
    foreach (phpFilesUnder(app_path('AI')) as $file) {
        $source = (string) file_get_contents($file);

        expect(stripos($source, 'gozero'))->toBeFalse(relativeToRepository($file).' mentions GoZero (§0, ADR-004 D6)');
    }
});

it('never infers a processing region from a hostname', function (): void {
    foreach (phpFilesUnder(app_path('AI')) as $file) {
        $source = phpCodeOf($file);
        $relative = relativeToRepository($file);

        // D5: "Domain-name inference is forbidden". No `.eu`, no `eu.` prefix
        // test, no regex over a provider hostname deciding eligibility.
        expect(preg_match('/[\'"]\.eu[\'"]|[\'"]eu\.[a-z]/i', $source))->toBe(0, "{$relative} derives region from a domain name")
            ->and(preg_match('/str_(ends_with|starts_with|contains)\s*\(\s*\$\w*(host|url|domain)\w*\s*,\s*[\'"][^\'"]*eu/i', $source))->toBe(0, "{$relative} derives region from a domain name");
    }
});

it('publishes no SDK migration and uses no SDK conversation store', function (): void {
    // ADR-004 D1: conversation memory is app-owned; the SDK's
    // `agent_conversations` table is never published or run.
    expect(glob(database_path('migrations/*agent_conversations*')) ?: [])->toBe([]);

    foreach (phpFilesUnder(app_path()) as $file) {
        $source = phpCodeOf($file);
        $relative = relativeToRepository($file);

        expect($source)->not->toContain('RemembersConversations', $relative)
            ->and($source)->not->toContain('Conversational', $relative)
            ->and($source)->not->toContain('agent_conversations', $relative);
    }
});

it('binds an adapter for exactly the providers the catalogue flags available', function (): void {
    // The honesty flag and the container must agree, and this is the arch-level
    // half of that rule: `SupportedProvidersTest` checks the fixture, this one
    // checks that nothing else got bound along the way.
    $flagged = [];
    $bound = [];

    foreach (SupportedProviders::catalogue() as $provider) {
        if ($provider->adapterAvailable) {
            $flagged[] = $provider->id;
        }

        if (app()->bound(SupportedProviders::adapterBinding($provider->id))) {
            $bound[] = $provider->id;
        }
    }

    expect($bound)->toBe($flagged)
        ->and($flagged)->not->toBe([], 'S2-13 ships adapters; an empty set would mean the bindings were lost');
});

it('resolves a bound adapter to the real implementation', function (): void {
    config(['lea.privacy_mode' => 'CUSTOM', 'lea.ai.provider' => 'ollama']);

    $adapter = app(SupportedProviders::adapterBinding('ollama'));

    expect($adapter)->toBeInstanceOf(AiProviderAdapter::class)
        ->and($adapter->providerId())->toBe('ollama')
        ->and($adapter->driver())->toBe('ollama');
});
