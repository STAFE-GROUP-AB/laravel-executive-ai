<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Briefs\Render\BriefMailRenderer;
use App\Briefs\Render\RenderedBrief;
use App\Models\Brief;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes the brief mail parts to files for the installer and the e2e slice
 * (§16 step 8 "Preview exact email"). Renders only; nothing is sent and no
 * mailer is resolved.
 */
class BriefPreviewCommand extends Command
{
    protected $signature = 'lea:brief:preview
        {brief? : Brief version row ULID or document id; defaults to the latest stored brief}
        {--fixture= : Render a brief.schema.json document from this file instead of a stored brief}
        {--format=both : html, text or both}
        {--out= : Directory to write into (default storage/app/brief-previews)}';

    protected $description = 'Render the Executive Brief mail (HTML and plaintext) to files without sending anything';

    public function handle(BriefMailRenderer $renderer): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['html', 'text', 'both'], true)) {
            $this->error("Unknown --format `{$format}`; use html, text or both.");

            return self::INVALID;
        }

        $rendered = $this->render($renderer);

        if ($rendered === null) {
            return self::FAILURE;
        }

        $directory = $this->outputDirectory();
        File::ensureDirectoryExists($directory);

        $base = $directory.DIRECTORY_SEPARATOR."brief-{$rendered->documentId()}-v{$rendered->version()}";

        $this->info("Rendering brief {$rendered->documentId()} v{$rendered->version()} ({$rendered->locale})...");

        if ($format !== 'text') {
            File::put("{$base}.html", $rendered->html);
            $this->line("  html  {$base}.html");
        }

        if ($format !== 'html') {
            File::put("{$base}.txt", $rendered->text);
            $this->line("  text  {$base}.txt");
        }

        File::put("{$base}.parity.json", $rendered->parityJson()."\n");
        $this->line("  parity {$base}.parity.json");

        $this->comment('Preview written. Nothing was sent.');

        return self::SUCCESS;
    }

    private function render(BriefMailRenderer $renderer): ?RenderedBrief
    {
        $fixture = $this->option('fixture');

        if (is_string($fixture) && $fixture !== '') {
            if (! is_file($fixture)) {
                $this->error("Fixture file not found: {$fixture}");

                return null;
            }

            $document = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($document)) {
                $this->error("Fixture is not a JSON object: {$fixture}");

                return null;
            }

            return $renderer->render($document);
        }

        $brief = $this->resolveBrief();

        if ($brief === null) {
            $this->error('No brief found. Pass a brief id, or --fixture=packaging/fixtures/briefs/brief-canonical.json.');

            return null;
        }

        return $renderer->renderBrief($brief);
    }

    private function resolveBrief(): ?Brief
    {
        $id = $this->argument('brief');

        if (is_string($id) && $id !== '') {
            return Brief::query()->find($id)
                ?? Brief::query()->where('document_id', $id)->latestGenerated()->first();
        }

        return Brief::query()->latestGenerated()->first();
    }

    private function outputDirectory(): string
    {
        $out = $this->option('out');

        if (is_string($out) && $out !== '') {
            return rtrim($out, DIRECTORY_SEPARATOR);
        }

        return storage_path('app/brief-previews');
    }
}
