<?php

declare(strict_types=1);

namespace App\CRM\Search;

/**
 * Turns what a person typed into the terms both profiles can match the same way.
 * Only letters, digits and underscores survive; a trailing `*` keeps a term as a
 * prefix. Operators, quotes and punctuation never reach the engines, so a
 * query can neither break the FTS5 parser nor smuggle boolean syntax into MySQL.
 */
final class SearchTerms
{
    public const int MAX_TERMS = 16;

    /** @return list<string> lower-cased terms, a trailing `*` marking a prefix term */
    public static function parse(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}_]+\*?/u', mb_strtolower($query), $matches);

        $terms = [];

        foreach ($matches[0] as $term) {
            $terms[] = $term;

            if (count($terms) === self::MAX_TERMS) {
                break;
            }
        }

        return array_values(array_unique($terms));
    }

    public static function isPrefix(string $term): bool
    {
        return str_ends_with($term, '*');
    }

    public static function word(string $term): string
    {
        return rtrim($term, '*');
    }
}
