<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules\Store;

use IndianOpinion\FeedRules\Exception\StoreUnavailableException;

/**
 * The recent-items table, unique on importer id plus URL hash.
 */
final class WpdbStorage implements RecentStorage
{
    public const TABLE = 'feed_rules_recent';

    private ?bool $available = null;

    public function __construct(private \wpdb $db)
    {
    }

    public function table(): string
    {
        return $this->db->prefix . self::TABLE;
    }

    /**
     * Check for the table once per request and create it when missing.
     */
    public function ensure(): void
    {
        if ($this->available === true) {
            return;
        }
        if ($this->available === false) {
            throw new StoreUnavailableException('The recent-items table is unavailable.');
        }
        if (!$this->exists()) {
            $this->db->query($this->createSql());
        }
        $this->available = $this->exists();
        if (!$this->available) {
            throw new StoreUnavailableException(
                'The recent-items table is missing and could not be created: ' . $this->db->last_error
            );
        }
    }

    public function createSql(): string
    {
        $charset = $this->db->get_charset_collate();
        return 'CREATE TABLE IF NOT EXISTS ' . $this->table() . ' (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            importer_id varchar(40) NOT NULL,
            url_hash char(40) NOT NULL,
            url text NOT NULL,
            source_key varchar(191) NOT NULL DEFAULT \'\',
            seen_at bigint(20) unsigned NOT NULL,
            sampled tinyint(1) NOT NULL DEFAULT 0,
            data longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY importer_url (importer_id, url_hash),
            KEY importer_seen (importer_id, seen_at)
        ) ' . $charset;
    }

    public function drop(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->table());
    }

    public function upsert(array $row): void
    {
        $this->ensure();
        $sql = $this->db->prepare(
            'INSERT INTO ' . $this->table() . ' (importer_id, url_hash, url, source_key, seen_at, sampled, data)'
            . ' VALUES (%s, %s, %s, %s, %d, %d, %s)'
            . ' ON DUPLICATE KEY UPDATE url = VALUES(url), source_key = VALUES(source_key),'
            . ' seen_at = VALUES(seen_at), sampled = VALUES(sampled), data = VALUES(data)',
            $row['importer_id'],
            $row['url_hash'],
            $row['url'],
            $row['source_key'],
            $row['seen_at'],
            $row['sampled'],
            $row['data']
        );
        if ($this->db->query($sql) === false) {
            throw new StoreUnavailableException('Could not record an item: ' . $this->db->last_error);
        }
    }

    public function count(?string $importerId = null): int
    {
        if ($importerId === null) {
            return (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->table());
        }
        return (int) $this->db->get_var(
            $this->db->prepare('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE importer_id = %s', $importerId)
        );
    }

    public function prune(string $importerId, int $keep): int
    {
        $count = $this->count($importerId);
        if ($count <= $keep) {
            return 0;
        }
        $edge = $this->db->get_row(
            $this->db->prepare(
                'SELECT id, seen_at FROM ' . $this->table() . ' WHERE importer_id = %s'
                . ' ORDER BY seen_at DESC, id DESC LIMIT 1 OFFSET %d',
                $importerId,
                $keep - 1
            ),
            ARRAY_A
        );
        if (!is_array($edge)) {
            return 0;
        }
        $deleted = $this->db->query(
            $this->db->prepare(
                'DELETE FROM ' . $this->table() . ' WHERE importer_id = %s'
                . ' AND (seen_at < %d OR (seen_at = %d AND id < %d))',
                $importerId,
                (int) $edge['seen_at'],
                (int) $edge['seen_at'],
                (int) $edge['id']
            )
        );
        return is_int($deleted) ? $deleted : 0;
    }

    public function latest(int $limit, ?string $importerId, ?string $sourceKey): array
    {
        $this->ensure();
        $where = [];
        $args = [];
        if ($importerId !== null) {
            $where[] = 'importer_id = %s';
            $args[] = $importerId;
        }
        if ($sourceKey !== null) {
            $where[] = 'source_key = %s';
            $args[] = $sourceKey;
        }
        $args[] = max(1, $limit);
        $sql = 'SELECT * FROM ' . $this->table()
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY seen_at DESC, id DESC LIMIT %d';
        $rows = $this->db->get_results($this->db->prepare($sql, ...$args), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function importers(): array
    {
        $ids = $this->db->get_col('SELECT DISTINCT importer_id FROM ' . $this->table());
        $out = [];
        foreach ($ids as $id) {
            if (is_scalar($id)) {
                $out[] = (string) $id;
            }
        }
        return $out;
    }

    private function exists(): bool
    {
        $table = $this->table();
        $found = $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($table)));
        return $found === $table;
    }
}
