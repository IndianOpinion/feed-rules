<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

/**
 * One row of the recent-items store.
 */
final class RecordedItem
{
    /**
     * @param array<string, mixed> $fields Normalised fields as stored.
     */
    public function __construct(
        public readonly string $importerId,
        public readonly string $url,
        public readonly string $sourceKey,
        public readonly int $seenAt,
        public readonly bool $sampled,
        public readonly array $fields,
        public readonly Decision $decision,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $data = json_decode(is_string($row['data'] ?? null) ? $row['data'] : '', true);
        $data = is_array($data) ? $data : [];
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $decision = Decision::fromArray(is_array($data['decision'] ?? null) ? $data['decision'] : []);
        $str = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        // The JSON keeps the full importer id and source key; the columns may be cut.
        $importer = is_string($fields['importer'] ?? null) ? $fields['importer'] : $str($row['importer_id'] ?? '');
        $sourceKey = is_string($fields['source_key'] ?? null) ? $fields['source_key'] : $str($row['source_key'] ?? '');
        unset($fields['importer'], $fields['source_key']);
        return new self(
            $importer,
            $str($row['url'] ?? ''),
            $sourceKey,
            is_numeric($row['seen_at'] ?? null) ? (int) $row['seen_at'] : 0,
            !empty($row['sampled']),
            $fields,
            $decision,
        );
    }

    /**
     * The item as the rules saw it, ready to evaluate again.
     */
    public function toItem(): Item
    {
        return Item::fromArray(array_merge($this->fields, [
            'url' => $this->url,
            'source_key' => $this->sourceKey,
            'importer' => $this->importerId,
            'normalised' => true,
            'body_truncated' => $this->decision->bodyTruncated(),
        ]));
    }

    public function title(): string
    {
        return is_string($this->fields['title'] ?? null) ? $this->fields['title'] : '';
    }

    public function sourceName(): string
    {
        return is_string($this->fields['source_name'] ?? null) ? $this->fields['source_name'] : '';
    }
}
