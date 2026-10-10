<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use App\Support\Json\Jcs;

/**
 * The semantic projection of one brief document (brief schema §6 "Must be
 * identical"). Every renderer — Blade mail, PWA, SwiftUI menu bar — derives
 * what it shows from exactly this structure and exports it for the S1-10
 * parity test, which compares the JCS canonical form byte for byte.
 *
 * The shape is fixed: every key is always present, absent optionals are
 * null, lists are always lists (possibly empty), item order and source
 * order are the document's. Timestamps keep the document's RFC 3339 text
 * untouched; formatting is presentation and happens later.
 *
 * Documented in docs/architecture/brief-parity.md. Changing a key here is a
 * contract change for all three surfaces.
 */
final class BriefParityPayload
{
    /**
     * @param  array<string, mixed>  $document  a brief schema 1.x document
     * @return array{
     *     schema_version: string,
     *     id: string,
     *     version: int,
     *     generated_at: string,
     *     period_start: string,
     *     period_end: string,
     *     language: string,
     *     subject: string,
     *     summary: string,
     *     privacy_mode: string,
     *     content_hash: string,
     *     items: list<array<string, mixed>>
     * }
     */
    public static function fromDocument(array $document): array
    {
        $sourcesById = [];

        foreach ($document['sources'] ?? [] as $source) {
            $sourcesById[(string) $source['id']] = $source;
        }

        $items = [];

        foreach ($document['items'] ?? [] as $item) {
            $items[] = self::item($item, $sourcesById);
        }

        return [
            'schema_version' => (string) $document['schema_version'],
            'id' => (string) $document['id'],
            'version' => (int) $document['version'],
            'generated_at' => (string) $document['generated_at'],
            'period_start' => (string) $document['period_start'],
            'period_end' => (string) $document['period_end'],
            'language' => (string) $document['language'],
            'subject' => (string) $document['subject'],
            'summary' => (string) $document['summary'],
            'privacy_mode' => (string) ($document['model_provenance']['privacy_mode'] ?? ''),
            'content_hash' => (string) $document['content_hash'],
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function canonicalJson(array $payload): string
    {
        return Jcs::canonicalize($payload);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $sourcesById
     * @return array<string, mixed>
     */
    private static function item(array $item, array $sourcesById): array
    {
        $sourceRefs = [];

        foreach ($item['source_refs'] ?? [] as $sourceId) {
            $source = $sourcesById[(string) $sourceId] ?? null;

            if ($source === null) {
                throw new InvalidBriefRenderInputException("Item {$item['id']} references unknown source {$sourceId}");
            }

            $sourceRefs[] = [
                'id' => (string) $source['id'],
                'type' => (string) $source['type'],
                'account_label' => isset($source['account_label']) ? (string) $source['account_label'] : null,
                'title' => isset($source['title']) ? (string) $source['title'] : null,
                'uri' => (string) $source['uri'],
            ];
        }

        $uncertainty = null;

        if (isset($item['uncertainty']) && is_array($item['uncertainty'])) {
            $uncertainty = [
                'reason' => (string) $item['uncertainty']['reason'],
                'confidence' => $item['uncertainty']['confidence'],
            ];
        }

        if ($sourceRefs === [] && $uncertainty === null) {
            throw new InvalidBriefRenderInputException("Item {$item['id']} has neither source_refs nor uncertainty (§4.2)");
        }

        return [
            'id' => (string) $item['id'],
            'kind' => (string) $item['kind'],
            'priority' => (string) $item['priority'],
            'title' => (string) $item['title'],
            'body' => (string) $item['body'],
            'due_at' => isset($item['due_at']) ? (string) $item['due_at'] : null,
            'source_refs' => $sourceRefs,
            'uncertainty' => $uncertainty,
            'action_ids' => array_values(array_map(strval(...), $item['action_ids'] ?? [])),
        ];
    }
}
