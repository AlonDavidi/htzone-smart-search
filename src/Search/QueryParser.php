<?php
declare(strict_types=1);

namespace App\Search;

use App\Normalizer;

/**
 * Turns a raw query into weighted tokens with spelling variants.
 *
 * Hebrew attaches prefixes to words: "לשואב" = "ל" + "שואב", "והאייפון" = "וה" + "אייפון".
 * We cannot know for sure whether a leading letter is a prefix ("מטבח" starts with מ),
 * so we keep the original token AND add the stripped form as a variant.
 */
final class QueryParser
{
    /** Longest first, so "וכש" is tried before "ו". */
    private const PREFIXES = ['וכש', 'וש', 'וה', 'וב', 'ול', 'ומ', 'וכ', 'שה', 'שב', 'של', 'שמ', 'כש', 'לכ', 'מה', 'בה', 'לה', 'כה',
                              'ו', 'ה', 'ב', 'ל', 'מ', 'ש', 'כ'];

    /** Words that carry no product meaning (lexically). Semantic search still sees the full query. */
    private const STOPWORDS = ['של', 'את', 'עם', 'על', 'אל', 'זה', 'זו', 'גם', 'או', 'כי', 'אם', 'לי', 'לו', 'לה',
                               'משהו', 'מישהו', 'שיעזור', 'שעוזר', 'מחפש', 'מחפשת', 'רוצה', 'צריך', 'צריכה', 'בשביל',
                               'for', 'the', 'a', 'an', 'of', 'with', 'and', 'to', 'something'];

    /**
     * @return list<array{variants: list<string>, weight: float}>
     */
    public static function tokens(string $query): array
    {
        $tokens = [];
        foreach (explode(' ', Normalizer::normalize($query)) as $word) {
            if (mb_strlen($word) < 2 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[] = [
                'variants' => self::variants($word),
                // Bare numbers ("16", "128") are weak signals: the catalog rarely has model numbers.
                'weight'   => ctype_digit($word) ? 0.3 : 1.0,
            ];
        }
        return $tokens;
    }

    /** @return list<string> */
    private static function variants(string $word): array
    {
        $variants = [$word];
        if (preg_match('/^\p{Hebrew}/u', $word)) {
            foreach (self::PREFIXES as $prefix) {
                $rest = mb_substr($word, mb_strlen($prefix));
                if (str_starts_with($word, $prefix) && mb_strlen($rest) >= 3) {
                    $variants[] = $rest;
                    break; // strip only the longest matching prefix
                }
            }
        }
        return $variants;
    }
}
