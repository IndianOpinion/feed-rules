<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * Compiles conditions into patterns once per request and runs them.
 *
 * Word boundaries are defined by Unicode letter, mark and number classes,
 * never by \b, so that keywords in Indic scripts match as whole words.
 */
final class Matcher
{
    /** Byte length of one combined pattern before it is split. */
    public const CHUNK_BYTES = 24000;

    private const WORD = '[\p{L}\p{M}\p{N}]';

    /** @var array<string, list<string>> */
    private static array $cache = [];

    public static function match(Condition $condition, FieldTexts $texts): MatchResult
    {
        $result = match ($condition->type) {
            Condition::TYPE_KEYWORDS => self::keywords($condition, $texts),
            Condition::TYPE_REGEX => self::regex($condition, $texts),
            Condition::TYPE_URL_PREFIX => self::urlPrefix($condition, $texts),
            default => MatchResult::error($condition->field, sprintf('unknown condition type "%s"', $condition->type)),
        };
        if (!$condition->negate || $result->isError()) {
            return $result;
        }
        return $result->isMatch() ? MatchResult::none() : MatchResult::matchedWithout($condition->field);
    }

    /**
     * The compiled patterns of a keyword condition: combined chunks for `any`,
     * one pattern per keyword for `all`.
     *
     * @return list<string>
     */
    public static function keywordPatterns(Condition $condition): array
    {
        $key = $condition->cacheKey();
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $alternatives = [];
        foreach ($condition->keywords as $keyword) {
            $alternatives[] = self::alternative($keyword, $condition);
        }
        $patterns = $condition->mode === 'all'
            ? array_map(static fn (string $alt): string => '/' . $alt . '/u', $alternatives)
            : self::chunk($alternatives);
        return self::$cache[$key] = $patterns;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function keywords(Condition $condition, FieldTexts $texts): MatchResult
    {
        $text = $texts->get($condition->field, !$condition->caseSensitive, $condition->accentInsensitive);
        $patterns = self::keywordPatterns($condition);
        $first = null;
        foreach ($patterns as $pattern) {
            $error = null;
            $m = RegexPattern::run($pattern, $text, $error);
            if ($m === null) {
                return MatchResult::error($condition->field, (string) $error);
            }
            if ($m === []) {
                if ($condition->mode === 'all') {
                    return MatchResult::none();
                }
                continue;
            }
            $first ??= MatchResult::found($condition->field, $text, $m[0][0], $m[0][1]);
            if ($condition->mode === 'any') {
                return $first;
            }
        }
        return $first ?? MatchResult::none();
    }

    private static function regex(Condition $condition, FieldTexts $texts): MatchResult
    {
        $text = $texts->get($condition->field, false, $condition->accentInsensitive);
        $error = null;
        $m = RegexPattern::run(RegexPattern::compiled($condition->pattern), $text, $error);
        if ($m === null) {
            return MatchResult::error($condition->field, (string) $error);
        }
        if ($m === []) {
            return MatchResult::none();
        }
        return MatchResult::found($condition->field, $text, $m[0][0], $m[0][1]);
    }

    private static function urlPrefix(Condition $condition, FieldTexts $texts): MatchResult
    {
        $url = $texts->item()->url();
        foreach ($condition->prefixes as $prefix) {
            if (UrlPrefix::matches($prefix, $url)) {
                return MatchResult::matchedWithout('url', $prefix);
            }
        }
        return MatchResult::none();
    }

    private static function alternative(string $keyword, Condition $condition): string
    {
        $prefix = str_ends_with($keyword, '*');
        if ($prefix) {
            $keyword = substr($keyword, 0, -1);
        }
        if (!$condition->caseSensitive) {
            $keyword = Text::fold($keyword);
        }
        if ($condition->accentInsensitive) {
            $keyword = Text::unaccent($keyword);
        }
        $words = array_map(
            static fn (string $w): string => preg_quote($w, '/'),
            explode(' ', $keyword)
        );
        $body = implode('\s+', $words);
        if (!$condition->wholeWords) {
            return $body;
        }
        return '(?<!' . self::WORD . ')' . $body . ($prefix ? '' : '(?!' . self::WORD . ')');
    }

    /**
     * Combine alternatives into as few patterns as compile.
     *
     * @param list<string> $alternatives
     * @return list<string>
     */
    private static function chunk(array $alternatives): array
    {
        $chunks = [];
        $current = [];
        $bytes = 0;
        foreach ($alternatives as $alt) {
            if ($current !== [] && $bytes + strlen($alt) + 1 > self::CHUNK_BYTES) {
                array_push($chunks, ...self::compileGroup($current));
                $current = [];
                $bytes = 0;
            }
            $current[] = $alt;
            $bytes += strlen($alt) + 1;
        }
        if ($current !== []) {
            array_push($chunks, ...self::compileGroup($current));
        }
        return $chunks;
    }

    /**
     * One pattern for the group, or split in halves until each half compiles.
     *
     * @param list<string> $group
     * @return list<string>
     */
    private static function compileGroup(array $group): array
    {
        $pattern = '/(?:' . implode('|', $group) . ')/u';
        $error = null;
        RegexPattern::run($pattern, '', $error, true);
        if ($error === null || count($group) === 1) {
            return [$pattern];
        }
        $half = intdiv(count($group), 2);
        return array_merge(
            self::compileGroup(array_slice($group, 0, $half)),
            self::compileGroup(array_slice($group, $half))
        );
    }
}
