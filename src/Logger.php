<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * Writes one-line messages to the PHP error log, prefixed "feed-rules".
 */
final class Logger
{
    /** @var (callable(string): void)|null */
    private static $sink = null;

    public static function log(string $message): void
    {
        $line = 'feed-rules ' . $message;
        if (self::$sink !== null) {
            (self::$sink)($line);
            return;
        }
        error_log($line);
    }

    /**
     * Send log lines somewhere else (tests), or pass null to restore error_log().
     *
     * @param (callable(string): void)|null $sink
     */
    public static function setSink(?callable $sink): void
    {
        self::$sink = $sink;
    }
}
