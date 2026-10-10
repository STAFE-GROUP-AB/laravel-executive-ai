<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use App\Support\Json\Jcs;

/**
 * Both mail parts of one frozen brief plus the semantic payload they were
 * rendered from. The payload is what S1-10 compares across surfaces; the
 * parts are what the delivery job (Sprint 2) hands to the chosen sender
 * account and what the preview surfaces show. Nothing here can send.
 */
final readonly class RenderedBrief
{
    /**
     * @param  array<string, mixed>  $parity  BriefParityPayload::fromDocument()
     */
    public function __construct(
        public string $subject,
        public string $locale,
        public string $html,
        public string $text,
        public array $parity,
    ) {}

    public function documentId(): string
    {
        return (string) $this->parity['id'];
    }

    public function version(): int
    {
        return (int) $this->parity['version'];
    }

    public function contentHash(): string
    {
        return (string) $this->parity['content_hash'];
    }

    /** RFC 8785 canonical JSON of the parity payload; byte-comparable across renderers. */
    public function parityJson(): string
    {
        return Jcs::canonicalize($this->parity);
    }

    public function parityHash(): string
    {
        return Jcs::sha256($this->parity);
    }
}
