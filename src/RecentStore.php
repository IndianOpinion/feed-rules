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
        ];
        $row = [
            'importer_id' => $importerId,
            'url' => $item->url(),
            'url_hash' => sha1($item->url()),
            'source_key' => $item->sourceKey(),
            'seen_at' => Clock::now(),
            'sampled' => $sampled ? 1 : 0,
            'data' => (string) json_encode(
                ['fields' => $fields, 'decision' => $decision->toArray()],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ),
        ];
        try {
            $this->storage->upsert($row);
            $this->storage->prune($importerId, $this->settings->recentLimit());
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
            $this->storage->latest($limit, $importerId, $sourceKey)
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
            return $this->storage->count($importerId);
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

    public function lastError(): string
    {
        return $this->lastError;
    }
}
