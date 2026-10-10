<?php

declare(strict_types=1);

use App\Http\View\AiStateCopy;
use App\Support\Health\AiState;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\Support\PwaFixtures;

use function Pest\Laravel\get;

function pwaManifest(): array
{
    return json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, 512, JSON_THROW_ON_ERROR);
}

it('ships a valid web app manifest that starts at /home as a standalone app', function (): void {
    $manifest = pwaManifest();

    expect($manifest['name'])->toBe('Laravel Executive AI')
        ->and($manifest['short_name'])->not->toBeEmpty()
        ->and(mb_strlen($manifest['short_name']))->toBeLessThanOrEqual(12)
        ->and($manifest['start_url'])->toBe('/home')
        ->and($manifest['scope'])->toBe('/')
        ->and($manifest['id'])->toBe('/home')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['background_color'])->toBe('#f4f4f2')
        ->and($manifest['theme_color'])->toBe('#0f5f70')
        ->and(PwaFixtures::aiOnPhoneClaims($manifest['description']))->toBe([]);
});

it('declares 192 and 512 icons in any and maskable purpose, each present at its declared size', function (): void {
    $manifest = pwaManifest();
    $declared = [];

    foreach ($manifest['icons'] as $icon) {
        $path = public_path(ltrim($icon['src'], '/'));

        expect(file_exists($path))->toBeTrue("{$icon['src']} missing")
            ->and($icon['type'])->toBe('image/png');

        [$width, $height] = getimagesize($path) ?: [0, 0];

        expect("{$width}x{$height}")->toBe($icon['sizes'], "{$icon['src']} is {$width}x{$height}")
            ->and(filesize($path))->toBeLessThan(64 * 1024);

        $declared[] = "{$icon['purpose']}:{$icon['sizes']}";
    }

    expect($declared)->toContain('any:192x192', 'any:512x512', 'maskable:192x192', 'maskable:512x512');
});

it('ships an Apple touch icon of 180x180', function (): void {
    $path = public_path('icons/apple-touch-icon.png');

    expect(file_exists($path))->toBeTrue();

    [$width, $height] = getimagesize($path) ?: [0, 0];

    expect([$width, $height])->toBe([180, 180]);
});

it('mirrors the manifest colours from the design tokens', function (): void {
    $tokens = (string) file_get_contents(resource_path('css/tokens.css'));
    $manifest = pwaManifest();

    expect($tokens)->toContain("--color-canvas: {$manifest['background_color']};")
        ->and($tokens)->toContain("--color-accent: {$manifest['theme_color']};");
});

it('serves the offline page without starting a session so the cached copy carries no cookie', function (): void {
    $response = get('/offline')->assertOk();

    expect($response->headers->getCookies())->toBe([])
        ->and(PwaFixtures::aiOnPhoneClaims($response->getContent()))->toBe([]);

    $response->assertSee('You are offline')->assertSee('href="/home"', false);

    $route = Route::getRoutes()->getByName('offline');
    $excluded = $route?->excludedMiddleware() ?? [];

    expect($excluded)->toContain(StartSession::class, AddQueuedCookiesToResponse::class);
});

it('says AI ready on exactly one copy path', function (): void {
    foreach (AiState::cases() as $state) {
        $label = AiStateCopy::label($state);
        $description = (string) AiStateCopy::description($state);

        expect(PwaFixtures::aiOnPhoneClaims($label.' '.$description))->toBe([]);

        if ($state === AiState::Ready) {
            expect($label)->toBe('AI ready')
                ->and(AiStateCopy::showsBanner($state))->toBeFalse();

            continue;
        }

        expect($label)->not->toContain('AI ready')
            ->and($description)->not->toContain('AI ready')
            ->and(AiStateCopy::showsBanner($state))->toBeTrue();
    }

    expect(AiStateCopy::label(AiState::NotConfigured))->toBe('AI setup required')
        ->and(AiStateCopy::label(AiState::ConfiguredUntested))->toBe('AI setup required')
        ->and(AiStateCopy::label(AiState::Error))->toBe('AI error');
});

it('renders the banner for every non-ready state and nothing for ready', function (): void {
    foreach (AiState::cases() as $state) {
        $html = Blade::render('<x-ai-state-banner :state="$state" />', ['state' => $state]);

        if ($state === AiState::Ready) {
            expect(trim($html))->toBe('');

            continue;
        }

        expect($html)->toContain('data-ai-state-banner="'.$state->value.'"')
            ->and($html)->toContain(AiStateCopy::label($state))
            ->and($html)->not->toContain('AI ready')
            ->and(PwaFixtures::aiOnPhoneClaims($html))->toBe([]);
    }

    $badge = Blade::render('<x-ai-state-badge :state="$state" />', ['state' => AiState::Ready]);

    expect($badge)->toContain('AI ready')->toContain('data-ai-state="ready"');
});
