<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * Validation and safe execution of operator-supplied regular expressions.
 */
final class RegexPattern
{
    public const MAX_LENGTH = 2000;
    public const ALLOWED_FLAGS = 'imsx';

    /**
     * Why a delimited pattern cannot be used, or null when it compiles.
     */
    public static function problem(string $pattern): ?string
    {
        if ($pattern === '') {
            return 'the pattern is empty';
        }
        if (strlen($pattern) > self::MAX_LENGTH) {
            return sprintf('the pattern is longer than %d characters', self::MAX_LENGTH);
        }
        $parts = self::split($pattern);
        if ($parts === null) {
            return 'the pattern needs delimiters, for example /word/i';
        }
        [, $flags] = $parts;
        $badFlags = $flags === '' ? '' : str_replace(str_split(self::ALLOWED_FLAGS), '', $flags);
        if ($badFlags !== '') {
            return sprintf('flag "%s" is not allowed; use only i, m, s and x', $badFlags);
        }
        $error = null;
        self::run(self::compiled($pattern), '', $error, true);
        return $error;
    }

    /**
     * The pattern as executed: the operator's pattern with the u flag added.
     */
    public static function compiled(string $pattern): string
    {
        return $pattern . 'u';
    }

    /**
     * Run a compiled pattern. Returns the match array, an empty array for no match,
     * or null on error with $error set. Never emits a PHP warning.
     *
     * @param-out string|null $error
     * @return array<int, array{0: string, 1: int}>|null
     */
    public static function run(string $compiled, string $subject, ?string &$error = null, bool $compileOnly = false): ?array
    {
        $error = null;
        $warning = null;
        set_error_handler(
            static function (int $no, string $message) use (&$warning): bool {
                $warning = preg_replace('/^preg_match\(\):\s*/', '', $message);
                return true;
            }
        );
        try {
            $result = preg_match($compiled, $subject, $matches, PREG_OFFSET_CAPTURE);
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            $error = is_string($warning) && $warning !== '' ? $warning : preg_last_error_msg();
            return null;
        }
        if ($compileOnly || $result === 0) {
            return [];
        }
        /** @var array<int, array{0: string, 1: int}> $matches */
        return $matches;
    }

    /**
     * @return array{0: string, 1: string}|null Body and flags.
     */
    private static function split(string $pattern): ?array
    {
        $open = $pattern[0];
        if (ctype_alnum($open) || $open === '\\' || ctype_space($open)) {
            return null;
        }
        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$open] ?? $open;
        $end = strrpos($pattern, $close);
        if ($end === false || $end === 0) {
            return null;
        }
        return [substr($pattern, 1, $end - 1), substr($pattern, $end + 1)];
    }
}
