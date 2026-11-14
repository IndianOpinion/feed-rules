<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * Text normalisation shared by items, keywords and the test screen.
 *
 * Every function here is pure and safe on any byte string.
 */
final class Text
{
    /** Separator placed between source terms. It is not whitespace and not a letter. */
    public const TERM_SEPARATOR = " \u{241E} ";

    private const BLOCK_TAGS = 'address|article|aside|blockquote|br|dd|details|div|dl|dt|figcaption|figure|footer'
        . '|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|summary|table|tbody|td|tfoot|th|thead|tr|ul';

    /** Characters that count as whitespace after normalisation. */
    private const SPACE_CLASS = '[\s\x{00A0}\x{1680}\x{2000}-\x{200B}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{3000}\x{FEFF}]+';

    /** Invisible characters removed outright: soft hyphen, zero-width non-joiner and joiner. */
    private const INVISIBLE_CLASS = '[\x{00AD}\x{200C}\x{200D}]';

    /**
     * Normalise an HTML or plain-text field the way every rule sees it.
     *
     * Removes script and style elements, strips tags with block boundaries
     * turned into spaces, decodes entities up to three times, repairs
     * invalid UTF-8, applies NFC when intl is present and collapses
     * whitespace. Case is not folded here; see fold().
     */
    public static function normalise(string $html): string
    {
        $text = self::scrub($html);
        $text = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#isu', ' ', $text);
        $text = (string) preg_replace('#</?(?:' . self::BLOCK_TAGS . ')\b[^>]*>#iu', ' ', $text);
        $text = strip_tags($text);
        for ($pass = 0; $pass < 3; $pass++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $text) {
                break;
            }
            $text = $decoded;
        }
        return self::plain($text);
    }

    /**
     * Normalise plain text (a keyword, or text that is already free of HTML).
     */
    public static function plain(string $text): string
    {
        $text = self::scrub($text);
        if (class_exists(\Normalizer::class)) {
            $nfc = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($nfc)) {
                $text = $nfc;
            }
        }
        $text = (string) preg_replace('/' . self::INVISIBLE_CLASS . '/u', '', $text);
        $text = (string) preg_replace('/' . self::SPACE_CLASS . '/u', ' ', $text);
        return trim($text, ' ');
    }

    /**
     * Full Unicode case folding.
     */
    public static function fold(string $text): string
    {
        return mb_convert_case($text, MB_CASE_FOLD, 'UTF-8');
    }

    /**
     * Remove accents through WordPress when it is loaded.
     */
    public static function unaccent(string $text): string
    {
        if (function_exists('remove_accents')) {
            return (string) remove_accents($text);
        }
        return $text;
    }

    /**
     * Replace invalid UTF-8 sequences so a pattern with the u flag never fails on them.
     */
    public static function scrub(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        return mb_scrub($text, 'UTF-8');
    }

    /**
     * Cut text to a number of characters on a character boundary.
     *
     * @return array{0: string, 1: bool} The text and whether it was cut.
     */
    public static function window(string $text, int $chars): array
    {
        if ($chars < 1 || mb_strlen($text, 'UTF-8') <= $chars) {
            return [$text, false];
        }
        return [mb_substr($text, 0, $chars, 'UTF-8'), true];
    }

    /**
     * Up to $chars characters either side of a match, by character offset.
     *
     * @return array{0: string, 1: string}
     */
    public static function context(string $text, int $offset, int $length, int $chars = 60): array
    {
        $start = max(0, $offset - $chars);
        $before = mb_substr($text, $start, $offset - $start, 'UTF-8');
        $after = mb_substr($text, $offset + $length, $chars, 'UTF-8');
        return [$before, $after];
    }

    /**
     * Turn a byte offset in $text into a character offset.
     */
    public static function charOffset(string $text, int $byteOffset): int
    {
        return mb_strlen(substr($text, 0, $byteOffset), 'UTF-8');
    }
}
