<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * One test inside a rule: a keyword list, a regular expression or a list of URL prefixes.
 */
final class Condition
{
    public const TYPE_KEYWORDS = 'keywords';
    public const TYPE_REGEX = 'regex';
    public const TYPE_URL_PREFIX = 'url_prefix';
    public const TYPES = [self::TYPE_KEYWORDS, self::TYPE_REGEX, self::TYPE_URL_PREFIX];

    public const FIELDS = ['title', 'body', 'title_body', 'url', 'source_name', 'author', 'source_terms'];
    public const MAX_KEYWORDS = 10000;

    /**
     * @param list<string> $keywords
     * @param list<int> $keywordLines
     * @param list<string> $prefixes
     */
    private function __construct(
        public readonly string $type,
        public readonly string $field,
        public readonly array $keywords,
        public readonly array $keywordLines,
        public readonly int $duplicatesRemoved,
        public readonly string $mode,
        public readonly bool $wholeWords,
        public readonly bool $caseSensitive,
        public readonly bool $accentInsensitive,
        public readonly string $pattern,
        public readonly array $prefixes,
        public readonly bool $negate,
    ) {
    }

    /**
     * Build a condition from stored data or a form. Lenient: call validate() before saving.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : self::TYPE_KEYWORDS;
        $field = is_string($data['field'] ?? null) && $data['field'] !== '' ? $data['field'] : 'title_body';
        if ($type === self::TYPE_URL_PREFIX) {
            $field = 'url';
        }
        $rawKeywords = $data['keywords'] ?? [];
        $parsed = KeywordList::parse(is_string($rawKeywords) || is_array($rawKeywords) ? $rawKeywords : []);
        $rawPrefixes = $data['prefixes'] ?? [];
        $prefixes = KeywordList::parse(is_string($rawPrefixes) || is_array($rawPrefixes) ? $rawPrefixes : []);

        return new self(
            $type,
            $field,
            $parsed['keywords'],
            $parsed['lines'],
            $parsed['duplicates'],
            ($data['mode'] ?? 'any') === 'all' ? 'all' : 'any',
            self::flag($data, 'whole_words', true),
            self::flag($data, 'case_sensitive', false),
            self::flag($data, 'accent_insensitive', false),
            is_string($data['pattern'] ?? null) ? trim($data['pattern']) : '',
            array_map([UrlPrefix::class, 'normaliseRule'], $prefixes['keywords']),
            self::flag($data, 'negate', false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['type' => $this->type, 'field' => $this->field, 'negate' => $this->negate];
        if ($this->type === self::TYPE_KEYWORDS) {
            $out += [
                'keywords' => $this->keywords,
                'mode' => $this->mode,
                'whole_words' => $this->wholeWords,
                'case_sensitive' => $this->caseSensitive,
                'accent_insensitive' => $this->accentInsensitive,
            ];
        } elseif ($this->type === self::TYPE_REGEX) {
            $out += ['pattern' => $this->pattern, 'accent_insensitive' => $this->accentInsensitive];
        } else {
            $out += ['prefixes' => $this->prefixes];
        }
        return $out;
    }

    /**
     * @return list<ValidationError>
     */
    public function validate(string $ruleId, int $index): array
    {
        $path = 'condition ' . ($index + 1);
        $errors = [];
        if (!in_array($this->type, self::TYPES, true)) {
            return [new ValidationError($ruleId, $path, sprintf('unknown condition type "%s"', $this->type))];
        }
        if (!in_array($this->field, self::FIELDS, true)) {
            $errors[] = new ValidationError($ruleId, $path, sprintf('unknown field "%s"', $this->field));
        }
        if ($this->type === self::TYPE_KEYWORDS) {
            if ($this->keywords === []) {
                $errors[] = new ValidationError($ruleId, $path, 'the keyword list is empty');
            }
            if (count($this->keywords) > self::MAX_KEYWORDS) {
                $errors[] = new ValidationError(
                    $ruleId,
                    $path,
                    sprintf('%d keywords is more than the limit of %d', count($this->keywords), self::MAX_KEYWORDS)
                );
                return $errors;
            }
            foreach ($this->keywords as $i => $keyword) {
                $problem = KeywordList::problem($keyword);
                if ($problem !== null) {
                    $errors[] = new ValidationError(
                        $ruleId,
                        $path,
                        sprintf('line %d ("%s"): %s', $this->keywordLines[$i] ?? $i + 1, $keyword, $problem)
                    );
                }
            }
        } elseif ($this->type === self::TYPE_REGEX) {
            $problem = RegexPattern::problem($this->pattern);
            if ($problem !== null) {
                $errors[] = new ValidationError($ruleId, $path, 'regex: ' . $problem);
            }
        } else {
            if ($this->prefixes === []) {
                $errors[] = new ValidationError($ruleId, $path, 'the URL prefix list is empty');
            }
            foreach ($this->prefixes as $prefix) {
                if (!UrlPrefix::isValidRule($prefix)) {
                    $errors[] = new ValidationError($ruleId, $path, sprintf('"%s" is not a host or host/path', $prefix));
                }
            }
        }
        return $errors;
    }

    /**
     * A stable key for the compiled-pattern cache.
     */
    public function cacheKey(): string
    {
        return md5((string) json_encode($this->toArray()));
    }

    /**
     * @param array<mixed> $data
     */
    private static function flag(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        $value = $data[$key];
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $value;
    }
}
