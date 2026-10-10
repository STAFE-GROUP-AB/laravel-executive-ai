<?php

declare(strict_types=1);

use App\Briefs\BriefDocumentMapper;
use App\Briefs\Generation\PlaceholderBrief;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\JsonSchema;

use function Pest\Laravel\artisan;

uses(DatabaseTransactions::class);

it('produces a brief.schema.json-valid placeholder with provider none in each supported language', function (string $language): void {
    $organization = Organization::factory()->create(['language' => $language]);
    $document = app(PlaceholderBrief::class)->document($organization, CarbonImmutable::parse('2026-10-10T09:10:30+02:00'));

    expect(JsonSchema::errors(JsonSchema::fixturePath('brief.schema.json'), $document))->toBe([])
        ->and($document['model_provenance']['provider'])->toBe('none')
        ->and($document['language'])->toBe($language)
        ->and($document['items'])->toBe([])
        ->and($document['delivery_state'])->toBe('skipped');
})->with(['sv', 'en', 'it', 'de']);

it('stores the placeholder and exports it unchanged', function (): void {
    $organization = Organization::factory()->create(['timezone' => 'Europe/Stockholm']);
    $brief = app(PlaceholderBrief::class)->create($organization);

    $exported = app(BriefDocumentMapper::class)->export($brief->fresh());

    expect($brief->isPlaceholder())->toBeTrue()
        ->and($brief->version)->toBe(1)
        ->and(JsonSchema::errors(JsonSchema::fixturePath('brief.schema.json'), $exported))->toBe([])
        ->and($brief->stale_after->greaterThan($brief->generated_at))->toBeTrue();
});

it('creates the placeholder once through lea:brief:placeholder', function (): void {
    $organization = Organization::factory()->create();

    artisan('lea:brief:placeholder', ['--organization' => $organization->id])->assertSuccessful();
    artisan('lea:brief:placeholder', ['--organization' => $organization->id])
        ->expectsOutputToContain('already has brief')
        ->assertSuccessful();

    expect($organization->briefs()->count())->toBe(1);
});
