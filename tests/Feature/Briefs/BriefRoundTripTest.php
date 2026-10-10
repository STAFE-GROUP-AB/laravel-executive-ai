<?php

declare(strict_types=1);

use App\Briefs\BriefDocumentMapper;
use App\Briefs\DeliveryChannel;
use App\Briefs\DeliveryStatus;
use App\Briefs\InvalidBriefDocumentException;
use App\Models\Brief;
use App\Models\BriefDelivery;
use App\Models\Organization;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use App\Support\Json\Jcs;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

function loadBriefFixture(string $file): array
{
    return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
}

function importFixture(string $file): array
{
    $document = loadBriefFixture($file);
    $organization = Organization::factory()->create();
    $brief = app(BriefDocumentMapper::class)->import($document, $organization, Provenance::job('FixtureImport', ProvenanceOrigin::Import));

    return [$document, $brief->fresh()];
}

it('round-trips every brief fixture to byte-identical canonical JSON', function (string $file): void {
    [$document, $brief] = importFixture($file);

    $exported = app(BriefDocumentMapper::class)->export($brief);

    expect(Jcs::canonicalize($exported))->toBe(Jcs::canonicalize($document))
        ->and($exported['content_hash'])->toBe($document['content_hash'])
        ->and($brief->items()->count())->toBe(count($document['items']))
        ->and($brief->deliveries()->count())->toBe(count($document['deliveries']));
})->with(fn () => glob(dirname(__DIR__, 3).'/packaging/fixtures/briefs/*.json') ?: []);

it('stores the §3.1 common columns on every brief row', function (): void {
    [, $brief] = importFixture(base_path('packaging/fixtures/briefs/brief-canonical.json'));

    expect($brief->organization_id)->toBeString()
        ->and($brief->source_uri)->toBe("lxai://briefs/{$brief->document_id}/versions/1")
        ->and($brief->sensitivity_class->value)->toBe('confidential')
        ->and($brief->provenance['actor'])->toBe('job:FixtureImport')
        ->and($brief->items->first()?->provenance['origin'])->toBe('import')
        ->and($brief->utc_offset)->toBe('+02:00')
        ->and($brief->generated_at->toIso8601String())->toBe('2026-10-09T03:30:00+00:00');
});

it('rejects a document whose content_hash does not match its envelope', function (): void {
    $document = loadBriefFixture(base_path('packaging/fixtures/briefs/brief-canonical.json'));
    $document['subject'] = 'tampered';

    expect(fn () => app(BriefDocumentMapper::class)->import($document, Organization::factory()->create(), Provenance::job('FixtureImport')))
        ->toThrow(InvalidBriefDocumentException::class);
});

it('cannot record a second sent delivery for the same idempotency key, channel and recipient', function (): void {
    [, $brief] = importFixture(base_path('packaging/fixtures/briefs/brief-canonical.json'));

    $sent = $brief->deliveries()->where('channel', DeliveryChannel::Email->value)->where('status', DeliveryStatus::Sent->value)->firstOrFail();

    expect(fn () => BriefDelivery::query()->create([
        'organization_id' => $brief->organization_id,
        'brief_id' => $brief->id,
        'idempotency_key' => $brief->idempotency_key,
        'position' => 99,
        'channel' => DeliveryChannel::Email,
        'recipient' => $sent->recipient,
        'attempt' => $sent->attempt + 1,
        'status' => DeliveryStatus::Sent,
        'message_id' => 'duplicate',
        'sent_at' => now(),
        'provenance' => Provenance::job('Test'),
    ]))->toThrow(QueryException::class, 'brief_deliveries.sent_marker');
});

it('allows further failed or skipped attempts after a sent one', function (): void {
    [, $brief] = importFixture(base_path('packaging/fixtures/briefs/brief-canonical.json'));

    $delivery = BriefDelivery::query()->create([
        'organization_id' => $brief->organization_id,
        'brief_id' => $brief->id,
        'idempotency_key' => $brief->idempotency_key,
        'position' => 99,
        'channel' => DeliveryChannel::Email,
        'recipient' => 'andreas@example.se',
        'attempt' => 3,
        'status' => DeliveryStatus::Skipped,
        'message_id' => null,
        'sent_at' => null,
        'provenance' => Provenance::job('Test'),
    ]);

    expect($delivery->exists)->toBeTrue();
});

it('cannot record the same attempt number twice for one target', function (): void {
    [, $brief] = importFixture(base_path('packaging/fixtures/briefs/brief-canonical.json'));

    expect(fn () => BriefDelivery::query()->create([
        'organization_id' => $brief->organization_id,
        'brief_id' => $brief->id,
        'idempotency_key' => $brief->idempotency_key,
        'position' => 99,
        'channel' => DeliveryChannel::Email,
        'recipient' => 'andreas@example.se',
        'attempt' => 1,
        'status' => DeliveryStatus::Failed,
        'message_id' => null,
        'sent_at' => null,
        'provenance' => Provenance::job('Test'),
    ]))->toThrow(QueryException::class, 'brief_deliveries.attempt');
});

it('keeps (organization, document, version) and idempotency_key unique', function (): void {
    [$document, $brief] = importFixture(base_path('packaging/fixtures/briefs/brief-canonical.json'));

    expect(fn () => app(BriefDocumentMapper::class)->import($document, $brief->organization, Provenance::job('FixtureImport')))
        ->toThrow(QueryException::class);

    $otherOrganization = Organization::factory()->create();
    $document['idempotency_key'] = '01M4FJ7ZJRDVPBT5PEZTZWC6BY';

    expect(fn () => app(BriefDocumentMapper::class)->import($document, $otherOrganization, Provenance::job('FixtureImport')))
        ->toThrow(InvalidBriefDocumentException::class);

    expect(Brief::query()->where('document_id', $document['id'])->count())->toBe(1);
});
