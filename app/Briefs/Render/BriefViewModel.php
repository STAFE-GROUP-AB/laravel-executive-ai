<?php

declare(strict_types=1);

namespace App\Briefs\Render;

/**
 * Turns the parity payload into the flat, pre-formatted data the two mail
 * views print. The views receive only this array: no model, no raw
 * document, so whatever appears in the mail is traceable to the payload.
 * Labels come from lang/{sv,en,it,de}/brief.php; dates from BriefLocale.
 */
final class BriefViewModel
{
    /**
     * @param  array<string, mixed>  $payload  BriefParityPayload::fromDocument()
     * @return array<string, mixed>
     */
    public static function build(array $payload, string $locale): array
    {
        $items = [];

        foreach ($payload['items'] as $index => $item) {
            $items[] = self::item($item, $index + 1, $locale);
        }

        return [
            'locale' => $locale,
            'subject' => $payload['subject'],
            'summary_paragraphs' => self::paragraphs($payload['summary']),
            'version' => $payload['version'],
            'version_label' => __('brief.version', ['version' => $payload['version']], $locale),
            'generated_at_label' => BriefLocale::dateTime($payload['generated_at'], $locale),
            'period_label' => BriefLocale::period($payload['period_start'], $payload['period_end'], $locale),
            'items' => $items,
            'privacy_mode' => $payload['privacy_mode'],
            'privacy_mode_label' => __('brief.privacy_modes.'.$payload['privacy_mode'], [], $locale),
            'brief_id' => $payload['id'],
            'content_hash' => $payload['content_hash'],
            'content_hash_short' => substr($payload['content_hash'], 0, 7 + 12),
            'open_url' => url('/briefs/'.$payload['id']),
            'app_name' => (string) config('app.name', 'Laravel Executive AI'),
            'styles' => BriefMailStyles::all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function item(array $item, int $number, string $locale): array
    {
        $sources = [];

        foreach ($item['source_refs'] as $source) {
            $sources[] = [
                ...$source,
                'type_label' => __('brief.source_types.'.$source['type'], [], $locale),
                'href' => str_starts_with($source['uri'], 'https://') ? $source['uri'] : null,
            ];
        }

        $uncertainty = null;

        if ($item['uncertainty'] !== null) {
            $uncertainty = [
                ...$item['uncertainty'],
                'confidence_label' => __('brief.confidence', ['percent' => BriefLocale::percent($item['uncertainty']['confidence'])], $locale),
            ];
        }

        $approvals = [];

        foreach ($item['action_ids'] as $actionId) {
            $approvals[] = ['id' => $actionId, 'href' => url('/approvals/'.$actionId)];
        }

        return [
            'number' => $number,
            'id' => $item['id'],
            'kind' => $item['kind'],
            'kind_label' => __('brief.kinds.'.$item['kind'], [], $locale),
            'priority' => $item['priority'],
            'priority_label' => __('brief.priorities.'.$item['priority'], [], $locale),
            'priority_badge' => BriefMailStyles::priorityBadge($item['priority']),
            'title' => $item['title'],
            'body' => $item['body'],
            'body_paragraphs' => self::paragraphs($item['body']),
            'due_at' => $item['due_at'],
            'due_at_label' => $item['due_at'] === null ? null : BriefLocale::dateTime($item['due_at'], $locale),
            'sources' => $sources,
            'uncertainty' => $uncertainty,
            'approvals' => $approvals,
        ];
    }

    /** @return list<string> */
    private static function paragraphs(string $text): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $text);
        $parts = preg_split("/\n{2,}/", $normalised) ?: [$normalised];

        return array_values(array_filter(array_map(trim(...), $parts), static fn (string $part): bool => $part !== ''));
    }
}
