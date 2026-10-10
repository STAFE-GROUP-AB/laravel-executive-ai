<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use App\Briefs\BriefDocumentMapper;
use App\Models\Brief;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Traits\Localizable;

/**
 * Renders one frozen brief document to its HTML and plaintext mail parts
 * (§1.3, §5.5, §16 step 3). Pure: reads the document, writes strings. It
 * has no mailer, no transport and no recipient, which is what makes the
 * local preview (§1.3 "lokalt preview-läge utan utgående mejl") unable to
 * send by construction rather than by configuration.
 *
 * Renderer obligations from brief schema §4: SUPPORTED_SCHEMA_VERSION is the
 * maximum this renderer understands; a higher major is refused, a higher
 * minor is rendered with unknown keys ignored.
 */
final class BriefMailRenderer
{
    use Localizable;

    public const string SUPPORTED_SCHEMA_VERSION = '1.0.0';

    public const string HTML_VIEW = 'mail.brief';

    public const string TEXT_VIEW = 'mail.brief-text';

    public function __construct(
        private readonly ViewFactory $views,
        private readonly PlaintextBriefRenderer $plaintext,
        private readonly BriefDocumentMapper $mapper,
    ) {}

    public function renderBrief(Brief $brief): RenderedBrief
    {
        return $this->render($this->mapper->export($brief));
    }

    /** @param array<string, mixed> $document a brief schema document (fixture or export) */
    public function render(array $document): RenderedBrief
    {
        $this->assertSupportedSchema((string) ($document['schema_version'] ?? ''));

        $payload = BriefParityPayload::fromDocument($document);
        $locale = BriefLocale::resolve($payload['language']);

        return $this->withLocale($locale, function () use ($payload, $locale): RenderedBrief {
            $viewModel = BriefViewModel::build($payload, $locale);

            return new RenderedBrief(
                subject: $payload['subject'],
                locale: $locale,
                html: $this->views->make(self::HTML_VIEW, $viewModel)->render(),
                text: $this->plaintext->render($viewModel),
                parity: $payload,
            );
        });
    }

    private function assertSupportedSchema(string $schemaVersion): void
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $schemaVersion, $matches) !== 1) {
            throw new InvalidBriefRenderInputException("Brief has no valid schema_version (got `{$schemaVersion}`)");
        }

        $supportedMajor = (int) explode('.', self::SUPPORTED_SCHEMA_VERSION)[0];

        if ((int) $matches[1] > $supportedMajor) {
            throw new InvalidBriefRenderInputException(
                "Brief schema {$schemaVersion} is newer than this renderer supports (".self::SUPPORTED_SCHEMA_VERSION.'); update required'
            );
        }
    }
}
