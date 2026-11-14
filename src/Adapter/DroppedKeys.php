<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Adapter;

use IndianOpinion\FeedRules\Clock;

/**
 * URL keys of dropped items, remembered for 30 days, newest 5,000 kept.
 */
final class DroppedKeys
{
    public const OPTION = 'feed_rules_dropped_keys';
    public const MAX_KEYS = 5000;
    public const TTL = 30 * 86400;

    /** @var array<string, int> */
    private array $keys = [];
    private bool $dirty = false;

    public function __construct()
    {
        $stored = get_option(self::OPTION, []);
        foreach (is_array($stored) ? $stored : [] as $key => $time) {
            if (is_numeric($time)) {
                $this->keys[(string) $key] = (int) $time;
            }
        }
    }

    public function has(string $key): bool
    {
        $time = $this->keys[sha1($key)] ?? null;
        return $time !== null && $time > Clock::now() - self::TTL;
    }

    public function add(string $key): void
    {
        $this->keys[sha1($key)] = Clock::now();
        $this->dirty = true;
    }

    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * Prune and write once.
     */
    public function save(): void
    {
        $cutoff = Clock::now() - self::TTL;
        $before = count($this->keys);
        $this->keys = array_filter($this->keys, static fn (int $t): bool => $t > $cutoff);
        if (count($this->keys) > self::MAX_KEYS) {
            arsort($this->keys);
            $this->keys = array_slice($this->keys, 0, self::MAX_KEYS, true);
        }
        if ($this->dirty || count($this->keys) !== $before) {
            update_option(self::OPTION, $this->keys, false);
        }
        $this->dirty = false;
    }
}
