<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

use IndianOpinion\FeedRules\Exception\StoreUnavailableException;
use IndianOpinion\FeedRules\Store\RecentStorage;

/**
 * The last N evaluated items per importer, with their verdicts and reasons.
 */
final class RecentStore
{
    /** Widths of the importer id and source key columns, in characters. */
    public const IMPORTER_WIDTH = 40;
    public const SOURCE_KEY_WIDTH = 191;

    private bool $unavailable = false;
    private string $lastError = '';

    public function __construct(private RecentStorage $storage, private Settings $settings)
    {
    }

    /**
     * Record an evaluated item. Never throws: a broken store stops recording
     * for the rest of the request and filtering carries on.
     */
    public function record(
        Item $item,
        string $importerId,
        Decision $decision,
        ?FieldTexts $texts = null,
        bool $sampled = false
    ): void {
        if ($this->unavailable) {
            return;
        }
        $texts ??= new FieldTexts($item, $this->settings->bodyWindow());
        $fields = $texts->stored() + [
            'categories' => $item->categories(),
            'tags' => $item->tags(),
            'published' => $item->published(),
            'guid' => $item->guid(),
            // Full values: the columns below are cut to their widths.
            'source_key' => $item->sourceKey(),
            'importer' => $importerId,
        ];
        $row = [
            'importer_id' => self::column($importerId, self::IMPORTER_WIDTH),
            'url' => $item->url(),
            'url_hash' => sha1($item->url()),
            'source_key' => self::column($item->sourceKey(), self::SOURCE_KEY_WIDTH),
            'seen_at' => Clock::now(),
            'sampled' => $sampled ? 1 : 0,
            'data' => (string) json_encode(
                ['fields' => $fields, 'decision' => $decision->toArray()],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ),
        ];
        try {
            $this->storage->upsert($row);
            $this->storage->prune($row['importer_id'], $this->settings->recentLimit());
        } catch (StoreUnavailableException $e) {
            $this->unavailable = true;
            $this->lastError = $e->getMessage();
            Logger::log('error ' . $e->getMessage());
        }
    }

    /**
     * Newest first.
     *
     * @return list<RecordedItem>
     * @throws StoreUnavailableException
     */
    public function latest(int $limit = 100, ?string $importerId = null, ?string $sourceKey = null): array
    {
        return array_map(
            [RecordedItem::class, 'fromRow'],
            $this->storage->latest(
                $limit,
                $importerId === null ? null : self::column($importerId, self::IMPORTER_WIDTH),
                $sourceKey === null ? null : self::column($sourceKey, self::SOURCE_KEY_WIDTH)
            )
        );
    }

    /**
     * Prune every importer to the configured limit. Returns rows deleted.
     */
    public function prune(): int
    {
        $deleted = 0;
        foreach ($this->storage->importers() as $importerId) {
            $deleted += $this->storage->prune($importerId, $this->settings->recentLimit());
        }
        return $deleted;
    }

    public function count(?string $importerId = null): int
    {
        try {
            $this->storage->ensure();
            return $this->storage->count($importerId === null ? null : self::column($importerId, self::IMPORTER_WIDTH));
        } catch (StoreUnavailableException) {
            return 0;
        }
    }

    public function isAvailable(): bool
    {
        if ($this->unavailable) {
            return false;
        }
        try {
            $this->storage->ensure();
            return true;
        } catch (StoreUnavailableException $e) {
            $this->unavailable = true;
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * A value cut to a column width on a character boundary, so a long feed URL
     * used as a source key cannot make the insert fail under strict SQL modes.
     */
    public static function column(string $value, int $width): string
    {
        return mb_strlen($value, 'UTF-8') > $width ? mb_substr($value, 0, $width, 'UTF-8') : $value;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }
}
