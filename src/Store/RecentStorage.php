<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Store;

use IndianOpinion\FeedRules\Exception\StoreUnavailableException;

/**
 * Where recent-item rows live. A row is an array with the keys importer_id,
 * url, url_hash, source_key, seen_at, sampled and data (a JSON string).
 */
interface RecentStorage
{
    /**
     * Make sure the storage exists.
     *
     * @throws StoreUnavailableException
     */
    public function ensure(): void;

    /**
     * Insert a row, or update the row with the same importer id and URL hash.
     *
     * @param array{importer_id: string, url: string, url_hash: string, source_key: string,
     *     seen_at: int, sampled: int, data: string} $row
     * @throws StoreUnavailableException
     */
    public function upsert(array $row): void;

    public function count(?string $importerId = null): int;

    /**
     * Delete all but the newest $keep rows of one importer. Returns rows deleted.
     */
    public function prune(string $importerId, int $keep): int;

    /**
     * Newest first.
     *
     * @return list<array<string, mixed>>
     * @throws StoreUnavailableException
     */
    public function latest(int $limit, ?string $importerId, ?string $sourceKey): array;

    /**
     * @return list<string>
     */
    public function importers(): array;
}
