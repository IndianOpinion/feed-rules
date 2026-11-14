<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Adapter;

use IndianOpinion\FeedRules\Adapter;
use IndianOpinion\FeedRules\Clock;
use IndianOpinion\FeedRules\Engine;
use IndianOpinion\FeedRules\Item;
use IndianOpinion\FeedRules\Logger;
use IndianOpinion\FeedRules\Settings;

/**
 * Reference adapter: a minimal importer built on WordPress core's fetch_feed().
 *
 * It reads each configured feed on a WP-Cron interval, passes every entry
 * through the entry filter, inserts a draft for each kept item and then
 * fires the apply action.
 */
final class CoreFeedAdapter implements Adapter
{
    public const ID = 'core-feed';
    public const CRON_HOOK = 'feed_rules_core_feed_run';
    public const LOCK = 'feed_rules_core_feed_lock';
    public const LOCK_TTL = 600;
    public const META_SOURCE_URL = '_feed_rules_source_url';
    public const META_URL_KEY = '_feed_rules_url_key';

    public const INTERVALS = ['hourly' => 3600, 'twicedaily' => 43200, 'daily' => 86400];
    public const STATUSES = ['draft', 'pending'];

    /** @var callable(string): mixed */
    private $fetcher;

    /**
     * @param (callable(string): mixed)|null $fetcher Defaults to fetch_feed().
     */
    public function __construct(private Settings $settings, ?callable $fetcher = null)
    {
        $this->fetcher = $fetcher ?? static fn (string $url): mixed => fetch_feed($url);
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'WordPress core feeds';
    }

    public function boot(): void
    {
        // The cron hook is registered by the main plugin file, so nothing to hook here.
    }

    public function sources(): array
    {
        $out = [];
        foreach ($this->feeds() as $feed) {
            $out[$feed['key']] = $feed['label'];
        }
        return $out;
    }

    public function defaultAuthor(string $sourceKey): ?int
    {
        foreach ($this->feeds() as $feed) {
            if ($feed['key'] === $sourceKey && $feed['author'] > 0) {
                return $feed['author'];
            }
        }
        return null;
    }

    public function supportsSampling(): bool
    {
        return true;
    }

    public function sample(int $limit): array
    {
        $items = [];
        foreach ($this->feeds() as $feed) {
            $entries = $this->read($feed);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($this->candidates($entries, $feed)['items'] as $candidate) {
                $items[] = $candidate['item'];
            }
        }
        usort($items, static fn (Item $a, Item $b): int => ($b->published() ?? 0) <=> ($a->published() ?? 0));
        return array_slice($items, 0, max(0, $limit));
    }

    /**
     * The configured feeds, normalised.
     *
     * @return list<array{url: string, key: string, label: string, author: int, category: int,
     *     status: string, interval: string, max: int}>
     */
    public function feeds(): array
    {
        $out = [];
        $raw = $this->settings->get('feeds');
        foreach (is_array($raw) ? $raw : [] as $feed) {
            $feed = self::normaliseFeed(is_array($feed) ? $feed : []);
            if ($feed !== null) {
                $out[] = $feed;
            }
        }
        return $out;
    }

    /**
     * Validate one feed row from a form or the option. Null when the URL is not usable.
     *
     * @param array<mixed> $feed
     * @return array{url: string, key: string, label: string, author: int, category: int,
     *     status: string, interval: string, max: int}|null
     */
    public static function normaliseFeed(array $feed): ?array
    {
        $url = is_string($feed['url'] ?? null) ? trim($feed['url']) : '';
        if ($url === '' || !Item::isAbsoluteHttpUrl($url) || wp_http_validate_url($url) === false) {
            return null;
        }
        $status = is_string($feed['status'] ?? null) && in_array($feed['status'], self::STATUSES, true)
            ? $feed['status'] : 'draft';
        $interval = is_string($feed['interval'] ?? null) && isset(self::INTERVALS[$feed['interval']])
            ? $feed['interval'] : 'hourly';
        $max = is_numeric($feed['max'] ?? null) ? (int) $feed['max'] : 20;
        $label = is_string($feed['label'] ?? null) ? sanitize_text_field($feed['label']) : '';
        return [
            'url' => $url,
            'key' => UrlKey::make($url),
            'label' => $label === '' ? $url : $label,
            'author' => is_numeric($feed['author'] ?? null) ? max(0, (int) $feed['author']) : 0,
            'category' => is_numeric($feed['category'] ?? null) ? max(0, (int) $feed['category']) : 0,
            'status' => $status,
            'interval' => $interval,
            'max' => min(200, max(1, $max)),
        ];
    }

    /**
     * Keep one cron event per interval in use, and none for intervals not in use.
     */
    public function schedule(): void
    {
        $used = array_unique(array_map(static fn (array $f): string => $f['interval'], $this->feeds()));
        foreach (array_keys(self::INTERVALS) as $interval) {
            $next = wp_next_scheduled(self::CRON_HOOK, [$interval]);
            if (in_array($interval, $used, true) && $next === false) {
                wp_schedule_event(Clock::now() + 60, $interval, self::CRON_HOOK, [$interval]);
            } elseif (!in_array($interval, $used, true) && $next !== false) {
                wp_unschedule_event($next, self::CRON_HOOK, [$interval]);
            }
        }
    }

    /**
     * One run over the feeds on $interval, or every feed when null.
     *
     * @return array{locked: bool, inserted: int, dropped: int, skipped_existing: int, skipped_seen: int,
     *     skipped_no_link: int, skipped_empty: int, failed_feeds: int}
     */
    public function run(?string $interval = null): array
    {
        $report = [
            'locked' => false, 'inserted' => 0, 'dropped' => 0, 'skipped_existing' => 0, 'skipped_seen' => 0,
            'skipped_no_link' => 0, 'skipped_empty' => 0, 'failed_feeds' => 0,
        ];
        if (get_transient(self::LOCK) !== false) {
            Logger::log('core-feed run skipped: another run holds the lock');
            $report['locked'] = true;
            return $report;
        }
        set_transient(self::LOCK, Clock::now(), self::LOCK_TTL);
        $status = $this->settings->get('feed_status');
        $status = is_array($status) ? $status : [];
        $dropped = new DroppedKeys();
        try {
            foreach ($this->feeds() as $feed) {
                if ($interval !== null && $feed['interval'] !== $interval) {
                    continue;
                }
                $entries = $this->read($feed);
                if (!is_array($entries)) {
                    $status[$feed['key']] = ['last_run' => Clock::now(), 'error' => $entries, 'error_time' => Clock::now()];
                    $report['failed_feeds']++;
                    Logger::log(sprintf('error core-feed feed=%s: %s', $feed['url'], $entries));
                    continue;
                }
                $status[$feed['key']] = ['last_run' => Clock::now(), 'error' => '', 'error_time' => 0];
                $candidates = $this->candidates($entries, $feed);
                $report['skipped_no_link'] += $candidates['no_link'];
                $report['skipped_empty'] += $candidates['empty'];
                $inserted = 0;
                foreach ($candidates['items'] as $candidate) {
                    if ($inserted >= $feed['max']) {
                        break;
                    }
                    $key = $candidate['key'];
                    if ($this->postExists($key)) {
                        $report['skipped_existing']++;
                        continue;
                    }
                    if ($dropped->has($key)) {
                        $report['skipped_seen']++;
                        continue;
                    }
                    $data = $candidate['item']->toArray();
                    $result = apply_filters('feed_rules_filter_item', $data, self::ID);
                    if ($result === false) {
                        $dropped->add($key);
                        $report['dropped']++;
                        continue;
                    }
                    if (!is_array($result)) {
                        $result = $data;
                    }
                    $postId = $this->insert($result, $candidate['item'], $feed, $key);
                    if ($postId > 0) {
                        $inserted++;
                        $report['inserted']++;
                        do_action('feed_rules_apply_to_post', $postId, $result);
                    }
                }
            }
        } finally {
            $dropped->save();
            // Re-read before writing, so feeds saved by an operator during the run are not overwritten.
            $this->settings->forget();
            $this->settings->update(['feed_status' => $status]);
            delete_transient(self::LOCK);
        }
        return $report;
    }

    /**
     * Read one feed. Returns entry arrays, or the error text.
     *
     * @param array{url: string, interval: string} $feed
     * @return list<array{link: string, title: string, content: string, date: int, id: string,
     *     author: string, categories: list<string>}>|string
     */
    public function read(array $feed): array|string
    {
        $lifetime = max(60, (self::INTERVALS[$feed['interval']] ?? 3600) - 60);
        $filter = static fn (): int => $lifetime;
        add_filter('wp_feed_cache_transient_lifetime', $filter, 99);
        try {
            $result = ($this->fetcher)($feed['url']);
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            remove_filter('wp_feed_cache_transient_lifetime', $filter, 99);
        }
        if ($result instanceof \WP_Error) {
            return $result->get_error_message();
        }
        if (!is_object($result) || !method_exists($result, 'get_items')) {
            return 'fetch_feed() returned something that is not a feed';
        }
        $entries = [];
        $items = $result->get_items();
        foreach (is_array($items) ? $items : [] as $entry) {
            if (is_object($entry)) {
                $entries[] = self::entry($entry);
            }
        }
        return $entries;
    }

    /**
     * Turn entries into items: skip linkless and empty ones, fill missing
     * titles, order newest first then by feed order.
     *
     * @param list<array{link: string, title: string, content: string, date: int, id: string,
     *     author: string, categories: list<string>}> $entries
     * @param array{key: string, label: string} $feed
     * @return array{items: list<array{item: Item, key: string}>, no_link: int, empty: int}
     */
    public function candidates(array $entries, array $feed): array
    {
        $noLink = 0;
        $empty = 0;
        $rows = [];
        foreach ($entries as $order => $entry) {
            $link = trim($entry['link']);
            if ($link === '' || !Item::isAbsoluteHttpUrl($link)) {
                $noLink++;
                continue;
            }
            $title = trim(sanitize_text_field($entry['title']));
            $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($entry['content'])));
            if ($title === '' && $text === '') {
                $empty++;
                continue;
            }
            if ($title === '') {
                $title = implode(' ', array_slice(explode(' ', $text), 0, 10));
            }
            $rows[] = [
                'order' => $order,
                'date' => $entry['date'],
                'key' => UrlKey::make($link),
                'item' => Item::fromArray([
                    'url' => $link,
                    'title' => $title,
                    'body' => $entry['content'],
                    'source_name' => $feed['label'],
                    'source_key' => $feed['key'],
                    'author' => $entry['author'],
                    'categories' => $entry['categories'],
                    'published' => $entry['date'] > 0 ? $entry['date'] : null,
                    'guid' => $entry['id'],
                    'importer' => self::ID,
                ]),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['date'], $a['order']] <=> [$a['date'], $b['order']]);
        return [
            'items' => array_map(static fn (array $r): array => ['item' => $r['item'], 'key' => $r['key']], $rows),
            'no_link' => $noLink,
            'empty' => $empty,
        ];
    }

    /**
     * @return array{link: string, title: string, content: string, date: int, id: string,
     *     author: string, categories: list<string>}
     */
    private static function entry(object $entry): array
    {
        $call = static function (object $o, string $method, mixed ...$args): mixed {
            return method_exists($o, $method) ? $o->{$method}(...$args) : null;
        };
        $str = static fn (mixed $v): string => is_string($v) ? $v : '';
        $author = $call($entry, 'get_author');
        $categories = [];
        $cats = $call($entry, 'get_categories');
        foreach (is_array($cats) ? $cats : [] as $cat) {
            if (!is_object($cat)) {
                continue;
            }
            $label = $str($call($cat, 'get_label'));
            if ($label === '') {
                $label = $str($call($cat, 'get_term'));
            }
            if ($label !== '') {
                $categories[] = $label;
            }
        }
        $date = $call($entry, 'get_date', 'U');
        return [
            'link' => $str($call($entry, 'get_permalink')),
            'title' => html_entity_decode($str($call($entry, 'get_title')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'content' => $str($call($entry, 'get_content')),
            'date' => is_numeric($date) ? (int) $date : 0,
            'id' => $str($call($entry, 'get_id')),
            'author' => is_object($author) ? $str($call($author, 'get_name')) : '',
            'categories' => $categories,
        ];
    }

    private function postExists(string $key): bool
    {
        $found = get_posts([
            'post_type' => 'post',
            // 'any' leaves out trash: an item an editor trashed must not come back as a new draft.
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
            'meta_key' => self::META_URL_KEY,
            'meta_value' => $key,
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);
        return $found !== [];
    }

    /**
     * @param array<mixed> $result The array the entry filter returned.
     * @param array{key: string, author: int, category: int, status: string} $feed
     */
    private function insert(array $result, Item $item, array $feed, string $key): int
    {
        $decision = is_array($result[Engine::DECISION_KEY] ?? null) ? $result[Engine::DECISION_KEY] : [];
        $author = is_numeric($decision['author'] ?? null) ? (int) $decision['author'] : $feed['author'];
        $status = apply_filters('feed_rules_core_feed_post_status', $feed['status'], $feed['key']);
        $status = is_string($status) && in_array($status, self::STATUSES, true) ? $status : 'draft';
        $postarr = [
            'post_type' => 'post',
            'post_status' => $status,
            'post_title' => sanitize_text_field($item->title()),
            'post_content' => wp_kses_post($item->body()),
            'post_author' => $author,
        ];
        if ($feed['category'] > 0) {
            $postarr['post_category'] = [$feed['category']];
        }
        if ($item->published() !== null) {
            $postarr['post_date_gmt'] = gmdate('Y-m-d H:i:s', $item->published());
        }
        $filtered = apply_filters('feed_rules_core_feed_post_data', $postarr, $item);
        $postarr = is_array($filtered) ? $filtered : $postarr;
        if (!in_array($postarr['post_status'] ?? 'draft', self::STATUSES, true)) {
            $postarr['post_status'] = 'draft';
        }
        $postId = wp_insert_post($postarr, true);
        if ($postId instanceof \WP_Error) {
            Logger::log(sprintf('error core-feed insert %s: %s', $item->url(), $postId->get_error_message()));
            return 0;
        }
        update_post_meta($postId, self::META_SOURCE_URL, esc_url_raw($item->url()));
        update_post_meta($postId, self::META_URL_KEY, $key);
        return $postId;
    }
}
