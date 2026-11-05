<?php
declare(strict_types=1);

namespace App;

/**
 * Text normalization shared by ingest and search, so both sides
 * of the comparison are in the same form.
 */
final class Normalizer
{
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Hebrew niqqud and cantillation marks.
        $text = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $text);
        // Unify geresh/gershayim variants: ׳ ` ´ ’ → '   ״ “ ” → "
        $text = strtr($text, ['׳' => "'", '`' => "'", '´' => "'", '’' => "'", '״' => '"', '“' => '"', '”' => '"']);
        // Keep letters, digits, apostrophes (אינץ') and decimal points (6.1);
        // everything else becomes a space.
        $text = preg_replace("/[^\\p{L}\\p{N}'.]+|(?<!\\d)\\.|\\.(?!\\d)/u", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
