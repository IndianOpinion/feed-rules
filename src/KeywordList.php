<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * Parses keyword lists given as text (one per line) or as an array of strings.
 *
 * This is the one place lists are normalised.
 */
final class KeywordList
{
    /**
     * @param string|array<mixed> $input
     * @return array{keywords: list<string>, lines: list<int>, duplicates: int}
     */
    public static function parse(string|array $input): array
    {
        $lines = is_string($input) ? preg_split('/\r\n|\r|\n/', $input) : array_values($input);
        if ($lines === false) {
            $lines = [];
        }
        $keywords = [];
        $lineNumbers = [];
        $seen = [];
        $duplicates = 0;
        foreach ($lines as $index => $line) {
            if (!is_string($line)) {
                continue;
            }
            $line = Text::plain($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $key = Text::fold($line);
            if (isset($seen[$key])) {
                $duplicates++;
                continue;
            }
            $seen[$key] = true;
            $keywords[] = $line;
            $lineNumbers[] = $index + 1;
        }
        return ['keywords' => $keywords, 'lines' => $lineNumbers, 'duplicates' => $duplicates];
    }

    /**
     * Why a keyword is not acceptable, or null.
     */
    public static function problem(string $keyword): ?string
    {
        $star = strpos($keyword, '*');
        if ($star === false) {
            return null;
        }
        if ($star !== strlen($keyword) - 1) {
            return 'a * is only allowed at the end of a keyword';
        }
        $stem = rtrim(substr($keyword, 0, -1));
        if ($stem === '' || $stem !== substr($keyword, 0, -1)) {
            return 'a trailing * needs a word directly before it';
        }
        return null;
    }
}
