<?php

declare(strict_types=1);

namespace App\Briefs;

use App\Support\Json\Jcs;

/**
 * content_hash rule (brief schema §2 "Freeze and delivery", §3): SHA-256 over
 * the JCS form of every top-level field except delivery_state, deliveries
 * and content_hash. Same definition as packaging/fixtures/lib/jcs.mjs.
 */
final class BriefContentHash
{
    /** @var array<int, string> */
    public const array NON_CONTENT_FIELDS = ['delivery_state', 'deliveries', 'content_hash'];

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function envelope(array $document): array
    {
        return array_diff_key($document, array_flip(self::NON_CONTENT_FIELDS));
    }

    /** @param array<string, mixed> $document */
    public static function compute(array $document): string
    {
        return Jcs::sha256(self::envelope($document));
    }

    /** @param array<string, mixed> $document */
    public static function matches(array $document): bool
    {
        return isset($document['content_hash']) && self::compute($document) === $document['content_hash'];
    }
}
