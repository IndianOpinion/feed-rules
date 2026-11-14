<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Tests\Unit;

use IndianOpinion\FeedRules\Clock;
use IndianOpinion\FeedRules\Exception\StoreUnavailableException;
use IndianOpinion\FeedRules\RecentStore;
use IndianOpinion\FeedRules\Store\WpdbStorage;
use IndianOpinion\FeedRules\Tester;
use IndianOpinion\FeedRules\Tests\TestCase;

final class RecentStoreTest extends TestCase
{
    /** Case 38 and ruling 5: the cap is per importer. */
    public function testKeepsLatestPerImporterWithClampedLimit(): void
    {
        $this->saveRules(self::rule('exclude', [self::kw('odd')]));
        for ($i = 0; $i < 105; $i++) {
            Clock::set(1_763_100_000 + $i);
            $this->engine->filter(['url' => 'https://example.com/' . $i, 'title' => $i % 2 ? 'odd' : 'even'], 'imp');
        }
        $this->engine->filter(['url' => 'https://example.com/other', 'title' => 'even'], 'other');
        self::assertSame(100, $this->storage->count('imp'));
        self::assertSame(1, $this->storage->count('other'));
        $latest = $this->store->latest(100, 'imp');
        self::assertSame('https://example.com/104', $latest[0]->url);
        self::assertSame('https://example.com/5', $latest[99]->url);
        self::assertTrue($latest[0]->decision->isKept());
        self::assertFalse($latest[1]->decision->isKept());
        self::assertSame('odd', $latest[1]->decision->reasons()[0]->matched);

        add_filter('feed_rules_recent_limit', static fn (): int => 5);
        self::assertSame(20, $this->settings->recentLimit());
        add_filter('feed_rules_recent_limit', static fn (): int => 5000, 20);
        self::assertSame(1000, $this->settings->recentLimit());
    }

    /** Case 39. */
    public function testSameUrlUpdatesItsRow(): void
    {
        $this->engine->filter(['url' => 'https://example.com/a', 'title' => 'one'], 'imp');
        Clock::set(1_763_200_000);
        $this->engine->filter(['url' => 'https://example.com/a', 'title' => 'one'], 'imp');
        self::assertSame(1, $this->storage->count());
        self::assertSame(1_763_200_000, $this->store->latest()[0]->seenAt);
        $this->engine->filter(['url' => 'https://example.com/a', 'title' => 'one'], 'other');
        self::assertSame(2, $this->storage->count());
    }

    /** Case 56: a missing table is created on first record. */
    public function testMissingTableIsCreated(): void
    {
        $db = new \wpdb();
        $store = new RecentStore(new WpdbStorage($db), $this->settings);
        $store->record(self::item(), 'imp', $this->engine->evaluate(self::item(), 'imp', null, true));
        self::assertTrue($db->tableExists);
        self::assertNotEmpty(array_filter($db->queries, static fn (string $q): bool => str_starts_with($q, 'CREATE TABLE IF NOT EXISTS wp_feed_rules_recent')));
        self::assertNotEmpty(array_filter($db->queries, static fn (string $q): bool => str_contains($q, 'ON DUPLICATE KEY UPDATE')));
    }

    /** Case 56: creation fails, filtering still works, the test screen reports it. */
    public function testFailedCreationFailsOpen(): void
    {
        $db = new \wpdb();
        $db->canCreate = false;
        $storage = new WpdbStorage($db);
        $store = new RecentStore($storage, $this->settings);
        $engine = new \IndianOpinion\FeedRules\Engine($this->repository, $this->settings, $this->counters, $store, $this->registry);
        $this->saveRules(self::rule('exclude', [self::kw('x')]));
        self::assertFalse($engine->filter(['url' => 'https://example.com/a', 'title' => 'x'], 'imp'));
        self::assertIsArray($engine->filter(['url' => 'https://example.com/b', 'title' => 'y'], 'imp'));
        $creates = array_filter($db->queries, static fn (string $q): bool => str_starts_with($q, 'CREATE'));
        self::assertCount(1, $creates, 'tried once per request');
        self::assertFalse($store->isAvailable());

        $report = (new Tester($engine, $store))->run($this->repository->load());
        self::assertNotSame('', $report->storeError);
        $this->expectException(StoreUnavailableException::class);
        $store->latest();
    }

    public function testPruneAll(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->storage->upsert([
                'importer_id' => 'imp', 'url' => 'https://example.com/' . $i, 'url_hash' => sha1((string) $i),
                'source_key' => '', 'seen_at' => $i, 'sampled' => 0, 'data' => '{}',
            ]);
        }
        $this->settings->update(['recent_limit' => 20]);
        self::assertSame(10, $this->store->prune());
    }

    /** A source key or importer id wider than its column is cut there and kept in full in the row data. */
    public function testLongSourceKeyAndImporterRoundTrip(): void
    {
        $key = 'https://example.com/' . str_repeat('feed/', 60);
        $importer = str_repeat('i', 50);
        $this->engine->filter(['url' => 'https://example.com/a', 'title' => 'one', 'source_key' => $key], $importer);
        $rows = $this->storage->latest(10, null, null);
        self::assertSame(RecentStore::SOURCE_KEY_WIDTH, mb_strlen((string) $rows[0]['source_key']));
        self::assertSame(RecentStore::IMPORTER_WIDTH, mb_strlen((string) $rows[0]['importer_id']));
        $latest = $this->store->latest(10, $importer, $key);
        self::assertCount(1, $latest);
        self::assertSame($key, $latest[0]->sourceKey);
        self::assertSame($importer, $latest[0]->importerId);
        self::assertSame($key, $latest[0]->toItem()->sourceKey());
        self::assertSame(1, $this->store->count($importer));
    }
}
