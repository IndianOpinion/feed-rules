<?php

declare(strict_types=1);

namespace IndianOpinion\FeedRules;

use IndianOpinion\FeedRules\Exception\InvalidItemException;

/**
 * One imported entry, as an importer hands it over. Immutable.
 */
final class Item
{
    /**
     * @param list<string> $categories
     * @param list<string> $tags
     */
    private function __construct(
        private string $url,
        private string $title,
        private string $body,
        private string $sourceName,
        private string $sourceKey,
        private string $author,
        private array $categories,
        private array $tags,
        private ?int $published,
        private string $guid,
        private string $importerId,
        private bool $normalised,
        private bool $truncated,
    ) {
    }

    /**
     * Build an item from an importer's array.
     *
     * Keys: url (required), title, body, source_name, source_key, author,
     * categories, tags, published (Unix time or date string), guid, importer.
     *
     * @param array<mixed> $data
     * @throws InvalidItemException naming the field that is wrong.
     */
    public static function fromArray(array $data): self
    {
        $url = $data['url'] ?? null;
        if (!is_string($url) || trim($url) === '') {
            throw new InvalidItemException('url', 'The item has no url.');
        }
        $url = trim($url);
        if (!self::isAbsoluteHttpUrl($url)) {
            throw new InvalidItemException('url', 'The item url must be an absolute http or https URL.');
        }

        $published = $data['published'] ?? null;
        if (is_string($published) && $published !== '') {
            $published = ctype_digit($published) ? (int) $published : strtotime($published);
        }
        if (!is_int($published) || $published <= 0) {
            $published = null;
        }

        return new self(
            $url,
            self::str($data, 'title'),
            self::str($data, 'body'),
            self::str($data, 'source_name'),
            self::str($data, 'source_key'),
            self::str($data, 'author'),
            self::strList($data, 'categories'),
            self::strList($data, 'tags'),
            $published,
            self::str($data, 'guid'),
            self::str($data, 'importer'),
            !empty($data['normalised']),
            !empty($data['normalised']) && !empty($data['body_truncated']),
        );
    }

    public static function isAbsoluteHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return false;
        }
        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'url' => $this->url,
            'title' => $this->title,
            'body' => $this->body,
            'source_name' => $this->sourceName,
            'source_key' => $this->sourceKey,
            'author' => $this->author,
            'categories' => $this->categories,
            'tags' => $this->tags,
            'published' => $this->published,
            'guid' => $this->guid,
            'importer' => $this->importerId,
        ];
        if ($this->normalised) {
            $out['normalised'] = true;
            $out['body_truncated'] = $this->truncated;
        }
        return $out;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function sourceName(): string
    {
        return $this->sourceName;
    }

    public function sourceKey(): string
    {
        return $this->sourceKey;
    }

    public function author(): string
    {
        return $this->author;
    }

    /** @return list<string> */
    public function categories(): array
    {
        return $this->categories;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function published(): ?int
    {
        return $this->published;
    }

    public function guid(): string
    {
        return $this->guid;
    }

    public function importerId(): string
    {
        return $this->importerId;
    }

    /**
     * True when the text fields are already normalised (items read back from the store).
     */
    public function isNormalised(): bool
    {
        return $this->normalised;
    }

    /**
     * True when the stored body was already cut to the matching window.
     */
    public function wasTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * @param array<mixed> $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<mixed> $data
     * @return list<string>
     */
    private static function strList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (is_string($value)) {
            $value = $value === '' ? [] : explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $out[] = trim($entry);
            }
        }
        return $out;
    }
}
