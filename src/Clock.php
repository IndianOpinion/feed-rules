<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * The one source of the current time, so tests can pin it.
 */
final class Clock
{
    private static ?int $fixed = null;

    public static function now(): int
    {
        return self::$fixed ?? time();
    }

    /**
     * Pin the clock to a Unix time, or pass null to release it.
     */
    public static function set(?int $time): void
    {
        self::$fixed = $time;
    }
}
