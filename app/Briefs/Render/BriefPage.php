<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use App\Briefs\BriefDocumentMapper;
use App\Models\Brief;
use App\Support\Health\BriefState;
use App\Support\Json\Jcs;
use Carbon\CarbonImmutable;

/**
 * What the PWA brief pages render (S1-04). Built the same way as the mail:
 * document -> BriefParityPayload -> BriefViewModel::build(), so the labels,
 * dates and item order on the page are the mail's, and the payload the page
 * exports for S1-10 is the one it was rendered from (brief-parity.md §1
 * "payload-driven rendering, not payload-extracted").
 *
 * The extras here are renderer-time state the payload deliberately leaves
 * out: the version row id for links, fresh/stale, delivery state, provider.
 */
final readonly class BriefPage
{
    /**
     * @param  array<string, mixed>  $payload  BriefParityPayload::fromDocument()
     * @param  array<string, mixed>  $view  BriefViewModel::build($payload, $locale)
     */
    private function __construct(
        public string $rowId,
        public array $payload,
        public array $view,
        public string $locale,
        public string $staleAfter,
        public string $staleAfterLabel,
        public bool $stale,
        public string $deliveryState,
        public string $provider,
        public string $model,
        public bool $placeholder,
    ) {}

    public static function fromBrief(Brief $brief, BriefDocumentMapper $mapper, CarbonImmutable $now): self
    {
        $document = $mapper->export($brief);
        $payload = BriefParityPayload::fromDocument($document);
        // S1-10 deliberate break (reverted in the next commit): one key on the PWA surface only.
        $payload['surface'] = 'pwa';
        $locale = BriefLocale::resolve($payload['language']);
        $provenance = $document['model_provenance'];

        return new self(
            rowId: $brief->id,
            payload: $payload,
            view: BriefViewModel::build($payload, $locale),
            locale: $locale,
            staleAfter: (string) $document['stale_after'],
            staleAfterLabel: BriefLocale::dateTime((string) $document['stale_after'], $locale),
            stale: $brief->isStaleAt($now),
            deliveryState: (string) $document['delivery_state'],
            provider: (string) ($provenance['provider'] ?? 'none'),
            model: (string) ($provenance['model'] ?? 'none'),
            placeholder: $brief->isPlaceholder() && ($provenance['trace_id'] ?? null) === 'placeholder',
        );
    }

    /** @return list<array<string, mixed>> The mail view model's items: labels, paragraphs, sources, uncertainty, approvals. */
    public function items(): array
    {
        return $this->view['items'];
    }

    public function hasItems(): bool
    {
        return $this->items() !== [];
    }

    public function state(): BriefState
    {
        return $this->stale ? BriefState::Stale : BriefState::Fresh;
    }

    /** RFC 8785 canonical JSON of the parity payload; byte-comparable with the mail and the menu bar. */
    public function parityJson(): string
    {
        return BriefParityPayload::canonicalJson($this->payload);
    }

    public function parityHash(): string
    {
        return Jcs::sha256($this->payload);
    }
}
