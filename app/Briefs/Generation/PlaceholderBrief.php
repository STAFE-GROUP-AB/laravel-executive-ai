<?php

declare(strict_types=1);

namespace App\Briefs\Generation;

use App\Briefs\BriefContentHash;
use App\Briefs\BriefDocumentMapper;
use App\Briefs\DeliveryState;
use App\Models\Brief;
use App\Models\Organization;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * The install-time placeholder brief (health contract §2 `brief_placeholder`):
 * a brief.schema.json-valid document with model_provenance.provider "none",
 * no items and no sources, so the menu bar can always open to something
 * before any model is configured (§14). INSTALL_CORE creates it (S1-05).
 */
final class PlaceholderBrief
{
    private const string PROMPT_HASH_OF_NOTHING = 'sha256:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function __construct(private readonly BriefDocumentMapper $mapper) {}

    public function create(Organization $organization, ?CarbonImmutable $now = null): Brief
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(new DateTimeZone($organization->timezone));

        return $this->mapper->import(
            $this->document($organization, $now),
            $organization,
            Provenance::job('PlaceholderBrief', ProvenanceOrigin::Manual),
        );
    }

    /** @return array<string, mixed> */
    public function document(Organization $organization, CarbonImmutable $now): array
    {
        $language = in_array($organization->language, ['sv', 'en', 'it', 'de'], true) ? $organization->language : 'en';
        $generatedAt = $now->startOfSecond();

        $document = [
            'schema_version' => Brief::SCHEMA_VERSION,
            'id' => (string) Str::ulid(),
            'version' => 1,
            'generated_at' => $generatedAt->format('Y-m-d\TH:i:sP'),
            'period_start' => $generatedAt->format('Y-m-d\TH:i:sP'),
            'period_end' => $generatedAt->format('Y-m-d\TH:i:sP'),
            'language' => $language,
            'subject' => $this->subject($language),
            'summary' => $this->summary($language),
            'items' => [],
            'sources' => [],
            'action_ids' => [],
            'model_provenance' => [
                'provider' => 'none',
                'model' => 'none',
                'privacy_mode' => 'LOCAL_ONLY',
                'prompt_hash' => self::PROMPT_HASH_OF_NOTHING,
                'trace_id' => 'placeholder',
            ],
            'content_hash' => '',
            'delivery_state' => DeliveryState::Skipped->value,
            'idempotency_key' => (string) Str::ulid(),
            'deliveries' => [],
            'stale_after' => $generatedAt->addDay()->format('Y-m-d\TH:i:sP'),
        ];

        $document['content_hash'] = BriefContentHash::compute($document);

        return $document;
    }

    private function subject(string $language): string
    {
        return match ($language) {
            'sv' => 'Välkommen – din första Executive Brief kommer efter AI-inställningen',
            'it' => 'Benvenuto – il primo Executive Brief arriva dopo la configurazione dell’AI',
            'de' => 'Willkommen – das erste Executive Brief kommt nach der KI-Einrichtung',
            default => 'Welcome – your first Executive Brief arrives after AI setup',
        };
    }

    private function summary(string $language): string
    {
        return match ($language) {
            'sv' => 'Kärnan är installerad. Ingen modell är vald ännu, så den här platshållaren innehåller inga punkter. Välj en AI-leverantör i inställningarna för att få din första riktiga brief.',
            'it' => 'Il core è installato. Nessun modello è ancora selezionato, quindi questo segnaposto non contiene voci. Scegli un provider AI nelle impostazioni per ricevere il primo brief reale.',
            'de' => 'Der Kern ist installiert. Es ist noch kein Modell ausgewählt, deshalb enthält dieser Platzhalter keine Punkte. Wählen Sie in den Einstellungen einen KI-Anbieter, um das erste echte Brief zu erhalten.',
            default => 'The core is installed. No model is selected yet, so this placeholder contains no items. Choose an AI provider in settings to receive your first real brief.',
        };
    }
}
