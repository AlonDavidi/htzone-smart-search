<?php
declare(strict_types=1);

namespace App\Search;

use App\Normalizer;

/**
 * Detects who a query is for (female/male) and which products are explicitly
 * targeted at one gender, so contradicting products can be demoted.
 *
 * Why: neither retriever can see contradictions. For "מתנה למישהי" both match
 * "מארז מתנה טיפוח לגבר" on the word "מתנה", and the small embedding model barely
 * separates מישהו/מישהי (one letter). Scoring only adds points for matches; this
 * rule removes them for an explicit mismatch.
 *
 * Deliberately conservative:
 *  - whole words only ("מגבר" = amplifier, "נשיאה" = carrying - not gender);
 *  - "מישהו" is NOT male: in Hebrew it is the generic "someone";
 *  - a query naming both genders, or neither, changes nothing;
 *  - contradicting products are removed, unless that would leave no results.
 */
final class GenderIntent
{
    private const QUERY_FEMALE = ['מישהי', 'אישה', 'אשה', 'נשים', 'אמא', 'אמי', 'סבתא', 'אחותי', 'אשתי', 'זוגתי',
                                  'חברתי', 'ילדה', 'woman', 'women', 'her', 'she', 'mom', 'mother', 'wife', 'girlfriend'];
    private const QUERY_MALE   = ['גבר', 'גברים', 'אבא', 'אבי', 'סבא', 'אחי', 'בעלי', 'זוגי',
                                  'man', 'men', 'him', 'he', 'dad', 'father', 'husband', 'boyfriend'];
    private const QUERY_FEMALE_PHRASES = ['בת זוג', 'בת הזוג', 'לבת זוג', 'לבת הזוג'];
    private const QUERY_MALE_PHRASES   = ['בן זוג', 'בן הזוג', 'לבן זוג', 'לבן הזוג'];

    private const PRODUCT_FEMALE = ['אישה', 'לאישה', 'נשים', 'לנשים', 'נשי', 'אמא', 'לאמא', 'women'];
    private const PRODUCT_MALE   = ['גבר', 'לגבר', 'גברים', 'לגברים', 'גברי', 'men'];

    /** 'female' | 'male' | null */
    public static function ofQuery(string $query): ?string
    {
        $normalized = Normalizer::normalize($query);
        $words = [];
        foreach (QueryParser::tokens($query) as $t) {
            array_push($words, ...$t['variants']);   // includes prefix-stripped forms: "לאשתי" -> "אשתי"
        }

        $female = array_intersect($words, self::QUERY_FEMALE) !== [] || self::hasPhrase($normalized, self::QUERY_FEMALE_PHRASES);
        $male   = array_intersect($words, self::QUERY_MALE) !== []   || self::hasPhrase($normalized, self::QUERY_MALE_PHRASES);

        return $female === $male ? null : ($female ? 'female' : 'male');
    }

    /** 'female' | 'male' | null - only from explicit words in the product name. */
    public static function ofProduct(string $name): ?string
    {
        $words = explode(' ', Normalizer::normalize($name));
        $female = array_intersect($words, self::PRODUCT_FEMALE) !== [];
        $male   = array_intersect($words, self::PRODUCT_MALE) !== [];

        return $female === $male ? null : ($female ? 'female' : 'male');
    }

    /**
     * Drops products explicitly made for the opposite gender. If every result
     * contradicts, the list is returned as is (something beats an empty page).
     * @param list<array{name: string}> $products
     */
    public static function removeContradicting(string $query, array $products): array
    {
        $target = self::ofQuery($query);
        if ($target === null) {
            return $products;
        }
        $keep = $demoted = [];
        foreach ($products as $p) {
            $g = self::ofProduct($p['name']);
            if ($g !== null && $g !== $target) {
                $demoted[] = $p;
            } else {
                $keep[] = $p;
            }
        }
        return $keep !== [] ? $keep : $demoted;
    }

    private static function hasPhrase(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains(" $text ", " $phrase ")) {
                return true;
            }
        }
        return false;
    }
}
