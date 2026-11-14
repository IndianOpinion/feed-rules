<?php

/**
 * Minimal WP-CLI stand-in for static analysis and tests.
 *
 * phpcs:disable
 */

declare(strict_types=1);

namespace {
    class WP_CLI
    {
        /** @var list<string> */
        public static array $lines = [];

        public static function line(string $message = ''): void
        {
            self::$lines[] = $message;
        }

        public static function success(string $message): void
        {
            self::$lines[] = 'Success: ' . $message;
        }

        public static function warning(string $message): void
        {
            self::$lines[] = 'Warning: ' . $message;
        }

        public static function error(string $message): void
        {
            self::$lines[] = 'Error: ' . $message;
        }

        public static function add_command(string $name, string $class): void
        {
        }
    }
}

namespace WP_CLI\Utils {
    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        foreach ($items as $item) {
            \WP_CLI::line(implode("\t", array_map('strval', array_map(
                static fn (string $f): string => is_scalar($item[$f] ?? '') ? (string) ($item[$f] ?? '') : '',
                $fields
            ))));
        }
    }
}
