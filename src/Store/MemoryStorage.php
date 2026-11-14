<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Store;

/**
 * In-memory storage, for tests and for running the engine outside WordPress.
 */
final class MemoryStorage implements RecentStorage
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];
    private int $nextId = 1;

    public function ensure(): void
    {
    }

    public function upsert(array $row): void
    {
        $key = $row['importer_id'] . '|' . $row['url_hash'];
        $id = isset($this->rows[$key]) ? $this->rows[$key]['id'] : $this->nextId++;
        $this->rows[$key] = ['id' => $id] + $row;
    }

    public function count(?string $importerId = null): int
    {
        return count($this->filtered($importerId, null));
    }

    public function prune(string $importerId, int $keep): int
    {
        $rows = $this->filtered($importerId, null);
        $deleted = 0;
        foreach (array_slice($rows, $keep) as $row) {
            unset($this->rows[$row['importer_id'] . '|' . $row['url_hash']]);
            $deleted++;
        }
        return $deleted;
    }

    public function latest(int $limit, ?string $importerId, ?string $sourceKey): array
    {
        return array_slice($this->filtered($importerId, $sourceKey), 0, $limit);
    }

    public function importers(): array
    {
        $ids = [];
        foreach ($this->rows as $row) {
            $ids[(string) $row['importer_id']] = true;
        }
        return array_keys($ids);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filtered(?string $importerId, ?string $sourceKey): array
    {
        $rows = array_values(array_filter(
            $this->rows,
            static fn (array $r): bool => ($importerId === null || $r['importer_id'] === $importerId)
                && ($sourceKey === null || $r['source_key'] === $sourceKey)
        ));
        usort(
            $rows,
            static fn (array $a, array $b): int => [$b['seen_at'], $b['id']] <=> [$a['seen_at'], $a['id']]
        );
        return $rows;
    }
}
