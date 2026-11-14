<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * The result of one condition against one item.
 */
final class MatchResult
{
    public const MATCH = 'match';
    public const NONE = 'none';
    public const ERROR = 'error';

    private function __construct(
        public readonly string $status,
        public readonly string $field = '',
        public readonly string $matched = '',
        public readonly int $offset = -1,
        public readonly string $before = '',
        public readonly string $after = '',
        public readonly string $error = '',
    ) {
    }

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public static function error(string $field, string $message): self
    {
        return new self(self::ERROR, $field, '', -1, '', '', $message);
    }

    /**
     * A match found at a byte offset in $text.
     */
    public static function found(string $field, string $text, string $matched, int $byteOffset): self
    {
        $offset = Text::charOffset($text, $byteOffset);
        [$before, $after] = Text::context($text, $offset, mb_strlen($matched, 'UTF-8'));
        return new self(self::MATCH, $field, $matched, $offset, $before, $after);
    }

    /**
     * A match with no text to show (a negated condition, or a URL prefix).
     */
    public static function matchedWithout(string $field, string $matched = ''): self
    {
        return new self(self::MATCH, $field, $matched);
    }

    public function isMatch(): bool
    {
        return $this->status === self::MATCH;
    }

    public function isError(): bool
    {
        return $this->status === self::ERROR;
    }
}
