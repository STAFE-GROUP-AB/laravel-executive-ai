<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * The text/plain alternative part (§5.5 "HTML + plaintext"). Rendered from
 * resources/views/mail/brief-text.blade.php and normalised: LF line
 * endings, no trailing whitespace, at most one blank line in a row, long
 * lines soft-wrapped at 76 columns without breaking words or URIs, single
 * trailing newline. The result is deterministic for the snapshot test.
 */
final class PlaintextBriefRenderer
{
    public const int WRAP_COLUMNS = 76;

    public function __construct(private readonly ViewFactory $views) {}

    /** @param array<string, mixed> $viewModel */
    public function render(array $viewModel): string
    {
        $raw = $this->views->make('mail.brief-text', $viewModel)->render();

        return self::normalise($raw);
    }

    public static function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $lines = [];

        foreach (explode("\n", $text) as $line) {
            $line = rtrim($line);

            if ($line === '') {
                $lines[] = '';

                continue;
            }

            $hangingIndent = str_starts_with($line, '- ') ? '  ' : '';

            foreach (explode("\n", wordwrap($line, self::WRAP_COLUMNS, "\n", false)) as $index => $wrapped) {
                $lines[] = ($index === 0 ? '' : $hangingIndent).rtrim($wrapped);
            }
        }

        $joined = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? implode("\n", $lines);

        return trim($joined)."\n";
    }
}
