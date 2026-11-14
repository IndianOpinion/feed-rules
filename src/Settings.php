<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * The settings option: global switch, limits, mapping tables, tag switches
 * and the reference adapter's feed list. Not autoloaded.
 */
final class Settings
{
    public const OPTION = 'feed_rules_settings';

    public const DEFAULTS = [
        'enabled' => true,
        'body_window' => 2000,
        'recent_limit' => 100,
        'category_cap' => 10,
        'copy_source_tags' => false,
        'create_tags' => false,
        'remove_default_category' => false,
        'category_map' => [],
        'author_names' => [],
        'author_sources' => [],
        'feeds' => [],
        'feed_status' => [],
    ];

    public const RECENT_MIN = 20;
    public const RECENT_MAX = 1000;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
        }
        return $this->cache;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(array $changes): void
    {
        $this->cache = array_merge($this->all(), $changes);
        update_option(self::OPTION, $this->cache, false);
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    public function enabled(): bool
    {
        return (bool) apply_filters('feed_rules_enabled', (bool) $this->get('enabled'));
    }

    public function bodyWindow(): int
    {
        $window = apply_filters('feed_rules_body_window', $this->intValue('body_window', 2000));
        return max(1, is_numeric($window) ? (int) $window : 2000);
    }

    /**
     * Items kept in the recent-items store per importer, clamped to 20 to 1,000.
     */
    public function recentLimit(): int
    {
        $limit = apply_filters('feed_rules_recent_limit', $this->intValue('recent_limit', 100));
        $limit = is_numeric($limit) ? (int) $limit : 100;
        return min(self::RECENT_MAX, max(self::RECENT_MIN, $limit));
    }

    public function categoryCap(): int
    {
        return max(1, $this->intValue('category_cap', 10));
    }

    public static function capability(): string
    {
        $cap = apply_filters('feed_rules_capability', 'manage_options');
        return is_string($cap) && $cap !== '' ? $cap : 'manage_options';
    }

    /**
     * @return array<string, int>
     */
    public function intMap(string $key): array
    {
        $map = $this->get($key);
        $out = [];
        foreach (is_array($map) ? $map : [] as $name => $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $out[(string) $name] = (int) $id;
            }
        }
        return $out;
    }

    /**
     * Normalise a name for the category and author tables.
     */
    public static function mapKey(string $name): string
    {
        return Text::fold(Text::plain($name));
    }

    private function intValue(string $key, int $default): int
    {
        $value = $this->get($key);
        return is_numeric($value) ? (int) $value : $default;
    }
}
