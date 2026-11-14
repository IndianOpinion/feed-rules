<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Adapter\CoreFeedAdapter;
use IndianOpinion\FeedRules\Adapter\DroppedKeys;
use IndianOpinion\FeedRules\Adapter\UrlKey;
use IndianOpinion\FeedRules\Engine;
use IndianOpinion\FeedRules\Plugin;
use IndianOpinion\FeedRules\Store\MemoryStorage;
use IndianOpinion\FeedRules\Tests\TestCase;

final class CoreFeedAdapterTest extends TestCase
{
    private const FEED = 'https://feeds.example.com/news.xml';

    private CoreFeedAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new CoreFeedAdapter($this->settings);
        $this->registry->add($this->adapter);
        add_filter('feed_rules_filter_item', fn ($item, $importer = '') => $this->engine->filter($item, $importer), 10, 2);
        add_action('feed_rules_apply_to_post', function ($postId, $item = []): void {
            $this->engine->applyAction($postId, $item);
        }, 10, 2);
        $this->settings->update(['feeds' => [['url' => self::FEED, 'label' => 'Example', 'category' => 4, 'author' => 0]]]);
        \WpStub::addTerm(4, 'News', 'category');
        \WpStub::addTerm(8, 'Mapped', 'category');
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private static function feed(array $entries, string $url = self::FEED): void
    {
        \WpStub::$feeds[$url] = new \SimplePie(array_map(static fn (array $e): \SimplePie_Item => new \SimplePie_Item($e), $entries));
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(int $n, string $title = '', int $date = 0): array
    {
        return [
            'link' => 'https://example.com/story/' . $n,
            'title' => $title !== '' ? $title : 'Story ' . $n,
            'content' => '<p>Body ' . $n . '</p>',
            'date' => $date > 0 ? $date : 1_763_000_000 + $n,
        ];
    }

    /** Case 46. */
    public function testRunInsertsKeptItemsAsDraftsWithMapping(): void
    {
        $this->saveRules(
            self::rule('exclude', [self::kw('horoscope')]),
            self::rule('categorise', [self::kw('budget')], ['categories' => [8]])
        );
        self::feed([self::entry(1, 'Budget day'), self::entry(2, 'Your horoscope'), self::entry(3, 'Weather')]);
        $report = $this->adapter->run();
        self::assertSame(2, $report['inserted']);
        self::assertSame(1, $report['dropped']);
        self::assertCount(2, \WpStub::$posts);
        foreach (\WpStub::$posts as $post) {
            self::assertSame('draft', $post['post_status']);
        }
        $budget = array_search('Budget day', array_column(\WpStub::$posts, 'post_title'), true);
        $id = array_keys(\WpStub::$posts)[(int) $budget];
        self::assertSame([4, 8], \WpStub::$postTerms[$id]['category']);
        self::assertArrayHasKey(Engine::POST_META, \WpStub::$meta[$id]);
    }

    /** Case 47. */
    public function testExistingAndDroppedKeysAreSkipped(): void
    {
        self::assertSame('https://example.com/a?id=2', UrlKey::make('https://WWW.Example.com/a?utm_source=x&id=2&utm_medium=y#frag'));
        update_post_meta(5, CoreFeedAdapter::META_URL_KEY, UrlKey::make('https://example.com/story/1'));
        $this->saveRules(self::rule('exclude', [self::kw('drop me')]));
        $entry = self::entry(1);
        $entry['link'] = 'https://www.example.com/story/1?utm_source=feed#top';
        self::feed([$entry, self::entry(2, 'drop me')]);
        $first = $this->adapter->run();
        self::assertSame(1, $first['skipped_existing']);
        self::assertSame(1, $first['dropped']);
        self::assertSame(0, $first['inserted']);
        $recorded = $this->storage->count();

        $second = $this->adapter->run();
        self::assertSame(1, $second['skipped_seen']);
        self::assertSame(0, $second['dropped']);
        self::assertSame($recorded, $this->storage->count());

        $keys = new DroppedKeys();
        for ($i = 0; $i < 5100; $i++) {
            $keys->add('https://example.com/k' . $i);
        }
        $keys->save();
        self::assertSame(5000, (new DroppedKeys())->count());
    }

    /** Case 48. */
    public function testOneFailingFeedDoesNotStopTheRun(): void
    {
        $other = 'https://feeds.example.com/other.xml';
        $this->settings->update(['feeds' => [['url' => self::FEED], ['url' => $other]]]);
        \WpStub::$feeds[self::FEED] = new \WP_Error('http', 'timed out');
        self::feed([self::entry(1)], $other);
        $report = $this->adapter->run();
        self::assertSame(1, $report['failed_feeds']);
        self::assertSame(1, $report['inserted']);
        $status = $this->settings->get('feed_status');
        self::assertIsArray($status);
        self::assertSame('timed out', $status[UrlKey::make(self::FEED)]['error']);
    }

    /** Case 49. */
    public function testLinklessTitlelessAndEmptyEntries(): void
    {
        self::feed([
            ['title' => 'No link', 'content' => 'x'],
            ['link' => 'https://example.com/t', 'title' => '', 'content' => '<p>one two three four five six seven eight nine ten eleven</p>'],
            ['link' => 'https://example.com/e', 'title' => '', 'content' => ''],
        ]);
        $report = $this->adapter->run();
        self::assertSame(1, $report['skipped_no_link']);
        self::assertSame(1, $report['skipped_empty']);
        self::assertSame(1, $report['inserted']);
        self::assertSame('one two three four five six seven eight nine ten', array_values(\WpStub::$posts)[0]['post_title']);
    }

    /** Case 50. */
    public function testMaximumPerRunTakesTheNewest(): void
    {
        $entries = [];
        for ($i = 1; $i <= 30; $i++) {
            $entries[] = self::entry($i);
        }
        shuffle($entries);
        self::feed($entries);
        self::assertSame(20, $this->adapter->run()['inserted']);
        $titles = array_column(\WpStub::$posts, 'post_title');
        self::assertContains('Story 30', $titles);
        self::assertContains('Story 11', $titles);
        self::assertNotContains('Story 10', $titles);
    }

    /** Case 51. */
    public function testCacheLifetimeFilterIsScopedToTheCall(): void
    {
        $seen = null;
        $adapter = new CoreFeedAdapter($this->settings, static function (string $url) use (&$seen) {
            $seen = apply_filters('wp_feed_cache_transient_lifetime', 43200, $url);
            return new \SimplePie([]);
        });
        $adapter->run();
        self::assertSame(3540, $seen);
        self::assertSame(43200, apply_filters('wp_feed_cache_transient_lifetime', 43200, self::FEED));
    }

    /** Case 52. */
    public function testLockedRunExits(): void
    {
        set_transient(CoreFeedAdapter::LOCK, 1, 600);
        $called = false;
        $adapter = new CoreFeedAdapter($this->settings, static function () use (&$called) {
            $called = true;
            return new \SimplePie([]);
        });
        self::assertTrue($adapter->run()['locked']);
        self::assertFalse($called);
        self::assertContains('feed-rules core-feed run skipped: another run holds the lock', $this->log);
        delete_transient(CoreFeedAdapter::LOCK);
        $adapter->run();
        self::assertFalse(get_transient(CoreFeedAdapter::LOCK), 'lock released');
    }

    /** Case 53. */
    public function testInsertSanitises(): void
    {
        self::feed([[
            'link' => 'https://example.com/s',
            'title' => 'Hello <b>world</b>',
            'content' => '<p>Text</p><script>alert(1)</script>',
            'date' => 1_763_000_000,
        ]]);
        $this->adapter->run();
        $id = array_key_first(\WpStub::$posts);
        self::assertSame('Hello world', \WpStub::$posts[$id]['post_title']);
        self::assertStringNotContainsString('script', \WpStub::$posts[$id]['post_content']);
        self::assertSame('https://example.com/s', \WpStub::$meta[$id][CoreFeedAdapter::META_SOURCE_URL]);
    }

    public function testStatusIsDraftOrPendingOnly(): void
    {
        $this->settings->update(['feeds' => [['url' => self::FEED, 'status' => 'publish']]]);
        self::assertSame('draft', $this->adapter->feeds()[0]['status']);
        add_filter('feed_rules_core_feed_post_status', static fn (): string => 'publish');
        self::feed([self::entry(1)]);
        $this->adapter->run();
        self::assertSame('draft', array_values(\WpStub::$posts)[0]['post_status']);
    }

    /** Case 41. */
    public function testSamplingFillsTheStore(): void
    {
        self::feed([self::entry(1), self::entry(2)]);
        global $wpdb;
        $wpdb = null;
        $plugin = Plugin::instance();
        self::assertSame(0, $plugin->sample('core-feed'));
        $this->adapter = new CoreFeedAdapter($this->settings);
        $items = $this->adapter->sample(10);
        self::assertCount(2, $items);
        foreach ($items as $item) {
            $this->store->record($item, 'core-feed', $this->engine->preview($item, 'core-feed'), null, true);
        }
        $latest = $this->store->latest();
        self::assertCount(2, $latest);
        self::assertTrue($latest[0]->sampled);
        self::assertSame([], \WpStub::$posts);
        self::assertInstanceOf(MemoryStorage::class, $this->storage);
    }

    public function testScheduleOneEventPerInterval(): void
    {
        $this->settings->update(['feeds' => [['url' => self::FEED], ['url' => self::FEED . '?b', 'interval' => 'daily']]]);
        $this->adapter->schedule();
        self::assertNotFalse(wp_next_scheduled(CoreFeedAdapter::CRON_HOOK, ['hourly']));
        self::assertNotFalse(wp_next_scheduled(CoreFeedAdapter::CRON_HOOK, ['daily']));
        $this->settings->update(['feeds' => [['url' => self::FEED]]]);
        $this->adapter->schedule();
        self::assertFalse(wp_next_scheduled(CoreFeedAdapter::CRON_HOOK, ['daily']));
        Plugin::deactivate();
        self::assertFalse(wp_next_scheduled(CoreFeedAdapter::CRON_HOOK, ['hourly']));
    }
}
