<?php

declare(strict_types=1);

namespace App\Briefs;

use App\Models\Brief;
use App\Models\BriefDelivery;
use App\Models\BriefItem;
use App\Models\Organization;
use App\Support\Data\SensitivityClass;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Maps a brief schema 1.0.0 document to the briefs / brief_items /
 * brief_deliveries tables and back without loss. The round trip is proven
 * byte for byte on the canonical (JCS) form of every fixture in
 * packaging/fixtures/briefs/ by tests/Feature/Briefs/BriefRoundTripTest.php.
 *
 * Timestamps are stored in UTC; the document's offset is kept on
 * briefs.utc_offset and every timestamp is rendered back in it.
 */
final class BriefDocumentMapper
{
    private const string RFC3339 = 'Y-m-d\TH:i:sP';

    /**
     * Import is the freeze transaction of brief schema §5: brief row, items,
     * deliveries, one commit. The document's content_hash must match the
     * recomputed one, otherwise the document is rejected.
     *
     * @param  array<string, mixed>  $document
     * @param  array{origin: string, actor: string, recorded_at: string, trace_id: ?string}  $provenance
     */
    public function import(array $document, Organization $organization, array $provenance): Brief
    {
        if (($document['schema_version'] ?? null) !== Brief::SCHEMA_VERSION) {
            throw new InvalidBriefDocumentException('Unsupported brief schema_version');
        }

        if (! BriefContentHash::matches($document)) {
            throw new InvalidBriefDocumentException('content_hash does not match the content envelope');
        }

        return DB::transaction(function () use ($document, $organization, $provenance): Brief {
            $offset = $this->offsetOf($document['generated_at']);

            $lineage = [
                'organization_id' => $organization->id,
                'source_uri' => "lxai://briefs/{$document['id']}/versions/{$document['version']}",
                'sensitivity_class' => SensitivityClass::Confidential,
                'provenance' => $provenance,
            ];

            $brief = Brief::query()->create([
                ...$lineage,
                'document_id' => $document['id'],
                'version' => $document['version'],
                'schema_version' => $document['schema_version'],
                'generated_at' => $this->toUtc($document['generated_at']),
                'period_start' => $this->toUtc($document['period_start']),
                'period_end' => $this->toUtc($document['period_end']),
                'stale_after' => $this->toUtc($document['stale_after']),
                'utc_offset' => $offset,
                'language' => $document['language'],
                'subject' => $document['subject'],
                'summary' => $document['summary'],
                'sources' => $document['sources'],
                'action_ids' => $document['action_ids'],
                'model_provenance' => $document['model_provenance'],
                'content_hash' => $document['content_hash'],
                'delivery_state' => DeliveryState::from($document['delivery_state']),
                'idempotency_key' => $document['idempotency_key'],
            ]);

            foreach ($document['items'] as $position => $item) {
                BriefItem::query()->create([
                    ...$lineage,
                    'brief_id' => $brief->id,
                    'item_id' => $item['id'],
                    'position' => $position,
                    'kind' => ItemKind::from($item['kind']),
                    'title' => $item['title'],
                    'body' => $item['body'],
                    'priority' => Priority::from($item['priority']),
                    'due_at' => isset($item['due_at']) ? $this->toUtc($item['due_at']) : null,
                    'source_refs' => $item['source_refs'] ?? null,
                    'uncertainty' => $item['uncertainty'] ?? null,
                    'action_ids' => $item['action_ids'] ?? null,
                    'dedupe_key' => $item['dedupe_key'] ?? null,
                ]);
            }

            foreach ($document['deliveries'] as $position => $delivery) {
                BriefDelivery::query()->create([
                    ...$lineage,
                    'brief_id' => $brief->id,
                    'idempotency_key' => $document['idempotency_key'],
                    'position' => $position,
                    'channel' => DeliveryChannel::from($delivery['channel']),
                    'recipient' => $delivery['recipient'],
                    'attempt' => $delivery['attempt'],
                    'status' => DeliveryStatus::from($delivery['status']),
                    'message_id' => $delivery['message_id'],
                    'sent_at' => isset($delivery['sent_at']) ? $this->toUtc($delivery['sent_at']) : null,
                    'error' => $delivery['error'] ?? null,
                ]);
            }

            return $brief;
        });
    }

    /**
     * The full brief schema document, keys in schema order.
     *
     * @return array<string, mixed>
     */
    public function export(Brief $brief): array
    {
        $brief->loadMissing(['items', 'deliveries']);

        return [
            'schema_version' => $brief->schema_version,
            'id' => $brief->document_id,
            'version' => $brief->version,
            'generated_at' => $this->render($brief->generated_at, $brief->utc_offset),
            'period_start' => $this->render($brief->period_start, $brief->utc_offset),
            'period_end' => $this->render($brief->period_end, $brief->utc_offset),
            'language' => $brief->language,
            'subject' => $brief->subject,
            'summary' => $brief->summary,
            'items' => $brief->items->map(fn (BriefItem $item): array => $this->exportItem($item, $brief->utc_offset))->all(),
            'sources' => $brief->sources,
            'action_ids' => $brief->action_ids,
            'model_provenance' => $brief->model_provenance,
            'content_hash' => $brief->content_hash,
            'delivery_state' => $brief->delivery_state->value,
            'idempotency_key' => $brief->idempotency_key,
            'deliveries' => $brief->deliveries->map(fn (BriefDelivery $delivery): array => $this->exportDelivery($delivery, $brief->utc_offset))->all(),
            'stale_after' => $this->render($brief->stale_after, $brief->utc_offset),
        ];
    }

    /** @return array<string, mixed> */
    private function exportItem(BriefItem $item, string $offset): array
    {
        $document = [
            'id' => $item->item_id,
            'kind' => $item->kind->value,
            'title' => $item->title,
            'body' => $item->body,
            'priority' => $item->priority->value,
        ];

        if ($item->due_at !== null) {
            $document['due_at'] = $this->render($item->due_at, $offset);
        }

        if ($item->source_refs !== null) {
            $document['source_refs'] = $item->source_refs;
        }

        if ($item->uncertainty !== null) {
            $document['uncertainty'] = $item->uncertainty;
        }

        if ($item->action_ids !== null) {
            $document['action_ids'] = $item->action_ids;
        }

        if ($item->dedupe_key !== null) {
            $document['dedupe_key'] = $item->dedupe_key;
        }

        return $document;
    }

    /** @return array<string, mixed> */
    private function exportDelivery(BriefDelivery $delivery, string $offset): array
    {
        $document = [
            'channel' => $delivery->channel->value,
            'recipient' => $delivery->recipient,
            'attempt' => $delivery->attempt,
            'status' => $delivery->status->value,
            'message_id' => $delivery->message_id,
            'sent_at' => $delivery->sent_at === null ? null : $this->render($delivery->sent_at, $offset),
        ];

        if ($delivery->error !== null) {
            $document['error'] = $delivery->error;
        }

        return $document;
    }

    private function toUtc(string $rfc3339): CarbonImmutable
    {
        return CarbonImmutable::parse($rfc3339)->utc();
    }

    private function offsetOf(string $rfc3339): string
    {
        if (str_ends_with($rfc3339, 'Z')) {
            return 'Z';
        }

        return substr($rfc3339, -6);
    }

    private function render(CarbonImmutable $instant, string $offset): string
    {
        if ($offset === 'Z') {
            return $instant->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return $instant->setTimezone(new DateTimeZone($offset))->format(self::RFC3339);
    }
}
